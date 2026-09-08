# API architecture and mobile contract

## Authority

Laravel is the only backend authority for PorSca products, inventory, sales, transactions, payments, and PayMongo webhook events. The old Express payment scaffold is not an API source of truth and must be retired when the Laravel mobile integration is ready.

The API stores money as an integer in the smallest currency unit. For PHP, `1250` means PHP 12.50. The seeded prices and all payment amounts use `PHP`.

## Base URL and authentication

The mobile app receives a base URL that ends at the version prefix:

```text
https://<api-host>/api/v1
```

Every route needs this header except health and the PayMongo webhook:

```http
Authorization: Bearer <API_TOKEN>
Accept: application/json
```

`API_TOKEN` is a server environment value. Do not commit it or put it in the mobile bundle. This first API slice uses one shared token. User accounts and store roles are out of scope.

## Endpoints

### Health

`GET /health` is public.

Success:

```json
{
  "data": {
    "service": "porsca-api",
    "version": "v1",
    "environment": "staging",
    "database": "ok",
    "paymongo_mode": "sandbox",
    "status": "ok"
  }
}
```

### Products

`GET /products` returns active products by default. Use `?search=coffee` (or `?name=coffee`) for a case-insensitive name search, `?barcode=...` for an exact barcode filter, `?active=false` to include inactive products, and `?per_page=50` for paging.

`GET /products/{id}` returns one active product. `GET /products/barcode/{barcode}` performs an exact lookup of one active product. An unknown or inactive barcode returns the standard `404 not_found` error.

Example item:

```json
{
  "id": 1,
  "sku": "RICE-001",
  "barcode": "4800000000010",
  "name": "Sinandomeng Rice 5kg",
  "description": null,
  "price": 32000,
  "currency": "PHP",
  "active": true,
  "stock": {
    "quantity": 20,
    "reorder_level": 5,
    "status": "in_stock",
    "low_stock": false,
    "out_of_stock": false
  }
}
```

The list response is `{ "data": { "items": [], "pagination": {} } }`. Product responses expose only catalog and stock fields; API tokens, payment credentials, and other backend-only secrets are never serialized.

### Inventory

`GET /inventory` returns stock for every product. `GET /inventory/{product_id}` returns one stock row. Use `?low_stock=true` to filter rows at or below their reorder level. Each row uses the same `quantity`, `reorder_level`, `status`, `low_stock`, and `out_of_stock` fields as the product `stock` object. `status` is one of `in_stock`, `low_stock`, or `out_of_stock`; an out-of-stock row has `quantity: 0` and `out_of_stock: true`.

Stock changes only as part of a successful payment settlement. A client cannot directly deduct stock.

### Checkout and payments

`POST /sales/checkout` creates a pending QR Ph checkout. `POST /payments` is an alias with the same request and response.

Send a unique key in the header. A JSON `idempotency_key` is accepted as a fallback:

```http
Idempotency-Key: mobile-cart-2026-09-08T12:00:00Z
Content-Type: application/json
```

```json
{
  "items": [
    {"product_id": 1, "quantity": 2},
    {"product_id": 2, "quantity": 1}
  ]
}
```

A new checkout returns `201`. Repeating the same key and same cart returns the original payment with `200`. Reusing a key for a different cart returns `409`.

The response contains:

```json
{
  "data": {
    "id": 1,
    "idempotency_key": "mobile-cart-2026-09-08T12:00:00Z",
    "provider": "paymongo",
    "provider_payment_id": "sandbox_...",
    "amount": 82500,
    "currency": "PHP",
    "payment_method": "qrph",
    "status": "pending",
    "qr_payload": "https://sandbox.paymongo.test/qr/...",
    "checkout_url": null,
    "sale_id": null,
    "failure_reason": null,
    "paid_at": null,
    "items": []
  }
}
```

With no secret key, `provider_payment_id` starts with `sandbox_` and the QR value is a local practice value. It does not charge money. With a key, the server calls the PayMongo sandbox and still asks only for `qrph`.

`GET /payments/{id}` returns the stored payment. `GET /payments/{id}/status` is an alias for that read. `POST /payments/{id}/refresh` (or `POST /payments/{id}/status`) asks the PayMongo sandbox for the latest status. The normalized statuses are `pending`, `paid`, `failed`, and `cancelled`.

A `paid` result creates one sale and decrements every item in one database transaction. A duplicate checkout, status refresh, or webhook cannot create another sale or deduction. `failed` and `cancelled` results create no sale and deduct nothing.

### Sales

`GET /sales` lists completed sales. `GET /sales/{id}` returns a sale and its item snapshots. The list response uses the same `items` and `pagination` shape as products.

### Transactions

`GET /transactions` lists the payment ledger. Optional filters are `payment_id` and `sale_id`. `GET /transactions/{id}` returns one ledger row. Transaction types include `payment_created`, `payment_succeeded`, `payment_failed`, and `payment_cancelled`.

### PayMongo webhook

`POST /webhooks/paymongo` is public to the network but requires the provider signature. This launch slice uses the verification seam in `App\Services\Payments\PayMongoWebhookVerifier`: send an HMAC SHA-256 hex digest of the raw JSON body in `X-PayMongo-Signature`, using server-only `PAYMONGO_WEBHOOK_SECRET`.

Example fixture body:

```json
{
  "event_id": "evt-001",
  "type": "payment.paid",
  "payment_id": "sandbox_...",
  "status": "paid"
}
```

PayMongo's envelope is also accepted. Its event ID, type, resource ID, and status are extracted from the `data.attributes.resource` fields. The event ID is unique. Repeating a signed event returns `200` with `duplicate: true` and does not settle again. Replace the verifier implementation when the final PayMongo signed-header format is selected; keep the same service seam and tests.

## Error shape

All API errors use this shape:

```json
{
  "error": {
    "code": "validation_error",
    "message": "The request could not be validated.",
    "details": {
      "items.0.quantity": ["The items.0.quantity field must be at least 1."]
    }
  }
}
```

Common status codes are `401` for missing or bad token/signature, `404` for an unknown resource, `409` for idempotency or stock conflicts, `422` for bad input, and `503` for an unavailable payment provider.

## Code seams

- `App\Services\CheckoutService` validates the cart snapshot and creates one pending payment.
- `App\Services\PaymentSettlementService` owns the atomic sale and stock commit.
- `App\Services\Payments\PayMongoSandboxGateway` is the sandbox-only provider adapter.
- `App\Services\Payments\PayMongoWebhookVerifier` is the replaceable verification seam.
- `App\Services\WebhookService` records event IDs and makes webhook handling idempotent.
