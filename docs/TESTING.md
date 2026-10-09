# Testing and Postman

## Canonical API check

From the repository root run:

```sh
composer verify
```

Success means all Laravel feature and unit tests pass, followed by a clean Pint check. CI runs this same command in `.github/workflows/api.yml`.

The Laravel tests cover:

- public health and per-user token protection
- login, login rate limiting, token expiry, and logout revocation
- admin and cashier role enforcement, including deactivated accounts
- product and stock validation
- checkout amount and payment persistence
- repeated idempotency keys
- atomic paid settlement and stock deduction
- failed and cancelled payments with no sale or deduction
- signed and repeated webhooks
- atomic cash sales, insufficient cash/stock, and idempotent retries
- sandbox-only PayMongo behavior

## Checkout v3 boundary coverage

`CheckoutRulesTest` exercises pure money, evidence-classification and transition locks. `CheckoutAuthorityTest` executes the v3 HTTP resources: durable identity and store recovery, cash/attempt idempotency, centavo validation, quote acceptance, active-account/role denial, pending/unknown and hold expiry, late fulfillment, contradiction preservation, evidence-classed/versioned reconciliation, signed webhook convergence and staging-admin-only simulation retrieval. It uses fake provider evidence/HTTP and does **not** close PayMongo GATE-01..06. The original missing-resource tests were observed red before implementation.

`CheckoutAuthorityConcurrencyTest` is skipped on SQLite. Against a disposable MySQL test database with the normal DB variables, run:

```sh
php artisan test --filter='(CheckoutAuthorityTest|CheckoutAuthorityConcurrencyTest|ReservationConcurrencyTest)'
```

These tests rebuild/truncate only the selected test database. The v3 concurrency tests issue real HTTP requests in independent processes under `REPEATABLE READ`, pause behind a parent-held product row lock, assert neither worker acquired the lock early, and release them to race. Different Cash keys cannot finalize twice; duplicate Cash keys return the same receipt; duplicate QR keys create one attempt/hold; duplicate paid refreshes create one sale/ledger/deduction; concurrent case updates accept one version and reject the stale writer. Provider I/O is asserted outside transactions. Unlike the existing settlement suite's exact `information_schema.innodb_trx` wait observer, this v3 barrier does not need a global MySQL PROCESS privilege.

See [CHECKOUT-CONTRACT.md](CHECKOUT-CONTRACT.md) for the exact v3 contract and fail-closed provider gates. The retained Newman collection exercises the v2 compatibility surface; new v3 proof is in Feature tests, not an assertion that the old collection covers these resources.

## MySQL concurrency regression

`ReservationConcurrencyTest` and `SettlementConcurrencyTest` require MySQL and are skipped by the default in-memory SQLite suite. Against a **disposable test database**, set `DB_CONNECTION=mysql`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, and `DB_PASSWORD`, then run:

```sh
php artisan test --filter='(ReservationConcurrencyTest|SettlementConcurrencyTest)'
```

These tests rebuild the selected database; never point them at staging or production. They use independent processes/connections under `REPEATABLE READ`. Settlement tests hold the shared product lock in one actor, confirm the competing transaction is waiting on that lock, then release the holder. Both actors must return HTTP 200 with one transaction attempt, proving lock-order prevention rather than deadlock recovery; bounded outer transaction retries remain containment. The tests check atomic sales, ledger rows, stock and webhook processing. Refresh, signed webhook and both stock-edit controller paths are covered; provider inspection is faked and must remain outside the retried transaction.

## Postman collection

The collection runs the HTTP contract in order: admin login, health, product creation/editing/stock update, products, name search, barcode lookup, unknown-barcode handling, inventory state/filter checks, checkout, payment read, signed practice webhook rejection of unsupported settlement, payment read again, sales, and transactions.

Required tools:

```sh
node --version
npx newman --version
```

Success: Node and Newman each print a version.

Prepare a local webhook verification value. This is not a PayMongo payment key. It stays in the ignored `.env` file:

```sh
WEBHOOK_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')"
sed -i "s/^PAYMONGO_WEBHOOK_SECRET=.*/PAYMONGO_WEBHOOK_SECRET=$WEBHOOK_SECRET/" .env
export PAYMONGO_WEBHOOK_SECRET="$WEBHOOK_SECRET"
php artisan config:clear
```

Success: `php artisan config:clear` says the configuration cache was cleared and `.env` has a new local-only webhook value.

Start the API in another terminal and run:

```sh
npx newman run postman/PorSca-API.postman_collection.json \
  -e postman/PorSca-API.postman_environment.json \
  --env-var webhook_secret="$PAYMONGO_WEBHOOK_SECRET"
```

Success: Newman reports 25 requests and all assertions passing. The first request signs in with `admin_email` and `admin_password` and captures the returned token into `{{token}}`, which the later requests use. The seeded database must be fresh before this run because the collection creates a managed product, completes a cash sale and retry, proves a QR Ph practice fixture cannot be marked paid by a forged status, and verifies invalid and duplicate webhook behavior. Real paid/failed settlement requires operator-owned PayMongo test credentials and a stable registered HTTPS webhook; use the deterministic faked-HTTP feature suite until those are available. Never scan/pay a test-mode QR; only the operator may use the provider simulator URL.

For staging, copy the environment file to a file outside git and override `base_url`, `admin_email`, `admin_password`, and `webhook_secret` from the staging machine's environment. Never commit the copy:

```sh
cp postman/PorSca-API.postman_environment.json /tmp/porsca-staging.postman_environment.json
npx newman run postman/PorSca-API.postman_collection.json \
  -e /tmp/porsca-staging.postman_environment.json \
  --env-var base_url=https://<stable-api-host>/api/v1 \
  --env-var admin_email="$ADMIN_EMAIL" \
  --env-var admin_password="$ADMIN_PASSWORD" \
  --env-var webhook_secret="$PAYMONGO_WEBHOOK_SECRET"
```

Success: every request and assertion passes against the same stable staging URL. Run this only with the cycle's approved data. Do not reset staging during the run.

## Evidence

For each formal QA cycle, retain:

- the `composer verify` output or CI run URL
- the Newman text or JSON report
- the collection and environment version used
- screenshots or response bodies for any payment and webhook check

Keep evidence with the cycle record. Do not retain API tokens, PayMongo secret keys, webhook secrets, or raw authorization headers.
