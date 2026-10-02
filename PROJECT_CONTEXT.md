# Project Context

## Purpose

PorSca's Laravel REST API is the source of truth for point-of-sale products, inventory, sales, transactions, authentication, and payments. The separate Expo mobile app consumes this API.

## Stack

- Application/framework: Laravel 13
- Language/runtime: PHP 8.3+
- Frontend: None in this repository; client is maintained separately
- Backend: Laravel REST API
- Database: MySQL at runtime; PHPUnit uses in-memory SQLite
- External services: PayMongo sandbox for QR Ph payments and signed webhooks; local practice fixtures work without provider credentials
- Deployment target: Staging and promotion workflow is documented in `docs/STAGING.md`; infrastructure configuration is not maintained here

## Architecture

- `routes/api.php` defines versioned API routes; controllers, middleware, models, and service seams live under `app/`.
- Migrations and seeders are under `database/`.
- `docs/ARCHITECTURE.md` documents API/auth/payment contracts and the critical checkout, cash-sale, inventory, and settlement boundaries.

## Important conventions

- Keep business rules and atomic write workflows in the established services; validate requests and authorize access on the server.
- Use Sanctum personal access tokens and the existing admin/cashier role rules.
- Treat client totals and prices as untrusted; money is stored in integer PHP centavos.
- Preserve the mobile/API contract, response and error shapes, idempotency, and webhook settlement behavior.

## Security and data sensitivity

- Keep `.env` values, admin passwords, bearer tokens, PayMongo credentials, and webhook secrets out of source, logs, Postman evidence, and API responses.
- The API has no public registration. Use the documented token and role rules; verify the raw signed webhook before trusting event data.
- PayMongo operates in sandbox mode only. A local QR practice fixture does not represent a charge.

## External contracts

- The release-unit contract is `porsca-mobile-api-v2`; verify it against the mobile repository's contract before pairing revisions.
- The API base URL ends at `/api/v1`. See `docs/ARCHITECTURE.md` for endpoint, authentication, error, payment, and webhook details.
- `postman/` contains the API collection and environment template; use the workflow in `docs/TESTING.md` when an HTTP contract check is required.

## Verification

- Canonical check: `composer verify` (Laravel tests and Pint); details are in `PROJECT_CHECKS.md`, `docs/SETUP.md`, and `docs/TESTING.md`.
- PHPUnit is configured for in-memory SQLite. Use a disposable MySQL database for MySQL-specific behavior checks when necessary.

## Constraints

- This repository is API-only. The API and separate mobile app are one release unit; do not treat this repository as containing a React frontend.
- Do not use destructive database commands against shared or staging data. Follow `docs/STAGING.md` and `docs/QA-CYCLE.md` for release activity.

## Current priorities

No task-specific priority is recorded here; follow the active issue and approved release/QA cycle.

## Known debt or limitations

- The documented local payment fixture is not a real provider payment. Real sandbox verification requires operator-owned PayMongo credentials and a stable registered webhook URL.
- The API architecture document identifies the old Express payment scaffold as non-authoritative; Laravel remains the source of truth.
