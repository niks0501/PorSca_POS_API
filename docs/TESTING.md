# Testing and Postman

## Canonical API check

From the repository root run:

```sh
composer verify
```

Success means all Laravel feature and unit tests pass, followed by a clean Pint check. CI runs this same command in `.github/workflows/api.yml`; the separate `.github/workflows/newman.yml` workflow adds the HTTP-level collection run and sanitized report artifacts. Because `phpunit.xml` pins in-memory SQLite, 22 MySQL-only tests skip in this check; `.github/workflows/mysql.yml` runs them against a disposable MySQL service (see [MySQL CI job](#mysql-ci-job)).

The Laravel tests cover:

- public health and per-user token protection
- login, login rate limiting, token expiry, and logout revocation
- admin and cashier role enforcement, including deactivated accounts
- product and stock validation
- checkout amount and payment persistence
- repeated idempotency keys
- atomic paid settlement and stock deduction
- failed and cancelled payments with no sale or deduction
- signed and repeated webhooks, including the same `payment.paid` event progressing from verified pending (or v3 uncorrelated evidence) to paid with one sale, ledger entry and stock deduction; terminal duplicates skip provider I/O
- atomic cash sales, insufficient cash/stock, and idempotent retries
- sandbox-only PayMongo behavior

## Checkout v3 boundary coverage

`CheckoutRulesTest` exercises pure money, evidence-classification and transition locks. `CheckoutAuthorityTest` executes the v3 HTTP resources: durable identity and store recovery, cash/attempt idempotency, centavo validation, quote acceptance, active-account/role denial, pending/unknown and hold expiry, late fulfillment, contradiction preservation, evidence-classed/versioned reconciliation, signed webhook convergence and staging-admin-only simulation retrieval. It uses fake provider evidence/HTTP and does **not** close PayMongo GATE-01..06. The original missing-resource tests were observed red before implementation.

`CheckoutAuthorityConcurrencyTest` is skipped on SQLite. Against a disposable MySQL test database with the normal DB variables, run:

```sh
php artisan test --filter='(CheckoutAuthorityTest|CheckoutAuthorityConcurrencyTest|ReservationConcurrencyTest)'
```

These tests rebuild/truncate only the selected test database. The v3 concurrency tests issue real HTTP requests in independent processes under `REPEATABLE READ`, pause behind a parent-held product row lock, assert neither worker acquired the lock early, and release them to race. Different Cash keys cannot finalize twice; duplicate Cash keys return the same receipt; duplicate QR keys create one attempt/hold; duplicate paid refreshes create one sale/ledger/deduction; concurrent case updates accept one version and reject the stale writer. Provider I/O is asserted outside transactions. This v3 barrier reads the workers' own process output and does not need a global MySQL PROCESS privilege; the settlement suite observes its contender through Performance Schema instead (see the [lock-wait barrier caveat](#lock-wait-barrier-caveat)).

See [CHECKOUT-CONTRACT.md](CHECKOUT-CONTRACT.md) for the exact v3 contract and fail-closed provider gates. The retained Newman collection exercises both the v2 compatibility surface and the v3 durable local workflow; exhaustive v3 proof remains in Feature tests.

## MySQL suites

Five suites assert MySQL-only behavior and are skipped by the default in-memory SQLite suite, so neither `composer verify` nor the `verify` job in `.github/workflows/api.yml` (which installs only `pdo_sqlite`) exercises them: `IdempotencyKeyMigrationTest` (2 tests), `LegacyCheckoutKeyConcurrencyTest` (4 data sets), `ReservationConcurrencyTest` (2), `SettlementConcurrencyTest` (9 data sets), and `CheckoutAuthorityConcurrencyTest` (5). `composer verify` reports them as 22 skipped tests. Run them against MySQL whenever a change touches locking, settlement, reservation, or idempotency-key uniqueness.

Both runs need a **disposable test database**: the suites rebuild or truncate whichever database the connection selects, so never point them at staging or production. The MySQL server must be reachable from a PHP build with the `pdo_mysql` extension, and the test account needs privileges for the lock-wait barriers it exercises: `SettlementConcurrencyTest` reads `performance_schema.data_locks`, `data_lock_waits`, and `threads`, so it needs `SELECT` on `performance_schema`, while `LegacyCheckoutKeyConcurrencyTest` still polls `information_schema.innodb_trx` and needs `PROCESS` on `*.*` (see the [lock-wait barrier caveat](#lock-wait-barrier-caveat)). Set `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`; `phpunit.xml` supplies `sqlite` and `:memory:` as defaults without `force`, so exported values take precedence.

### Idempotency-key and migration suites

```sh
php artisan test --filter='(IdempotencyKeyMigrationTest|SaleKeyNamespaceMigrationTest|IdempotencyKeyTest)'
```

Success: 9 tests pass (89 assertions on MySQL 8.4.11). `IdempotencyKeyMigrationTest` exercises `2026_10_17_000000_harden_idempotency_keys.php`: the `utf8mb4_bin` collation on `checkouts.creation_key`, `checkout_operations.key`, `sales.idempotency_key`, and `payments.idempotency_key`, the `checkout_keys` primary-key table, and the all-scopes rollback guard. `SaleKeyNamespaceMigrationTest` exercises `2026_10_08_120000_separate_sale_key_namespaces.php`. Only the MySQL run proves case-sensitive uniqueness, so the SQLite runs of `SaleKeyNamespaceMigrationTest` and `IdempotencyKeyTest` are not a substitute for it.

### Concurrency suites

```sh
php artisan test --filter='(CheckoutAuthorityConcurrencyTest|LegacyCheckoutKeyConcurrencyTest|ReservationConcurrencyTest|SettlementConcurrencyTest)'
```

Expected: 20 tests (5 + 4 + 2 + 9). `CheckoutAuthorityConcurrencyTest` and `ReservationConcurrencyTest` pass on every MySQL run. `SettlementConcurrencyTest` observes its contender's `products` primary-key wait through Performance Schema and passes without any `PROCESS` privilege. `LegacyCheckoutKeyConcurrencyTest` still polls `information_schema.innodb_trx` and can fail before it reaches a behavior assertion, under the caveat below. The suites use independent processes/connections under `REPEATABLE READ`. Settlement tests hold the shared product lock in one actor, confirm the competing transaction is waiting on that lock, then release the holder. Both actors must return HTTP 200 with one transaction attempt, proving lock-order prevention rather than deadlock recovery; bounded outer transaction retries remain containment. The tests check atomic sales, ledger rows, stock and webhook processing. Refresh, signed webhook and both stock-edit controller paths are covered; provider inspection is faked and must remain outside the retried transaction.

### Lock-wait barrier caveat

`LegacyCheckoutKeyConcurrencyTest` confirms the race by polling `information_schema.innodb_trx` every 20 ms for 5 s until the contender's `trx_state` is `LOCK WAIT`. On MySQL 8.0.46 and 8.4.11 that read can return no row for a genuinely blocked Laravel worker, so the barrier times out and the suite fails all 4 data sets before it reaches a behavior assertion: `Failed asserting that null is identical to 'LOCK WAIT'.` This is a barrier-observability limitation, not evidence of a locking regression, and `composer verify` cannot catch it because the suite is skipped there.

`SettlementConcurrencyTest` had the same barrier and now observes the waiting `products` primary-key lock through `performance_schema.data_lock_waits` (green on MySQL 8.0.43 in 9/9 data sets without `PROCESS`); the equivalent replacement for `LegacyCheckoutKeyConcurrencyTest` is being prepared separately, so the `.github/workflows/mysql.yml` advisory tier below still covers both classes.

The wait itself is real and instrumented while the contender is blocked: `information_schema.processlist` shows the contender executing the `checkout_keys` upsert, `performance_schema.data_locks` and `data_lock_waits` show its `WAITING` X record lock, `events_transactions_current` shows the active `REPEATABLE READ` transaction, and `SHOW ENGINE INNODB STATUS` reports `LOCK WAIT` with `TRX HAS BEEN WAITING`. The same worker transaction is absent from `information_schema.innodb_trx` for the whole poll; the exact trigger is not isolated, and it does not reproduce with plain `mysql` clients or minimal PDO sessions running the same upsert. Read those server surfaces while the contender is blocked before treating a barrier failure as a product defect — those diagnostic reads need `SELECT` on the `performance_schema` tables, which the canonical runs do not. Replacing only the observation for `performance_schema.data_locks` `LOCK_STATUS='WAITING'` makes every settlement data set pass with no behavior-assertion changes, and the settlement suite now does exactly that; report a remaining legacy barrier miss as a barrier failure rather than loosening the assertions or the lock-wait timeout. `.github/workflows/mysql.yml` therefore still runs both classes as an advisory, non-blocking step — see [MySQL CI job](#mysql-ci-job).

### MySQL CI job

`.github/workflows/mysql.yml` runs the SQLite-skipped suites (22 tests across the five classes above) on pull requests and pushes to `main`, `staging`, and `development`, plus manual dispatch. It starts an ephemeral `mysql:8.4` service container (root with an empty password on `127.0.0.1:3306`, database `porsca_test`, destroyed with the runner), exports the `DB_*` variables over the SQLite defaults in `phpunit.xml`, confirms the connection, and then runs three PHPUnit steps:

- required — `IdempotencyKeyMigrationTest`: the `harden_idempotency_keys` migration's `utf8mb4_bin` key collations, case-sensitive key uniqueness, and the `checkout_keys` rollback guard;
- required — `CheckoutAuthorityConcurrencyTest` and `ReservationConcurrencyTest`: the v3 process-output barrier suites;
- advisory (`continue-on-error: true`) — `LegacyCheckoutKeyConcurrencyTest` and `SettlementConcurrencyTest`.

The advisory tier is deliberate. Those two classes guard real product protections and their behavior assertions pass on MySQL, but `LegacyCheckoutKeyConcurrencyTest` still confirms the wait through `information_schema.innodb_trx.trx_state = 'LOCK WAIT'`, and that read has been observed to omit a demonstrably waiting worker on MySQL 8.0 and 8.4 even while `processlist`, `performance_schema.data_locks`, and `SHOW ENGINE INNODB STATUS` all show the wait. This job reproduced it on GitHub-hosted MySQL 8.4.11: every settlement data set passed while all four legacy data sets failed at `LegacyCheckoutKeyConcurrencyTest.php:73`. Settlement's barrier now reads `performance_schema` instead, but the job still runs both classes in one advisory step, so promoting the tier to blocking needs a separate workflow change once the legacy observer is replaced too. A red advisory step therefore means the observer missed the wait, not that the protection failed; read the step output before treating it as a product defect.

## Postman collection

The collection runs the HTTP contract in order: health environment gate, admin login (also signing in the cashier once for the role checks), invalid-credential and missing-token 401 checks, managed-product creation/editing/stock update, field-validation 422, cash sale with idempotent retry and pinned total/cash/change echo, plus nested stock checks proving one deduction and stock-short rollback, cashier-token 403 on product creation, cash-below-total 422 with a follow-up inventory read proving the rejected sale left stock at 7, unknown-product 422, catalog products with the seeded prices pinned, matching and empty name searches, barcode lookup, unknown-barcode handling, inventory state/filter checks, QR practice checkout and payment read, signed practice webhook rejection of unsupported settlement, invalid-signature and duplicate-event webhook checks, failed-payment practice checkout and webhook with no sale or stock change, payment read again, sales history with exactly one completed cash sale, and transactions. It then runs the Checkout V3 durable local workflow folder: contract/session discovery, unknown-checkout 404, cash and QR checkout creation, cash tender acceptance and replay, attempt and reservation reads, local QR attempt refresh, and checkout abandonment, asserting the v3 contract version, quote math, pending attempt, held reservation, and the completed cash sale's total, cash received, and change.

### Automated report

`.github/workflows/newman.yml` runs this collection on pull requests and pushes to `main`, `staging`, and `development`, and on manual dispatch. It provisions a fresh SQLite database and cashier, generates and masks per-run credentials, starts a local API, waits for its health check, and runs the Newman and htmlextra versions pinned by `scripts/newman-report.sh`. The job uploads sanitized JSON, HTML, JUnit, CLI, and Markdown summary reports for 14 days, including when Newman reports a collection failure, and publishes a rich Markdown job summary: run metrics, per-request method/status/response-time/assertion evidence, the five slowest requests, and collapsible failure details with check names and messages, plus links to the workflow run and uploaded artifact and its `sha256` digest. The local `bash scripts/newman-report.sh` run writes the same summary tables to `reports/newman/summary.md` without the CI-only run and artifact links. The summary is capped at 256 KiB with a truncation note, and the job fails if the step summary would exceed GitHub's 1 MiB per-step limit. The sanitizer structurally redacts sensitive JSON fields and scrubs configured credentials, bearer and bare token-shaped values, and webhook timestamp and signature strings from every artifact, including base64- and Buffer-encoded response bodies. A fail-closed leak check prevents unverified artifacts from being published. Because this repository is public, do not add unreviewed report files or credentials to artifacts.

To create the same sanitized reports locally, complete the fresh-database, API, and cashier setup below, export `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `QA_CASHIER_PASSWORD`, and `PAYMONGO_WEBHOOK_SECRET`, then run:

```sh
bash scripts/newman-report.sh
```

The script downloads the exact pinned Newman and htmlextra packages through `npx`, sanitizes and checks every artifact, and writes the results to the ignored `reports/newman/` directory. It targets the collection's local default URL; do not use this script against staging. It exits with Newman's result after sanitization, so a failed contract run still leaves safe reports for diagnosis.

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

Reseed the local database so the run starts from the documented baseline. The run mutates data (sales, payments, stock holds), so a database carried over from an earlier run is no longer the documented baseline:

```sh
php artisan migrate:fresh --seed
```

Success: every migration runs and `Database\Seeders\DatabaseSeeder ... DONE` is printed. This is the required precondition for every local Newman run; never run Newman twice against the same database without reseeding first. (Staging uses the symmetric `php artisan qa:reset --force` on the staging machine, and only with the cycle's approved data.)

Start the API in another terminal. Export the admin email and password from your local `.env`, then run (the collection refuses placeholder secrets):

```sh
export ADMIN_EMAIL="$(sed -n 's/^ADMIN_EMAIL=//p' .env)"
export ADMIN_PASSWORD="$(sed -n 's/^ADMIN_PASSWORD=//p' .env)"
npx newman run postman/PorSca-API.postman_collection.json \
  -e postman/PorSca-API.postman_environment.json \
  --env-var admin_email="$ADMIN_EMAIL" \
  --env-var admin_password="$ADMIN_PASSWORD" \
  --env-var cashier_email=qa-cashier@porsca.test \
  --env-var cashier_password="$QA_CASHIER_PASSWORD" \
  --env-var webhook_secret="$PAYMONGO_WEBHOOK_SECRET"
```

Success: all 49 collection requests and every assertion pass; Newman's summary counts 56 HTTP requests because seven nested `pm.sendRequest` calls verify cashier login, sale/retry and rejection stock state, and failed-webhook stock state. The login request (second after the Health gate) signs in with `admin_email` and `admin_password` and captures the returned token into `{{token}}`; the same request also signs in with `cashier_email` and `cashier_password` and captures the cashier token into `{{cashier_token}}`. No cashier is seeded, so create the standing cashier with the [QA accounts recipe](QA-CYCLE.md#qa-accounts) and export `QA_CASHIER_PASSWORD` (plus `ADMIN_PASSWORD` from your local `.env`) before the run. The seeded database must be fresh before this run because the collection creates a managed product, completes a cash sale and retry, proves a QR Ph practice fixture cannot be marked paid by a forged status, verifies invalid and duplicate webhook behavior, and pins the seeded prices, stock, and cash totals. Real paid/failed settlement requires operator-owned PayMongo test credentials and a stable registered HTTPS webhook; use the deterministic faked-HTTP feature suite until those are available. Never scan/pay a test-mode QR; only the operator may use the provider simulator URL.

Two collection safeguards protect the run:

- A collection-level pre-request guard aborts before any stateful request fires when `admin_password`, `cashier_password`, or `webhook_secret` still hold a placeholder value, or when no login token was captured.
- The Health request runs first as an environment gate: it asserts `environment === {{expected_environment}}` (`local`), `database === 'ok'`, and `paymongo_mode === {{expected_paymongo_mode}}` (`sandbox`), and stops the whole run on mismatch so a dev-vs-staging mixup ends at Health with zero stateful side effects.

> **Known limitation:** the Health gate proves environment, database reachability, and sandbox mode only. It does not prove database identity: a local API pointed at a foreign database still passes the gate, and the run then proceeds to stateful writes against that data. Verify database identity manually (for example `DB_DATABASE` and `DB_HOST` in the API's `.env`) before reseeding or any stateful run.

The managed-product barcode is generated per run (timestamp-suffixed, captured into `{{managed_product_barcode}}` with the edit-step value in `{{managed_product_barcode_edit}}`), so back-to-back runs with a reseed between them never collide on the barcode unique rule. The seeded database must be fresh before this run because the collection creates a managed product, completes a cash sale and retry, proves a QR Ph practice fixture cannot be marked paid by a forged status, and verifies invalid and duplicate webhook behavior. Real paid/failed settlement requires operator-owned PayMongo test credentials and a stable registered HTTPS webhook; use the deterministic faked-HTTP feature suite until those are available. Never scan/pay a test-mode QR; only the operator may use the provider simulator URL.

For staging, copy the environment file to a file outside git and override `base_url`, `admin_email`, `admin_password`, `cashier_email`, `cashier_password`, and `webhook_secret` from the staging machine's environment. Never commit the copy. Create the standing cashier on the staging host with the [QA accounts recipe](QA-CYCLE.md#qa-accounts) and export `QA_CASHIER_PASSWORD` before the run:

```sh
cp postman/PorSca-API.postman_environment.json /tmp/porsca-staging.postman_environment.json
npx newman run postman/PorSca-API.postman_collection.json \
  -e /tmp/porsca-staging.postman_environment.json \
  --env-var base_url=https://staging-api.porsca.online/api/v1 \
  --env-var expected_environment=staging \
  --env-var admin_email="$ADMIN_EMAIL" \
  --env-var admin_password="$ADMIN_PASSWORD" \
  --env-var cashier_email=qa-cashier@porsca.test \
  --env-var cashier_password="$QA_CASHIER_PASSWORD" \
  --env-var webhook_secret="$PAYMONGO_WEBHOOK_SECRET"
```

(Override `--env-var expected_paymongo_mode=...` as well if the target host ever reports a non-sandbox mode.)

Success: every request and assertion passes against the same stable staging URL. The collection gate stops the run at Health only on environment, PayMongo mode, or database unreachability; it does not verify database identity and does not compare the deployed `commit`, so confirm the approved URL and full SHA per [STAGING.md](STAGING.md) before the run. Run this only with the cycle's approved data. Do not reset staging during the run.

## Evidence

For each formal QA cycle, retain:

- the `composer verify` output or CI run URL
- the Newman text or JSON report
- the collection and environment version used
- screenshots or response bodies for any payment and webhook check

Keep evidence with the cycle record. Do not retain API tokens, PayMongo secret keys, webhook secrets, or raw authorization headers.
