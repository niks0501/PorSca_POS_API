# Project Stack Guide — Laravel API + MySQL

## Laravel API

- This repository is a Laravel 13 / PHP 8.3 REST API; it contains no frontend. The separate Expo mobile app is the API's client.
- Prefer framework-native routing, request validation, authorization, middleware, Eloquent/query builder, API resources, services, and PHPUnit tests.
- Keep authorization and input validation server-side. Never trust client-supplied prices or totals for sales and payments.
- Keep multi-write sales, payment settlement, and inventory changes inside the established transaction/service boundaries; avoid N+1 queries and unbounded reads.
- Preserve the documented API response/error shapes, idempotency behavior, and mobile contract. See `docs/ARCHITECTURE.md` before changing API or payment behavior.
- Treat migrations as high-risk changes; retain MySQL compatibility and use the repository's existing migration conventions.
- Money values are integer PHP centavos. Do not expose credentials, tokens, or payment secrets in responses or logs.

## MySQL

- MySQL is the application runtime database. Follow `docs/SETUP.md` for local connection configuration and `DATABASE_GUIDE.md` for MySQL-specific query, transaction, indexing, and migration guidance.
- The PHPUnit configuration uses an in-memory SQLite database; passing those tests alone does not prove MySQL-specific behavior.
- Use transactions and database constraints for invariants where appropriate. Do not run destructive migrations against shared or non-disposable data.

## Verification

- Use the real commands in `PROJECT_CHECKS.md`; `composer verify` is the canonical repository check.
- Prefer focused PHPUnit coverage, then the canonical test/format check and affected API contract checks.
- This repository has no browser UI, frontend build, or device test target. Do not add browser/device checks to routine API verification.

## Documentation and tools

- Prefer the repository's architecture, setup, and testing docs for project behavior.
- Laravel Boost provides Laravel-aware project help. Use Context7 when version-sensitive external library documentation is needed and repository evidence is insufficient.
