<?php
// Method
$_['text_title']                  = 'Cryptocurrency (CoinCircuit)';

// Payment step
$_['text_instruction']            = 'Pay with cryptocurrency';
$_['text_description']            = 'You will be redirected to CoinCircuit to complete your payment securely with crypto.';
$_['button_confirm']              = 'Confirm Order';
$_['text_loading']                = 'Loading...';

// Session
$_['text_order_title']            = 'Order #%s';
$_['text_order_description']      = 'Payment for order #%s';

// Order history comments
$_['text_awaiting']               = 'CoinCircuit: Awaiting crypto payment. Session reference: %s';
$_['text_completed']              = 'CoinCircuit: Payment completed. Session reference: %s';
$_['text_partial']                = 'CoinCircuit: A partial payment was received. The session is still open and awaiting the remaining amount.';
$_['text_expired']                = 'CoinCircuit: The payment session expired before any payment was received.';
$_['text_underpaid']              = 'CoinCircuit: The payment session closed with less than the required amount (underpaid). Open this payment in your CoinCircuit dashboard to reopen it for the remaining amount, or refund the customer. Session reference: %s';
$_['text_failed']                 = 'CoinCircuit: The payment failed.';
$_['text_reason']                 = 'Reason: %s';
$_['text_tx_hash']                = 'Transaction: %s';
$_['text_explorer']               = 'Explorer: %s';
$_['text_tx_received']            = 'CoinCircuit: Transaction detected on the blockchain. Hash: %s';
$_['text_tx_confirmed']           = 'CoinCircuit: Transaction confirmed. Hash: %s';
$_['text_tx_confirmed_explorer']  = 'CoinCircuit: Transaction confirmed. View on explorer: %s';
$_['text_refunded']               = 'CoinCircuit: Payment refunded (%s %s). Refund ID: %s.';
$_['text_paid_cancelled']         = 'CoinCircuit: Payment completed on session %s, but this order is cancelled. Order status left unchanged - review and refund if needed.';
$_['text_paid_again']             = 'CoinCircuit: A second payment session (%s) completed for this order, which was already paid. Order status left unchanged - review and refund the extra payment.';
$_['text_partial_ignored']        = 'CoinCircuit: A partial payment arrived on session %s after this order was already paid or cancelled. Order status left unchanged.';

// Errors
$_['error_order']                 = 'We could not find your order. Please try again.';
$_['error_currency']              = 'CoinCircuit only supports payments in NGN or USD.';
$_['error_unavailable']           = 'Crypto payment is temporarily unavailable. Please try again or choose another method.';
