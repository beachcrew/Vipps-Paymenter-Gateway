# Vipps ePayment gateway for Paymenter

A production-ready gateway extension for Paymenter that integrates **Vipps MobilePay ePayment API**.

- Creates payments via `POST /epayment/v1/payments`, then redirects the buyer to Vipps/MobilePay.
- On return, fetches payment by reference and optionally **auto-captures**, then marks the invoice as paid.
- Supports **test** and **production** environments.

## Install

1. Copy the `extensions/Gateways/Vipps` folder into your Paymenter install:
   ```bash
   /var/www/paymenter/extensions/Gateways/Vipps
   ```
2. In the **Admin** → **Extensions**, enable “Vipps” and fill out settings.
3. Ensure your site has a public HTTPS URL for the return endpoint:
   - `GET https://YOURDOMAIN.tld/extensions/vipps/return`

## Settings

- **Use test environment**: toggles `apitest.vipps.no` vs `api.vipps.no`.
- **Client ID / Client Secret**: from Vipps MobilePay Developer portal.
- **Ocp-Apim-Subscription-Key**: subscription key for your sales unit.
- **Merchant-Serial-Number (MSN)**: your sales unit MSN.
- **Vipps-System-* headers**: optional but recommended.
- **Auto-capture**: capture full authorized amount automatically on return.

## How it works

1. `pay(Invoice $invoice, $total)`
   - Creates a unique `reference`.
   - Calls `POST /accesstoken/get` to obtain a **Bearer** access token.
   - Calls `POST /epayment/v1/payments` with headers:
     - `Authorization: Bearer <token>`
     - `Ocp-Apim-Subscription-Key: <subscription key>`
     - `Merchant-Serial-Number: <msn>`
     - `Vipps-System-*` headers
     - `Idempotency-Key: <uuid>`
   - Redirects the user to `redirectUrl` from Vipps.
2. Return: `/extensions/vipps/return?reference=...`
   - Calls `GET /epayment/v1/payments/{reference}`.
   - If authorized and **auto-capture** is enabled, calls `POST /epayment/v1/payments/{reference}/capture` with an `Idempotency-Key`.
   - When captured, marks invoice as paid via `ExtensionHelper::paymentPaid(...)`.

## File layout

```
extensions/Gateways/Vipps/
├─ Vipps.php
├─ routes.php
├─ Http/Controllers/VippsController.php
└─ resources/views/
   ├─ redirect.blade.php
   └─ result.blade.php
```
