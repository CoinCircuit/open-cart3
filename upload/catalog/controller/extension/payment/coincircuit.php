<?php
/**
 * CoinCircuit payment - catalog controller.
 *
 * index()    renders the confirm button on the payment step.
 * confirm()  returns the order's live checkout session, creating one only
 *            when no reusable session exists.
 * callback() receives signed webhooks and drives the order status.
 */
class ControllerExtensionPaymentCoincircuit extends Controller {
	/** Replay window for webhook timestamps, in seconds. */
	const MAX_TIMESTAMP_AGE = 300;

	/**
	 * Order statuses treated as cancelled (default OpenCart ids: Canceled,
	 * Denied, Canceled Reversal, Voided). Webhooks never change the status
	 * of a cancelled order; they only leave a note for the merchant.
	 */
	private $cancelled_status_ids = array(7, 8, 9, 16);

	public function index() {
		$this->load->language('extension/payment/coincircuit');

		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['text_loading'] = $this->language->get('text_loading');
		$data['text_instruction'] = $this->language->get('text_instruction');

		$description = $this->config->get('payment_coincircuit_description');
		$data['description'] = $description ? $description : $this->language->get('text_description');

		return $this->load->view('extension/payment/coincircuit', $data);
	}

	/**
	 * Hand the browser a checkout session for the order.
	 *
	 * Reuses the newest live session (pending or partial, unexpired, same
	 * amount) instead of minting a new one per click: a half-paid session
	 * keeps collecting its funds, and double-clicks can no longer strand a
	 * payment on a superseded session. A new session is created only when
	 * none is reusable.
	 */
	public function confirm() {
		$this->load->language('extension/payment/coincircuit');

		$json = array();

		try {
			if (!isset($this->session->data['order_id'])) {
				$json['error'] = $this->language->get('error_order');
			} elseif (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] != 'coincircuit') {
				$json['error'] = $this->language->get('error_order');
			} elseif (!$this->config->get('payment_coincircuit_api_key')) {
				$json['error'] = $this->language->get('error_unavailable');
			} else {
				$this->load->model('checkout/order');

				$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

				if (!$order_info) {
					$json['error'] = $this->language->get('error_order');
				} elseif (!in_array(strtoupper($order_info['currency_code']), array('NGN', 'USD'), true)) {
					$json['error'] = $this->language->get('error_currency');
				} else {
					$this->load->model('extension/payment/coincircuit');

					$amount = $this->model_extension_payment_coincircuit->orderAmount($order_info);

					$existing = $this->model_extension_payment_coincircuit->getReusableSession($order_info['order_id'], $amount);

					if ($existing) {
						$json['checkout_url'] = $existing['checkout_url'];
						$json['success_url'] = $this->url->link('checkout/success', '', true);
						$json['redirect'] = $existing['checkout_url'];
					} else {
						$options = array(
							'title'       => $this->config->get('config_name'),
							'description' => sprintf($this->language->get('text_order_description'), $order_info['order_id']),
							'success_url' => $this->url->link('checkout/success', '', true),
							'cancel_url'  => $this->url->link('checkout/checkout', '', true),
							'webhook_url' => $this->url->link('extension/payment/coincircuit/callback', '', true)
						);

						$session = $this->model_extension_payment_coincircuit->createSession($order_info, $options);

						$this->model_extension_payment_coincircuit->saveSession($order_info['order_id'], $session);

						$comment = sprintf($this->language->get('text_awaiting'), $session['reference']);

						$this->model_checkout_order->addOrderHistory($order_info['order_id'], $this->resolveStatusId('payment_coincircuit_pending_status_id', 1), $comment, false);

						// checkout_url + success_url drive the embedded modal;
						// redirect is kept so an older cached template (or a
						// browser where the embed script failed) still works.
						$json['checkout_url'] = $session['url'];
						$json['success_url'] = $this->url->link('checkout/success', '', true);
						$json['redirect'] = $session['url'];
					}
				}
			}
		} catch (Exception $e) {
			$message = $e->getMessage();
			$json['error'] = $message ? $message : $this->language->get('error_unavailable');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Webhook endpoint.
	 *
	 * Verification order: signature first (4xx on tamper), then session
	 * resolution, then idempotency, then processing. Signed events that can
	 * never be processed (not session-scoped, unknown session, missing
	 * order) are acknowledged with 200 and skipped: erroring them would only
	 * feed the sender's retry queue with deliveries that can not succeed.
	 */
	public function callback() {
		$this->load->language('extension/payment/coincircuit');

		$raw_body = file_get_contents('php://input');
		$webhook_secret = $this->config->get('payment_coincircuit_webhook_secret');

		if (empty($webhook_secret)) {
			return $this->respond(500, array('error' => 'Webhook secret not configured.'));
		}

		$timestamp_raw = trim((string)$this->getRequestHeader('X-CoinCircuit-Timestamp'));
		$signature = (string)$this->getRequestHeader('X-CoinCircuit-Signature');
		$timestamp = (int)$timestamp_raw;

		if (!$timestamp || abs(time() - $timestamp) > self::MAX_TIMESTAMP_AGE) {
			return $this->respond(400, array('error' => 'Invalid or expired timestamp.'));
		}

		$expected = 'v1=' . hash_hmac('sha256', $timestamp_raw . '.' . $raw_body, $webhook_secret);

		if (!hash_equals($expected, $signature)) {
			return $this->respond(401, array('error' => 'Invalid signature.'));
		}

		$data = json_decode($raw_body, true);

		if (!is_array($data) || empty($data['event'])) {
			return $this->respond(400, array('error' => 'Invalid payload structure.'));
		}

		$event = (string)$data['event'];

		$this->load->model('extension/payment/coincircuit');
		$this->load->model('checkout/order');

		$reference = $this->extractReference($event, $data);

		if ($reference === '') {
			return $this->respond(200, array('success' => true, 'ignored' => 'unrelated_event'));
		}

		// Resolve by reference against ALL sessions this store created, not
		// just the latest per order: a payment on a superseded session must
		// still land on its order.
		$session_row = $this->model_extension_payment_coincircuit->getSessionByReference($reference);

		if (empty($session_row)) {
			return $this->respond(200, array('success' => true, 'ignored' => 'unknown_session'));
		}

		$order_id = (int)$session_row['order_id'];

		// Defence in depth: when the payload carries order metadata it must
		// agree with our own record for this session.
		$meta_order_id = isset($data['data']['session']['metadata']['orderId']) ? (int)$data['data']['session']['metadata']['orderId'] : 0;

		if ($meta_order_id && $meta_order_id !== $order_id) {
			return $this->respond(200, array('success' => true, 'ignored' => 'order_mismatch'));
		}

		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			return $this->respond(200, array('success' => true, 'ignored' => 'order_missing'));
		}

		// Idempotency: redeliveries repost the identical stored body, so a
		// hash of the raw body identifies a delivery exactly. A genuinely
		// new event (even of the same type) hashes differently.
		if (!$this->model_extension_payment_coincircuit->markWebhookProcessed($order_id, $event, sha1($raw_body))) {
			return $this->respond(200, array('success' => true));
		}

		$this->processEvent($event, $order_id, $reference, $data, $order_info);

		return $this->respond(200, array('success' => true));
	}

