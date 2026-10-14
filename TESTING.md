# Staging test plan — Waypoint WiPay for WooCommerce 1.1.0

Sections 1–4 cover the checkout flow that already passed sandbox testing in 1.0.4 and must not regress. **Sections 5–8 cover the new 1.1.0 features, which have not yet been exercised against WiPay sandbox.** Run the whole list before treating this release as usable.

**Store setup:** WordPress 6.4+, WooCommerce, HTTPS enabled, one physical product with stock, and the gateway set to **Sandbox**.

```bash
php tests/run-tests.php   # must print RESULT: PASSED first
```

## 1. Regression — checkout still works

| # | Step | Expected |
|---|---|---|
| 1.1 | Activate the upgraded plugin | No PHP errors or fatals in `debug.log` |
| 1.2 | Gateway settings screen opens | All previous fields present, plus a **Webhook endpoint** URL |
| 1.3 | Classic checkout, pay with `4242424242424242` | Order completes; confirmation page renders |
| 1.4 | Checkout Blocks, same card | Order completes |
| 1.5 | Declined card `4000000000000002` | Order fails, never marked paid |
| 1.6 | Replay a captured success return URL | Order is not completed a second time |
| 1.7 | TTD 5.00 minimum still enforced on TT/TTD | Blocked below, allowed above |
| 1.8 | Diagnostics off by default | No log lines written |

## 2. Webhook setup

| # | Step | Expected |
|---|---|---|
| 2.1 | Read the Webhook endpoint from gateway settings | A `https://…/?wc-api=waypoint_wipay_webhook` URL |
| 2.2 | `curl` it with no secret configured | `503 {"error":"webhook_secret_not_configured"}` — WiPay will retry, not drop |
| 2.3 | Define `WAYPOINT_WIPAY_WEBHOOK_SECRET`, re-curl unsigned | `401 {"error":"invalid_signature"}` |
| 2.4 | Register the endpoint on the WiPay dashboard Developer page | Accepted as public HTTPS |
| 2.5 | Fire any test event from the dashboard | `200 {"received":true}` and an order note where applicable |

## 3. Webhook authentication — must fail closed

Sign with `HMAC-SHA256( timestamp + "." + raw_body, secret )`, sent as `X-WiPay-Webhook-Signature: sha256=<hex>`, alongside `X-WiPay-Webhook-Timestamp` and `X-WiPay-Webhook-Id`.

| # | Step | Expected |
|---|---|---|
| 3.1 | Correct signature and timestamp | `200` |
| 3.2 | Modify one byte of the body | `401`, order untouched |
| 3.3 | Sign with the wrong secret | `401` |
| 3.4 | Reuse a captured signature with a **new** timestamp | `401` — the signature covers the timestamp |
| 3.5 | Timestamp 6 minutes old | `401` (five-minute window) |
| 3.6 | Timestamp 6 minutes in the future | `401` |
| 3.7 | `sha1=` prefix instead of `sha256=` | `401` |
| 3.8 | Same event ID sent twice | Second returns `{"received":true,"duplicate":true}` and changes nothing |

## 4. Webhook events — one at a time

| # | Event | Expected |
|---|---|---|
| 4.1 | `payment.success`, matching `order_id` + `transaction_id` | Order completes; transaction ID recorded |
| 4.2 | `payment.success` with a **different** `transaction_id` than the order recorded | Ignored, `200`, order untouched |
| 4.3 | `payment.success` for an order already paid | `200`, no second completion |
| 4.4 | `payment.failed` / `payment.error` on a pending order | Order → failed |
| 4.5 | `payment.failed` on a paid order | `200`, paid status untouched |
| 4.6 | `payment.chargeback_pending` on a paid order | Order → on-hold with a "do not fulfil" note |
| 4.7 | `payment.chargeback_released` after 4.6 | Previous status restored |
| 4.8 | `payment.chargeback_released` with no hold recorded | Note only; status unchanged |
| 4.9 | `payment.refunded` with no existing refund record | Full refund recorded, order → refunded |
| 4.10 | `payment.refunded` twice | Second is a no-op |
| 4.11 | `payment.refund_rejected` | Order note carries `meta.reason` |
| 4.12 | `payment.fraud_confirmed` | Order → cancelled with a review note |
| 4.13 | An unknown future event name | `200`, nothing changes, no retry loop |
| 4.14 | Event for an order paid by a different gateway | `200`, that order untouched |

Use 4.2 to confirm the transaction-ID match is enforced — it is the guard that stops a signed-but-wrong event from completing payment.

## 5. Refunds

| # | Step | Expected |
|---|---|---|
| 5.1 | No `WAYPOINT_WIPAY_WAPI_KEY` set, try a full refund | Clear error naming the constant; **no** HTTP request sent |
| 5.2 | Partial refund (e.g. 50.00 of 100.00) | Rejected: "full order refunds only" |
| 5.3 | Refund amount 0.02 off the total | Rejected — tolerance is one cent |
| 5.4 | Full refund with key set | Succeeds; order note says the request is **awaiting confirmation** |
| 5.5 | Inspect the request | `POST https://{host}/wapi/transactions/{txn}/refund` with `X-WAPI-Key`, no body |
| 5.6 | Sandbox key against a live transaction (or vice versa) | Rejected by WiPay; message surfaced |
| 5.7 | Wait for `payment.refunded` webhook | Refund confirmed; note added |
| 5.8 | Trigger `payment.refund_rejected` | Note carries the rejection reason |
| 5.9 | Refund an order with no transaction ID | "no WiPay transaction to refund" |

Do **not** treat 5.4 as money returned. Only 5.7 confirms it.

## 6. Reconciliation

| # | Step | Expected |
|---|---|---|
| 6.1 | Order Actions → **Reconcile with WiPay** (no WAPI key) | Note: unavailable, names the constant |
| 6.2 | Reconcile a WiPay order with key set | Order note with WiPay status, totals and a verdict |
| 6.3 | Reconcile a non-WiPay order | Skipped with a note |
| 6.4 | Reconcile an order with no transaction ID | Skipped with a note |
| 6.5 | WiPay reports `success` while the order is unpaid | Note flags it for manual review — **the order is not auto-completed** |
| 6.6 | WiPay total differs from the order total | Note flags the mismatch |
| 6.7 | WAPI unreachable | Note carries the transport error; no exception |

## 7. Security review

| # | Check | Expected |
|---|---|---|
| 7.1 | View page source while configuring | No WAPI key, webhook secret or API key anywhere in HTML/JS |
| 7.2 | Read `debug.log` with Diagnostics on | No request bodies, card data, emails, hashes or keys |
| 7.3 | `wp-config.php` in version control | Absent from the repo and `.gitignore`d |
| 7.4 | Webhook endpoint without the secret | `503`, never `200` |

## 8. Regression after the refactor

The platform/host map moved into `includes/class-waypoint-wipay-platforms.php` in 1.1.0.

| # | Step | Expected |
|---|---|---|
| 8.1 | Place an order on each of TT, JM, BB, GY, GD | Correct host in the outgoing request |
| 8.2 | Set an invalid platform in the DB directly | Checkout reports "not configured" — it must **not** fall back to another territory |
| 8.3 | Reconcile and refund on a non-TT platform | WAPI hits the same country host as the payment request |

## Sign-off

Release only when every row passes. Sections 5–7 are new in 1.1.0 and are the reason this build must stay in Sandbox until they are exercised end to end against WiPay sandbox.
