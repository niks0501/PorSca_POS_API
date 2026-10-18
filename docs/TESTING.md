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

See [CHECKOUT-CONTRACT.md](CHECKOUT-CONTRACT.md) for the exact v3 contract and fail-closed provider gates. The retained Newman collection exercises both the v2 compatibility surface and the v3 durable local workflow; exhaustive v3 proof remains in Feature tests.

## MySQL concurrency regression

`ReservationConcurrencyTest` and `SettlementConcurrencyTest` require MySQL and are skipped by the default in-memory SQLite suite. Against a **disposable test database**, set `DB_CONNECTION=mysql`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, and `DB_PASSWORD`, then run:

```sh
php artisan test --filter='(ReservationConcurrencyTest|SettlementConcurrencyTest)'
```

These tests rebuild the selected database; never point them at staging or production. They use independent processes/connections under `REPEATABLE READ`. Settlement tests hold the shared product lock in one actor, confirm the competing transaction is waiting on that lock, then release the holder. Both actors must return HTTP 200 with one transaction attempt, proving lock-order prevention rather than deadlock recovery; bounded outer transaction retries remain containment. The tests check atomic sales, ledger rows, stock and webhook processing. Refresh, signed webhook and both stock-edit controller paths are covered; provider inspection is faked and must remain outside the retried transaction.

## Postman collection

The collection runs the HTTP contract in order: health environment gate, admin login (also signing in the cashier once for the role checks), missing-token 401 on a catalog read, managed-product creation/editing/stock update, cash sale with idempotent retry and pinned total/cash/change echo, cashier-token 403 on product creation, cash-below-total 422 with a follow-up inventory read proving the rejected sale left stock at 7, unknown-product 422, catalog products with the seeded prices pinned, name search, barcode lookup, unknown-barcode handling, inventory state/filter checks, QR practice checkout and payment read, signed practice webhook rejection of unsupported settlement, invalid-signature and duplicate-event webhook checks, failed-payment practice checkout and webhook, payment read again, sales history with exactly one completed cash sale, and transactions. It then runs the Checkout V3 durable local workflow folder: contract/session discovery, cash and QR checkout creation, cash tender acceptance and replay, attempt and reservation reads, local QR attempt refresh, and checkout abandonment, asserting the v3 contract version, quote math, pending attempt, held reservation, and the completed cash sale's total, cash received, and change.

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

Reseed the local database so the run starts from the documented baseline. The collection asserts absolute seed state (managed-product stock 8 → 11 → 9, cash totals, seeded catalog), so a stale database fails by design:

```sh
php artisan migrate:fresh --seed
```

Success: every migration runs and `Database\Seeders\DatabaseSeeder ... DONE` is printed. This is the required precondition for every local Newman run; never run Newman twice against the same database without reseeding first. (Staging uses the symmetric `php artisan qa:reset --force` on the staging machine, and only with the cycle's approved data.)

Start the API in another terminal. Export the admin password from your local `.env`, then run (the collection refuses placeholder secrets):

```sh
export ADMIN_PASSWORD="$(sed -n 's/^ADMIN_PASSWORD=//p' .env)"
npx newman run postman/PorSca-API.postman_collection.json \
  -e postman/PorSca-API.postman_environment.json \
  --env-var admin_password="$ADMIN_PASSWORD" \
  --env-var cashier_email=qa-cashier@porsca.test \
  --env-var cashier_password="$QA_CASHIER_PASSWORD" \
  --env-var webhook_secret="$PAYMONGO_WEBHOOK_SECRET"
```

Success: all 44 collection requests and every assertion pass; Newman's summary counts 46 HTTP requests because the admin login and the insufficient-cash stock check each add a nested `pm.sendRequest`. The login request (second after the Health gate) signs in with `admin_email` and `admin_password` and captures the returned token into `{{token}}`; the same request also signs in with `cashier_email` and `cashier_password` and captures the cashier token into `{{cashier_token}}`. No cashier is seeded, so create the standing cashier with the [QA accounts recipe](QA-CYCLE.md#qa-accounts) and export `QA_CASHIER_PASSWORD` (plus `ADMIN_PASSWORD` from your local `.env`) before the run. The seeded database must be fresh before this run because the collection creates a managed product, completes a cash sale and retry, proves a QR Ph practice fixture cannot be marked paid by a forged status, verifies invalid and duplicate webhook behavior, and pins the seeded prices, stock, and cash totals. Real paid/failed settlement requires operator-owned PayMongo test credentials and a stable registered HTTPS webhook; use the deterministic faked-HTTP feature suite until those are available. Never scan/pay a test-mode QR; only the operator may use the provider simulator URL.

Two collection safeguards protect the run:

- A collection-level pre-request guard aborts before any stateful request fires when `admin_password`, `cashier_password`, or `webhook_secret` still hold a placeholder value, or when no login token was captured.
- The Health request runs first as an environment gate: it asserts `environment === {{expected_environment}}` (`local`), `database === 'ok'`, and `paymongo_mode === {{expected_paymongo_mode}}` (`sandbox`), and stops the whole run on mismatch so a dev-vs-staging mixup ends at Health with zero stateful side effects.

> **Known limitation:** the Health gate proves environment, database reachability, and sandbox mode only. It does not prove database identity: a local API pointed at a foreign database still passes the gate, and the run then proceeds to stateful writes against that data. Verify database identity manually (for example `DB_DATABASE` and `DB_HOST` in the API's `.env`) before reseeding or any stateful run.

The managed-product barcode is generated per run (timestamp-suffixed, captured into `{{managed_product_barcode}}` with the edit-step value in `{{managed_product_barcode_edit}}`), so back-to-back runs with a reseed between them never collide on the barcode unique rule.

For staging, copy the environment file to a file outside git and override `base_url`, `admin_email`, `admin_password`, `cashier_email`, `cashier_password`, and `webhook_secret` from the staging machine's environment. Never commit the copy. Create the standing cashier on the staging host with the [QA accounts recipe](QA-CYCLE.md#qa-accounts) and export `QA_CASHIER_PASSWORD` before the run:

```sh
cp postman/PorSca-API.postman_environment.json /tmp/porsca-staging.postman_environment.json
npx newman run postman/PorSca-API.postman_collection.json \
  -e /tmp/porsca-staging.postman_environment.json \
  --env-var base_url=https://<stable-api-host>/api/v1 \
  --env-var expected_environment=staging \
  --env-var admin_email="$ADMIN_EMAIL" \
  --env-var admin_password="$ADMIN_PASSWORD" \
  --env-var cashier_email=qa-cashier@porsca.test \
  --env-var cashier_password="$QA_CASHIER_PASSWORD" \
  --env-var webhook_secret="$PAYMONGO_WEBHOOK_SECRET"
```

(Override `--env-var expected_paymongo_mode=...` as well if the target host ever reports a non-sandbox mode.)

Success: every request and assertion passes against the same stable staging URL. Run Health first and compare its `environment`/`commit` against the cycle record's approved URL/SHA before letting the rest of the run proceed; the collection gate stops the run at Health only on environment, PayMongo mode, or database unreachability, and it does not verify database identity, so the commit/SHA comparison stays manual. Run this only with the cycle's approved data. Do not reset staging during the run.

## Evidence

For each formal QA cycle, retain:

- the `composer verify` output or CI run URL
- the Newman text or JSON report
- the collection and environment version used
- screenshots or response bodies for any payment and webhook check

Keep evidence with the cycle record. Do not retain API tokens, PayMongo secret keys, webhook secrets, or raw authorization headers.