	/**
	 * The session reference an event belongs to, or '' when the event is not
	 * session-scoped. Refund events carry the reference on the refund object
	 * (entity distinguishes session refunds from invoice refunds).
	 */
	private function extractReference($event, $data) {
		if (strpos($event, 'refund.') === 0) {
			$refund = isset($data['data']['refund']) && is_array($data['data']['refund']) ? $data['data']['refund'] : array();
			$entity = isset($refund['entity']) ? (string)$refund['entity'] : '';

			if ($entity !== '' && $entity !== 'session') {
				return '';
			}

			return isset($refund['reference']) ? (string)$refund['reference'] : '';
		}

		return isset($data['data']['session']['reference']) ? (string)$data['data']['session']['reference'] : '';
	}

	/**
	 * Map a webhook event to an order-status change or note.
	 *
	 * State rules:
	 *  - a paid order (any session completed) is terminal: later partial /
	 *    expired / failed events never downgrade it,
	 *  - a cancelled order never changes status from a webhook; money
	 *    arriving on one leaves a loud note instead,
	 *  - a second completed session on a paid order is a double payment and
	 *    is surfaced as a note for the merchant to refund.
	 *
	 * Event semantics: payment.partial = session still open with less than
	 * required received (customer can pay the remainder); payment.underpaid
	 * = session closed short (terminal - the merchant reopens the payment
	 * from the CoinCircuit dashboard or refunds); payment.expired = session
	 * closed with nothing received.
	 */
	private function processEvent($event, $order_id, $reference, $data, $order_info) {
		$transaction = array();

		if (isset($data['data']['transaction']) && is_array($data['data']['transaction'])) {
			$transaction = $data['data']['transaction'];
		} elseif (isset($data['data']['session']['transaction']) && is_array($data['data']['session']['transaction'])) {
			$transaction = $data['data']['session']['transaction'];
		}

		$tx_hash = isset($transaction['txHash']) ? $transaction['txHash'] : '';
		$explorer = isset($transaction['explorerUrl']) ? $transaction['explorerUrl'] : '';
		$failure_reason = isset($data['data']['failureReason']) ? $data['data']['failureReason'] : '';

		$completed_events = array('payment.completed');
		$partial_events = array('payment.partial');
		$underpaid_events = array('payment.underpaid');
		$expired_events = array('payment.expired');
		$failed_events = array('payment.failed');
		$note_events = array('transaction.received', 'transaction.confirmed');

		// Guards are evaluated BEFORE this event mutates the session store,
		// so "paid" means paid by an earlier event.
		$order_paid = $this->model_extension_payment_coincircuit->orderHasCompletedSession($order_id);
		$order_cancelled = in_array((int)$order_info['order_status_id'], $this->cancelled_status_ids, true);

		if (in_array($event, $completed_events, true)) {
			$this->model_extension_payment_coincircuit->updateSessionStatus($reference, 'completed');

			if ($order_cancelled) {
				$this->addOrderNote($order_id, sprintf($this->language->get('text_paid_cancelled'), $reference));
			} elseif ($order_paid) {
				$this->addOrderNote($order_id, sprintf($this->language->get('text_paid_again'), $reference));
			} else {
				$comment = sprintf($this->language->get('text_completed'), $reference);

				if ($tx_hash) {
					$comment .= ' ' . sprintf($this->language->get('text_tx_hash'), $tx_hash);
				}

				if ($explorer) {
					$comment .= ' ' . sprintf($this->language->get('text_explorer'), $explorer);
				}

				$this->applyStatus($order_id, $this->resolveStatusId('payment_coincircuit_completed_status_id', 2), $comment, true);
			}
		} elseif (in_array($event, $partial_events, true)) {
			// Session is still open: keep it reusable so confirm() hands the
			// customer back to it to pay the remainder.
			$this->model_extension_payment_coincircuit->updateSessionStatus($reference, 'partial');

			if ($order_paid || $order_cancelled) {
				$this->addOrderNote($order_id, sprintf($this->language->get('text_partial_ignored'), $reference));
			} else {
				$this->applyStatus($order_id, $this->resolveStatusId('payment_coincircuit_partial_status_id', 1), $this->language->get('text_partial'), false);
			}
		} elseif (in_array($event, $underpaid_events, true)) {
			// Session closed short: terminal, so never reused. Funds arrived,
			// so the merchant is pointed at the dashboard to reopen or refund.
			$this->model_extension_payment_coincircuit->updateSessionStatus($reference, 'underpaid');

			if ($order_paid || $order_cancelled) {
				$this->addOrderNote($order_id, sprintf($this->language->get('text_partial_ignored'), $reference));
			} else {
				$this->applyStatus($order_id, $this->resolveStatusId('payment_coincircuit_partial_status_id', 1), sprintf($this->language->get('text_underpaid'), $reference), false);
			}
		} elseif (in_array($event, $expired_events, true)) {
			$this->model_extension_payment_coincircuit->updateSessionStatus($reference, 'expired');

			// A stale expiry (old session, or delivered late) must never
			// downgrade a paid or cancelled order; there is nothing for the
			// merchant to act on, so no note either.
			if (!$order_paid && !$order_cancelled) {
				$this->applyStatus($order_id, $this->resolveStatusId('payment_coincircuit_expired_status_id', 14), $this->language->get('text_expired'), false);
			}
		} elseif (in_array($event, $failed_events, true)) {
			$this->model_extension_payment_coincircuit->updateSessionStatus($reference, 'failed');

			if (!$order_paid && !$order_cancelled) {
				$comment = $this->language->get('text_failed');

				if ($failure_reason) {
					$comment .= ' ' . sprintf($this->language->get('text_reason'), $failure_reason);
				}

				$this->applyStatus($order_id, $this->resolveStatusId('payment_coincircuit_failed_status_id', 10), $comment, false);
			}
		} elseif (in_array($event, $note_events, true)) {
			if ($event === 'transaction.confirmed') {
				$comment = $explorer
					? sprintf($this->language->get('text_tx_confirmed_explorer'), $explorer)
					: sprintf($this->language->get('text_tx_confirmed'), $tx_hash);
			} else {
				$comment = sprintf($this->language->get('text_tx_received'), $tx_hash);
			}

			$this->addOrderNote($order_id, $comment);
		} elseif ($event === 'refund.success') {
			$refund = isset($data['data']['refund']) && is_array($data['data']['refund']) ? $data['data']['refund'] : array();

			$amount = isset($refund['fiatAmount']) && $refund['fiatAmount'] !== null ? $refund['fiatAmount'] : (isset($refund['amount']) ? $refund['amount'] : '');
			$currency = isset($refund['fiatCurrency']) && $refund['fiatCurrency'] !== null ? $refund['fiatCurrency'] : (isset($refund['asset']) ? strtoupper((string)$refund['asset']) : '');
			$refund_id = isset($refund['id']) ? $refund['id'] : $reference;

			$comment = sprintf($this->language->get('text_refunded'), $amount, $currency, $refund_id);

			if (!empty($refund['txHash'])) {
				$comment .= ' ' . sprintf($this->language->get('text_tx_hash'), $refund['txHash']);
			}

			$this->applyStatus($order_id, $this->resolveStatusId('payment_coincircuit_refunded_status_id', 11), $comment, false);
		}
		// session.updated, refund.created, refund.failed and any other
		// events are acknowledged and ignored.
	}

