# CoinCircuit for OpenCart 3

Accept cryptocurrency payments in your OpenCart 3 store with CoinCircuit. At checkout the
shopper pays in a secure CoinCircuit window that opens right on your store page, and the
order status updates on its own from signed CoinCircuit webhooks. No redirect away from
your store, and no manual reconciliation.

## Requirements

- OpenCart 3.x (built against the OpenCart 3 extension APIs, tested on 3.0.3.9)
- PHP 7.2 or newer, with the `curl` and `mbstring` extensions
- Store currency set to **NGN** or **USD** (the currencies CoinCircuit settles)
- A public HTTPS store URL. CoinCircuit requires HTTPS for the success, cancel, and
  webhook URLs and does not deliver webhooks to private or localhost hosts, so a local
  store will not receive them. Use a public domain, or a tunnel such as ngrok, when
  testing.
- A CoinCircuit account with an API key and a webhook signing secret

## How it works

1. A **CoinCircuit** payment method appears at checkout, shown only when the store
   currency is NGN or USD.
2. When the shopper confirms the order, the extension creates a CoinCircuit payment
   session and opens the hosted checkout in a secure modal layered over your store. The
   shopper pays without leaving the page. If the embedded view cannot load, the extension
   falls back to a full-page redirect to the same checkout, so payment is always possible.
3. As the payment progresses, CoinCircuit sends signed webhooks. The extension verifies
   each signature and moves the order to the matching status automatically.

## Installation

### 1. Download the package

Download `coincircuit.ocmod.zip` from the latest release:

**https://github.com/CoinCircuit/open-cart3/releases/latest/download/coincircuit.ocmod.zip**

Building from source instead? Run `python build.py` in this repository to produce the same
zip from the `upload/` folder.

### 2. Upload it

In your store admin, go to **Extensions > Installer** and upload the zip. If your store
prompts you, refresh via **Extensions > Modifications**.

### 3. Install the extension

Go to **Extensions > Extensions** and choose **Payments** from the dropdown. Find
**CoinCircuit** and click the green **Install** button. This creates the extension's
tables, seeds sensible defaults, and grants your admin group access.

### 4. Configure it

Click the blue **Edit** button and fill in:

- **Status**: Enabled
- **Environment**: Sandbox while testing, Production when live
- **API Key**: from your CoinCircuit dashboard, under API settings
- **Webhook Secret**: from your CoinCircuit dashboard, under Webhook settings
- Optionally adjust the fee payer, order-status mappings, geo zone, minimum total, and
  sort order

Then copy the read-only **Webhook URL** shown on the settings page and add it as a webhook
endpoint in your CoinCircuit dashboard.

Prefer to install by hand? Copy everything inside the `upload/` folder into your OpenCart
root, merging the `admin/` and `catalog/` directories, then follow steps 3 and 4.

## Configuration reference

| Setting | Description |
| --- | --- |
| Status | Enable or disable the method. |
| Title | Method name shown to shoppers at checkout. |
| Description | Short text on the payment step before the checkout opens. |
| Environment | `Production` (`api.coincircuit.io`) or `Sandbox` (`sandbox-api.coincircuit.io`). |
| API Key | Sent as the `x-api-key` header on API calls. |
| Webhook Secret | Your CoinCircuit webhook signing secret, used to verify incoming webhooks. |
| Network Fee Paid By | Who covers the blockchain network fee: Customer or Merchant. |
| Awaiting Payment Status | Status set when the checkout opens for the shopper. |
| Payment Completed Status | Status set when full payment is received. |
| Partial / Underpaid Status | Status set when only part of the amount is received. |
| Payment Expired Status | Status set when the session expires unpaid. |
| Payment Failed Status | Status set when the payment fails. |
| Payment Refunded Status | Status set when a refund completes. |
| Geo Zone | Restrict the method to a geo zone. |
| Total | Minimum cart total before the method is shown. |
| Sort Order | Position among payment methods. |

