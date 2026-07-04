<?php
/**
 * CoinCircuit payment - catalog model.
 *
 * Availability at checkout, the CoinCircuit API client, the per-order session
 * store, and webhook idempotency.
 *
 * Session model: every CoinCircuit session created for an order is kept as
 * its own row (keyed by the session reference). Webhooks are accepted for
 * ANY session the store created - never just the latest - so a payment made
 * on an older session can not be lost. confirm() reuses the newest live
 * session instead of minting a new one on every click.
 */
class ModelExtensionPaymentCoincircuit extends Model {
	/** Fiat currencies CoinCircuit settles. */
	private $supported_currencies = array('NGN', 'USD');

	/** Seconds of remaining session lifetime required before reuse. */
	const REUSE_MIN_TTL = 60;

	/**
	 * Maximum age (seconds) at which an untouched pending session is still
	 * handed back on re-confirm. Older pending sessions are treated as
	 * abandoned and a fresh session is created instead. Partial sessions are
	 * exempt: funds are already committed to them.
	 */
	const REUSE_PENDING_MAX_AGE = 180;

	/**
	 * Offer the method at checkout when enabled, above the minimum total, inside
	 * the configured geo zone, and paying in a supported currency.
	 *
	 * @param array $address
	 * @param float $total
	 * @return array
	 */
	public function getMethod($address, $total) {
		$this->load->language('extension/payment/coincircuit');

		$currency = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');

		if (!in_array(strtoupper((string)$currency), $this->supported_currencies, true)) {
			return array();
		}

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_coincircuit_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if ($this->config->get('payment_coincircuit_total') > 0 && $this->config->get('payment_coincircuit_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('payment_coincircuit_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$title = $this->config->get('payment_coincircuit_title');

			if (!$title) {
				$title = $this->language->get('text_title');
			}

			$method_data = array(
				'code'       => 'coincircuit',
				'title'      => $title,
				'terms'      => '',
				'sort_order' => $this->config->get('payment_coincircuit_sort_order')
			);
		}

		return $method_data;
	}

	/**
	 * The amount string sent to the API for an order. Centralised so session
	 * reuse can compare a stored session's amount against the current order.
	 */
	public function orderAmount($order_info) {
		return number_format((float)$order_info['total'] * (float)$order_info['currency_value'], 2, '.', '');
	}

	/**
	 * Create a CoinCircuit hosted checkout session for an order.
	 *
	 * @param array $order_info OpenCart order (from model_checkout_order->getOrder)
	 * @param array $options    title, description, success_url, cancel_url, webhook_url
	 * @return array            ['reference', 'url', 'amount', 'expires_at' (UTC datetime or null)]
	 * @throws Exception        on connection or API error (clean message)
	 */
	public function createSession($order_info, $options) {
		$amount = $this->orderAmount($order_info);

		$payload = array(
			'title'       => isset($options['title']) ? $options['title'] : '',
			'description' => isset($options['description']) ? $options['description'] : '',
			'amount'      => $amount,
			'currency'    => strtoupper((string)$order_info['currency_code']),
			'metadata'    => array(
				'orderId' => (string)$order_info['order_id']
			),
			'feePaidBy'   => $this->config->get('payment_coincircuit_fee_paid_by') ? $this->config->get('payment_coincircuit_fee_paid_by') : 'customer'
		);

		$customer = $this->buildCustomer($order_info);

		if (!empty($customer)) {
			$payload['customer'] = $customer;
		}

		if (!empty($options['success_url'])) {
			$payload['successUrl'] = $options['success_url'];
		}

		if (!empty($options['cancel_url'])) {
			$payload['cancelUrl'] = $options['cancel_url'];
		}

		if (!empty($options['webhook_url'])) {
			$payload['webhookUrl'] = $options['webhook_url'];
		}

		$response = $this->apiRequest('POST', '/api/v1/payments', $payload);

		$data = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();

		if (empty($data['reference']) || empty($data['url'])) {
			throw new Exception('Invalid response from CoinCircuit.');
		}

		// Store the expiry as UTC so comparisons never depend on server tz.
		$expires_at = null;

		if (!empty($data['expiresAt'])) {
			$ts = strtotime((string)$data['expiresAt']);

			if ($ts !== false) {
				$expires_at = gmdate('Y-m-d H:i:s', $ts);
			}
		}

		return array(
			'reference'  => $data['reference'],
			'url'        => $data['url'],
			'amount'     => $amount,
			'expires_at' => $expires_at
		);
	}

	/**
	 * Build the customer object, omitting anything the API would reject.
	 * The customer is only sent when we have a non-empty email.
	 */
	private function buildCustomer($order_info) {
		$email = isset($order_info['email']) ? trim((string)$order_info['email']) : '';

		if ($email === '') {
			return array();
		}

		$customer = array('email' => $email);

		if (!empty($order_info['firstname'])) {
			$customer['firstName'] = mb_substr((string)$order_info['firstname'], 0, 50);
		}

		if (!empty($order_info['lastname'])) {
			$customer['lastName'] = mb_substr((string)$order_info['lastname'], 0, 50);
		}

		$phone = $this->normalizePhone(isset($order_info['telephone']) ? $order_info['telephone'] : '');

		if ($phone !== '') {
			$customer['phone'] = $phone;
		}

		return $customer;
	}

	/**
	 * Reduce a phone number to E.164 or drop it. The API rejects malformed
	 * numbers for the whole request, and phone is optional, so we omit rather
	 * than block checkout.
	 */
	private function normalizePhone($phone) {
		$phone = (string)$phone;
		$has_plus = (strlen($phone) > 0 && strpos($phone, '+') === 0);
		$digits = preg_replace('/[^0-9]/', '', $phone);
		$candidate = ($has_plus ? '+' : '') . $digits;

		return preg_match('/^\+?[1-9]\d{1,14}$/', $candidate) ? $candidate : '';
	}

	/**
	 * Base API URL for the configured environment.
	 */
	public function getBaseUrl() {
		return $this->config->get('payment_coincircuit_environment') === 'sandbox'
			? 'https://sandbox-api.coincircuit.io'
			: 'https://api.coincircuit.io';
	}

	/**
	 * Perform an authenticated CoinCircuit API request.
	 *
	 * @throws Exception on transport failure or a >=400 response (clean message).
	 */
	private function apiRequest($method, $path, $body) {
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $this->getBaseUrl() . $path);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'x-api-key: ' . (string)$this->config->get('payment_coincircuit_api_key')
		));

		if (!empty($body)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}

		$raw = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$transport_error = curl_error($ch);

		curl_close($ch);

		if ($raw === false) {
			throw new Exception('Unable to reach CoinCircuit. ' . $transport_error);
		}

		$decoded = json_decode($raw, true);

		if ($status >= 400) {
			$message = 'CoinCircuit request failed.';

			if (is_array($decoded) && isset($decoded['message'])) {
				$message = is_array($decoded['message']) ? implode(' ', $decoded['message']) : (string)$decoded['message'];
			}

			throw new Exception($message);
		}

		return is_array($decoded) ? $decoded : array();
	}

	// ─── Session store ───────────────────────────────────────────────────

	/**
	 * Persist a newly created session. One row per session, keyed by the
	 * globally unique reference; an order accumulates rows across retries.
	 */
	public function saveSession($order_id, $session) {
		$this->db->query("
			INSERT INTO `" . DB_PREFIX . "coincircuit_session`
			SET `reference` = '" . $this->db->escape($session['reference']) . "',
				`order_id` = '" . (int)$order_id . "',
				`checkout_url` = '" . $this->db->escape($session['url']) . "',
				`amount` = '" . $this->db->escape($session['amount']) . "',
				`status` = 'pending',
				`expires_at` = " . ($session['expires_at'] ? "'" . $this->db->escape($session['expires_at']) . "'" : "NULL") . ",
				`date_added` = NOW(),
				`date_modified` = NOW()
			ON DUPLICATE KEY UPDATE
				`checkout_url` = VALUES(`checkout_url`),
				`expires_at` = VALUES(`expires_at`),
				`date_modified` = NOW()
		");
	}

	/**
	 * The session confirm() should hand back instead of creating a new one:
	 * the order's newest live (pending or partial, unexpired) session whose
	 * amount still matches the order. Partial sessions win over pending ones
	 * because funds are already committed to them.
	 *
	 * Expiry is checked against UTC with a safety margin so a customer is
	 * never handed a session that is about to die under them. Amount is
	 * compared so an order whose total changed gets a fresh session. Pending
	 * sessions are only reused while young (REUSE_PENDING_MAX_AGE): an old
	 * untouched one was abandoned, and a fresh session with a full timer is
	 * a better hand-back than one mid-countdown.
	 */
	public function getReusableSession($order_id, $amount) {
		$query = $this->db->query("
			SELECT * FROM `" . DB_PREFIX . "coincircuit_session`
			WHERE `order_id` = '" . (int)$order_id . "'
				AND `status` IN ('pending', 'partial')
				AND `amount` = '" . $this->db->escape($amount) . "'
				AND (`expires_at` IS NULL OR `expires_at` > DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . (int)self::REUSE_MIN_TTL . " SECOND))
				AND (`status` = 'partial' OR `date_added` > DATE_SUB(NOW(), INTERVAL " . (int)self::REUSE_PENDING_MAX_AGE . " SECOND))
			ORDER BY (`status` = 'partial') DESC, `date_added` DESC
			LIMIT 1
		");

		return $query->num_rows ? $query->row : array();
	}

	/**
	 * Look a session up by its reference (webhook path). Resolves any session
	 * this store ever created, so payments on superseded sessions still land
	 * on their order.
	 */
	public function getSessionByReference($reference) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "coincircuit_session` WHERE `reference` = '" . $this->db->escape($reference) . "'");

		return $query->num_rows ? $query->row : array();
	}

	/**
	 * Record the latest known status of a session.
	 */
	public function updateSessionStatus($reference, $status) {
		$this->db->query("UPDATE `" . DB_PREFIX . "coincircuit_session` SET `status` = '" . $this->db->escape($status) . "', `date_modified` = NOW() WHERE `reference` = '" . $this->db->escape($reference) . "'");
	}

	/**
	 * True when any of the order's sessions completed - the order is paid.
	 * Used to stop stale or cross-session events from downgrading it.
	 */
	public function orderHasCompletedSession($order_id) {
		$query = $this->db->query("SELECT `reference` FROM `" . DB_PREFIX . "coincircuit_session` WHERE `order_id` = '" . (int)$order_id . "' AND `status` = 'completed' LIMIT 1");

		return (bool)$query->num_rows;
	}

	// ─── Webhook idempotency ─────────────────────────────────────────────

	/**
	 * Mark a webhook delivery as processed. Keyed on a hash of the raw body:
	 * redeliveries of the same event carry an identical stored body and are
	 * dropped, while a genuinely new event (e.g. a second partial payment
	 * with a new received amount) hashes differently and processes normally.
	 * INSERT IGNORE on the unique hash is race-safe under concurrent
	 * deliveries of the same body.
	 *
	 * @return bool true only the first time this delivery is seen.
	 */
	public function markWebhookProcessed($order_id, $event, $body_hash) {
		$this->db->query("
			INSERT IGNORE INTO `" . DB_PREFIX . "coincircuit_webhook_event`
			SET `body_hash` = '" . $this->db->escape($body_hash) . "',
				`order_id` = '" . (int)$order_id . "',
				`event` = '" . $this->db->escape($event) . "',
				`date_added` = NOW()
		");

		return $this->db->countAffected() > 0;
	}
}
