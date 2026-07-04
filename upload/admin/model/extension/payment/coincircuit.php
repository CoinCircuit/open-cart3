<?php
/**
 * CoinCircuit payment - admin model.
 *
 * Owns the extension's schema. install()/uninstall() are invoked by the
 * Extensions > Payments Install/Uninstall buttons (see the matching controller).
 */
class ModelExtensionPaymentCoincircuit extends Model {
	/**
	 * Create the extension tables.
	 *
	 * - coincircuit_session:       one row per checkout session created for
	 *                              an order (an order accumulates sessions
	 *                              across retries; reference is global-unique).
	 * - coincircuit_webhook_event: processed webhook deliveries, keyed by a
	 *                              hash of the raw body, for idempotency.
	 */
	public function install() {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "coincircuit_session` (
				`reference` VARCHAR(191) NOT NULL,
				`order_id` INT(11) NOT NULL,
				`checkout_url` TEXT,
				`amount` VARCHAR(32) NOT NULL DEFAULT '',
				`status` VARCHAR(32) NOT NULL DEFAULT 'pending',
				`expires_at` DATETIME DEFAULT NULL,
				`date_added` DATETIME NOT NULL,
				`date_modified` DATETIME NOT NULL,
				PRIMARY KEY (`reference`),
				KEY `order_id` (`order_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;
		");

		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "coincircuit_webhook_event` (
				`coincircuit_webhook_event_id` INT(11) NOT NULL AUTO_INCREMENT,
				`body_hash` CHAR(40) NOT NULL,
				`order_id` INT(11) NOT NULL,
				`event` VARCHAR(64) NOT NULL,
				`date_added` DATETIME NOT NULL,
				PRIMARY KEY (`coincircuit_webhook_event_id`),
				UNIQUE KEY `body_hash` (`body_hash`),
				KEY `order_id` (`order_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;
		");
	}

	/**
	 * Drop the extension tables (including tables from earlier plugin
	 * versions, so an upgrade-then-uninstall leaves nothing behind).
	 */
	public function uninstall() {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "coincircuit_session`;");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "coincircuit_webhook_event`;");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "coincircuit_order`;");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "coincircuit_order_event`;");
	}
}
