# Project Verification Checks

## Tier command mapping (adaptive no-mistakes validation)

Route validation by change tier. Start with the narrowest meaningful check
and report commands exactly as run.

- T0 (style/format only): `vendor/bin/pint --test`
- T1 (focused change with an existing target): Pint plus
  `php artisan test --filter=<target>` for the affected test class or method
- T2 (default for code changes): `composer verify` (runs
  `php artisan config:clear`, the Laravel test suite, and Pint)
- T3 (locking, settlement, reservation, or idempotency-key uniqueness):
  `composer verify` plus the MySQL suite against a disposable local MySQL
  test database. Export `DB_CONNECTION=mysql` with `DB_HOST`, `DB_PORT`,
  `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` over the SQLite defaults
  in `phpunit.xml` (mirroring `.github/workflows/mysql.yml`), then run:

  ```sh
  php artisan test --filter='(IdempotencyKeyMigrationTest|CheckoutAuthorityConcurrencyTest|ReservationConcurrencyTest|LegacyCheckoutKeyConcurrencyTest|SettlementConcurrencyTest)'
  ```

  There is no `phpunit.mysql.xml`; `DB_*` environment exports select MySQL.

## Critical-path test authority and frozen matrix

For checkout authority/concurrency, settlement, reservations, and
idempotency-key migrations/uniqueness, only secondmate-blessed or human-owned
tests count as proof. Worker-written tests are supplemental proposals and do
not count as proof until explicitly blessed by secondmate or a human.

The golden money-path suites are immutable without captain sign-off:
`CheckoutAuthorityConcurrencyTest`, `SettlementConcurrencyTest`,
`ReservationConcurrencyTest`, `IdempotencyKeyMigrationTest`, and
`LegacyCheckoutKeyConcurrencyTest`. Frozen-matrix baseline: commit
`887e7aa36fcade5220e940b0f14d5ad7d20dabdd`, established by the [merged
change](https://github.com/niks0501/PorSca_POS_API/pull/83).

## Database rules

- SQLite (in-memory, per `phpunit.xml`) is the default test database.
- MySQL runs use only a disposable local test database. The suites rebuild
  or truncate whichever database the connection selects, so never point
  them at staging, production, or any other shared database.
- `migrate:fresh --seed` resets the selected database and is only safe
  against a disposable local database.

## Rules

- Do not install dependencies just to fill a verification gap.
- Do not deploy, publish, or run destructive database checks as part of
  routine verification.
- Distinguish passed, failed, skipped, and unavailable checks.
