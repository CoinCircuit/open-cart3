<?php
/**
 * CoinCircuit payment - admin controller.
 *
 * Renders and saves the settings form, and creates/drops the extension tables
 * when the method is installed/uninstalled from Extensions > Payments.
 */
class ControllerExtensionPaymentCoincircuit extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/payment/coincircuit');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_coincircuit', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['error_api_key'] = isset($this->error['api_key']) ? $this->error['api_key'] : '';
		$data['error_webhook_secret'] = isset($this->error['webhook_secret']) ? $this->error['webhook_secret'] : '';

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/coincircuit', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/payment/coincircuit', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

		// Settings: prefer posted values, fall back to stored config.
		$fields = array(
			'payment_coincircuit_status',
			'payment_coincircuit_title',
			'payment_coincircuit_description',
			'payment_coincircuit_environment',
			'payment_coincircuit_api_key',
			'payment_coincircuit_webhook_secret',
			'payment_coincircuit_fee_paid_by',
			'payment_coincircuit_pending_status_id',
			'payment_coincircuit_completed_status_id',
			'payment_coincircuit_partial_status_id',
			'payment_coincircuit_expired_status_id',
			'payment_coincircuit_failed_status_id',
			'payment_coincircuit_refunded_status_id',
			'payment_coincircuit_geo_zone_id',
			'payment_coincircuit_total',
			'payment_coincircuit_sort_order'
		);

		foreach ($fields as $field) {
			if (isset($this->request->post[$field])) {
				$data[$field] = $this->request->post[$field];
			} else {
				$data[$field] = $this->config->get($field);
			}
		}

		// Sensible defaults on first load.
		if ($data['payment_coincircuit_environment'] === null) {
			$data['payment_coincircuit_environment'] = 'production';
		}

		if ($data['payment_coincircuit_fee_paid_by'] === null) {
			$data['payment_coincircuit_fee_paid_by'] = 'customer';
		}

		if ($data['payment_coincircuit_title'] === null || $data['payment_coincircuit_title'] === '') {
			$data['payment_coincircuit_title'] = $this->language->get('text_default_title');
		}

		if ($data['payment_coincircuit_total'] === null) {
			$data['payment_coincircuit_total'] = '0';
		}

		// Order statuses and geo zones for the dropdowns.
		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		// Read-only webhook URL for the merchant to paste into the CoinCircuit dashboard.
		$catalog_url = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : (defined('HTTP_CATALOG') ? HTTP_CATALOG : '');
		$data['webhook_url'] = $catalog_url . 'index.php?route=extension/payment/coincircuit/callback';

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/coincircuit', $data));
	}

	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/coincircuit')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		// Only require credentials when the merchant is enabling the method.
		$enabling = !empty($this->request->post['payment_coincircuit_status']);

		if ($enabling) {
			if (empty($this->request->post['payment_coincircuit_api_key'])) {
				$this->error['api_key'] = $this->language->get('error_api_key');
			}

			if (empty($this->request->post['payment_coincircuit_webhook_secret'])) {
				$this->error['webhook_secret'] = $this->language->get('error_webhook_secret');
			}
		}

		if ($this->error && !isset($this->error['warning'])) {
			$this->error['warning'] = $this->language->get('error_warning');
		}

		return !$this->error;
	}

	/**
	 * Called when the extension is installed from Extensions > Payments.
	 * Creates the tables and seeds defaults for any keys not already set.
	 */
	public function install() {
		// No permission guard here: Extensions > Payments already validates
		// 'modify' before dispatching, and the route permission it grants in
		// this same request is not yet in the session's loaded permission
		// list, so checking it would silently skip table creation on the
		// first install.
		$this->load->model('extension/payment/coincircuit');
		$this->model_extension_payment_coincircuit->install();

		// Grant the installing admin access to the settings page immediately.
		$this->load->model('user/user_group');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/payment/coincircuit');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/payment/coincircuit');

		// Seed working defaults using standard OpenCart order status ids
		// (1 Pending, 2 Processing, 10 Failed, 11 Refunded, 14 Expired).
		// Existing values win, so a reinstall never overwrites a configured
		// store.
		$this->load->model('setting/setting');

		$existing = $this->model_setting_setting->getSetting('payment_coincircuit');

		$defaults = array(
			'payment_coincircuit_status'               => 0,
			'payment_coincircuit_title'                => 'Cryptocurrency (CoinCircuit)',
			'payment_coincircuit_environment'          => 'production',
			'payment_coincircuit_fee_paid_by'          => 'customer',
			'payment_coincircuit_pending_status_id'    => 1,
			'payment_coincircuit_completed_status_id'  => 2,
			'payment_coincircuit_partial_status_id'    => 1,
			'payment_coincircuit_expired_status_id'    => 14,
			'payment_coincircuit_failed_status_id'     => 10,
			'payment_coincircuit_refunded_status_id'   => 11,
			'payment_coincircuit_total'                => 0,
			'payment_coincircuit_sort_order'           => 1
		);

		$this->model_setting_setting->editSetting('payment_coincircuit', array_merge($defaults, $existing));
	}

	/**
	 * Called when the extension is uninstalled from Extensions > Payments.
	 */
	public function uninstall() {
		if ($this->user->hasPermission('modify', 'extension/payment/coincircuit')) {
			$this->load->model('extension/payment/coincircuit');
			$this->model_extension_payment_coincircuit->uninstall();

			$this->load->model('user/user_group');
			$this->model_user_user_group->removePermission($this->user->getGroupId(), 'access', 'extension/payment/coincircuit');
			$this->model_user_user_group->removePermission($this->user->getGroupId(), 'modify', 'extension/payment/coincircuit');
		}
	}
}