## Webhook events and order status

The webhook handler verifies the `X-CoinCircuit-Signature` (an HMAC-SHA256 of
`timestamp.body`) against your Webhook Secret, checks that the timestamp is within five
minutes, matches the order through its stored session records, and de-duplicates repeat
deliveries by hashing the raw body. It handles the `payment.*`, `transaction.*`, and
`refund.*` event families:

| Event | Meaning | Effect |
| --- | --- | --- |
| `payment.completed` | Full payment received. | Set to Payment Completed status. |
| `payment.partial` | Less than the required amount received, but the session is still open and the customer can pay the remainder. | Set to Partial / Underpaid status. The session stays reusable, so re-confirming returns the customer to it. |
| `payment.underpaid` | The session closed with less than the required amount. | Set to Partial / Underpaid status, with a note to reopen the payment from the CoinCircuit dashboard or refund the customer. |
| `payment.expired` | The session closed with nothing received. | Set to Payment Expired status. |
| `payment.failed` | The payment failed. | Set to Payment Failed status, with the failure reason. |
| `refund.success` | A refund completed. | Set to Payment Refunded status, with the amount and refund ID. |
| `transaction.received`, `transaction.confirmed` | Activity on the blockchain. | Order note only, with the transaction hash and explorer link. |
| Anything else | Not applicable. | Acknowledged and ignored. |

Safeguards applied on every event:

- The store keeps a record of every session it created for an order, so a payment made on
  an older session, for example after a shopper re-confirmed, still updates the order.
- Confirming an order reuses the existing live session when one exists, so a half-paid
  session keeps collecting its funds instead of being replaced.
- A paid order is never downgraded by a late `expired`, `failed`, or `partial` event.
- A cancelled order never changes status from a webhook. Money arriving on one adds a
  clearly worded order note instead, so you can review and refund.
- A second completed session on an already paid order adds a double-payment note rather
  than passing silently.

## Rotating your webhook secret

If you rotate the webhook signing secret in your CoinCircuit dashboard, update the
**Webhook Secret** field in the extension settings immediately. Until the two match, every
webhook delivery is rejected with a signature error, and once CoinCircuit exhausts its
retries those deliveries are not sent again, so order statuses stop updating. Rotate in
this order: generate the new secret, paste it into the extension settings, save, then
confirm a test event delivers.

## Testing

1. Set Environment to Sandbox and enter your sandbox API key and webhook secret.
2. Make sure the store is reachable over public HTTPS. A tunnel is fine.
3. Place a test order in NGN or USD, complete payment in the CoinCircuit checkout, and
   confirm the order status updates and the order history shows the CoinCircuit notes.

## Theme note (Zeexo and custom themes)

The confirm button ships under the `default` theme, and OpenCart falls back to it for any
theme, so no template changes are normally needed. If your Zeexo build uses a heavily
customized or quick checkout, confirm that the CoinCircuit confirm button appears on the
payment step and that clicking it opens the CoinCircuit checkout.

## Uninstall

**Extensions > Extensions > Payments > CoinCircuit > Uninstall** drops the extension's
tables and removes the granted permissions. Saved settings remain in OpenCart's settings
table and are reused if you reinstall.

## File structure

```
build.py                    Rebuilds coincircuit.ocmod.zip from upload/
install.json                Package metadata (not used by OpenCart's installer)
upload/
  admin/controller/extension/payment/coincircuit.php
  admin/model/extension/payment/coincircuit.php
  admin/language/en-gb/extension/payment/coincircuit.php
  admin/view/template/extension/payment/coincircuit.twig
  catalog/controller/extension/payment/coincircuit.php
  catalog/model/extension/payment/coincircuit.php
  catalog/language/en-gb/extension/payment/coincircuit.php
  catalog/view/javascript/coincircuit/checkout.js
  catalog/view/theme/default/template/extension/payment/coincircuit.twig
```