	/**
	 * Resolve a configured status id, falling back to a sane OpenCart default.
	 */
	private function resolveStatusId($config_key, $fallback) {
		$id = (int)$this->config->get($config_key);

		return $id > 0 ? $id : (int)$fallback;
	}

	/**
	 * Apply a status change with a comment.
	 */
	private function applyStatus($order_id, $status_id, $comment, $notify) {
		$this->model_checkout_order->addOrderHistory($order_id, (int)$status_id, $comment, (bool)$notify);
	}

	/**
	 * Add a comment without changing the order status (keeps the current status).
	 */
	private function addOrderNote($order_id, $comment) {
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if ($order_info && (int)$order_info['order_status_id'] > 0) {
			$this->model_checkout_order->addOrderHistory($order_id, (int)$order_info['order_status_id'], $comment, false);
		}
	}

	/**
	 * Read a request header (case-insensitive), from $_SERVER or getallheaders().
	 */
	private function getRequestHeader($name) {
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

		if (isset($this->request->server[$key])) {
			return $this->request->server[$key];
		}

		if (function_exists('getallheaders')) {
			foreach (getallheaders() as $header_name => $header_value) {
				if (strcasecmp($header_name, $name) === 0) {
					return $header_value;
				}
			}
		}

		return '';
	}

	/**
	 * Emit a JSON response with an explicit HTTP status code.
	 */
	private function respond($code, $body) {
		http_response_code((int)$code);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($body));
	}
}
