# PorSca API agent memory

- This is a Laravel 13 API. Run `composer verify` for the canonical tests and formatting check.
- `App\Services\PaymentSettlementService` is the authoritative atomic sale and inventory boundary; keep duplicate payment and webhook delivery idempotent.
- The public mobile contract is documented in `docs/ARCHITECTURE.md`; local setup is in `docs/SETUP.md`.
- Staging uses the separate `staging` database connection and `php artisan qa:reset --force` only before a QA cycle. Follow `docs/STAGING.md` and `docs/QA-CYCLE.md`.
- PayMongo is sandbox-only. Secrets and webhook verification material belong in `.env`, never source or committed Postman files.

## Maintaining this file

Keep this file for knowledge useful to almost every future agent session in this project.
Do not repeat what the codebase already shows; point to the authoritative file or command instead.
Prefer rewriting or pruning existing entries over appending new ones.
When updating this file, preserve this bar for all agents and keep entries concise.
