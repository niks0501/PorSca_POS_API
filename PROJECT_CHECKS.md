# Project Verification Checks

## Fast checks

- Format/lint: `vendor/bin/pint --test`
- Typecheck/static analysis: none configured in this repository
- Focused test example: `php artisan test --filter=ApiContractTest` (replace with the relevant existing test class or method)

## Broader checks

- Test suite and canonical repository check: `composer verify` (runs `php artisan config:clear`, the Laravel test suite, and Pint)
- Build: no frontend or separate build command is configured here
- Integration/API contract: PHPUnit feature tests cover the API contract. The Postman/Newman workflow is documented in `docs/TESTING.md` and requires its documented local environment and data setup.
- E2E: no browser/device E2E setup exists in this API repository; it is not a routine check for API-only changes

## Manual verification

- Use the Postman/Newman workflow in `docs/TESTING.md` when an HTTP-level contract run is specifically needed.
- Follow `docs/QA-CYCLE.md` and `docs/STAGING.md` for formal release/staging verification; do not run those workflows as routine local checks.

## Database checks

- Schema/migration tests run under the in-memory SQLite connection configured in `phpunit.xml`; those checks do not prove MySQL-specific behavior.
- No non-destructive migration dry-run is configured. `migrate:fresh --seed` resets the selected database and is only safe against a disposable local database.
- For MySQL-specific behavior, use the documented local MySQL connection and a disposable development database; see `docs/SETUP.md` and `DATABASE_GUIDE.md`.

## Rules

- Start with the narrowest meaningful check and report commands exactly as run.
- Do not install dependencies just to fill a verification gap.
- Do not deploy, publish, or run destructive database checks as part of routine verification.
- Distinguish passed, failed, skipped, and unavailable checks.
