# Staging guide

Staging is for formal QA, not a developer scratch database. Preparing the host does not start a formal QA cycle; a human release owner creates the cycle record after selecting paired mobile/API candidates.

## Branch and promotion rules

1. Make feature branches from `development` and open feature pull requests to `development`.
2. Reconcile `development` into a promotion branch based on `staging`, then open a separate captain-approved promotion pull request to `staging`. Never push directly to `staging` or merge a promotion autonomously; feature work stays on `development`.
3. Promote the exact paired API and mobile commits to `main` only after human approval of formal QA. Agents never merge releases.

Unavailable checks are not failed checks: merge to `staging` during runner outages only with green local `composer verify`, human diff review, and confirmation billing is the sole cause. `main` promotion still requires green checks or completed human-approved QA.

## AWS Lightsail staging runbook

The permanent API origin is `https://staging-api.<domain>`; clients use the `/api/v1` base. This is a literal placeholder until the operator selects and configures the existing domain. The instance is not live yet: configuration below is a plan, not proof of deployment. The first promotion merge into `staging` after operator setup must demonstrate a successful deploy and exact-commit health assertion. Do not configure DNS, secrets, or infrastructure from this guide alone.

### Bootstrap (operator)

Provision the decided Lightsail Small-2GB Ubuntu 24.04 instance in Singapore (`ap-southeast-1`), with static IPv4 and inbound 22/80/443 only. Use key-only SSH on port 22, disable password and root login, create the dedicated `deploy` user, and add 2 GB swap. Paid billing is the decided account plan; confirm credit eligibility/expiry and budget alert with the account owner.

Install Nginx, PHP 8.3-FPM and required extensions, Composer 2, git, and MySQL 8. Bind MySQL to loopback; create `porsca_staging` and a least-privilege database user. Configure Nginx/TLS for `staging-api.<domain>` (Certbot/Let's Encrypt) with the document root at `/home/deploy/porsca-api/public`; verify renewal. Configure PHP-FPM as `deploy:www-data`. Clone the API into `/home/deploy/porsca-api`, check out `staging`, and install production dependencies.

Create the host-only `.env` (mode 0600), generate the Laravel key, migrate, and set ownership/permissions so the deploy user owns the checkout and PHP-FPM's `www-data` group can write `storage` and `bootstrap/cache`. The example is a template only; substitute values privately on the host. Never commit actual credentials or put database, PayMongo, or admin secrets in GitHub Actions or mobile configuration.

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-api.<domain>
DB_CONNECTION=staging
STAGING_DB_CONNECTION=mysql
STAGING_DB_HOST=127.0.0.1
STAGING_DB_PORT=3306
STAGING_DB_DATABASE=<staging-database-name>
STAGING_DB_USERNAME=<staging-database-user>
STAGING_DB_PASSWORD=<private-database-password>
ADMIN_NAME=<store-admin-name>
ADMIN_EMAIL=<store-admin-email>
ADMIN_PASSWORD=<private-strong-admin-password>
SANCTUM_EXPIRATION=43200
PAYMONGO_MODE=sandbox
PAYMONGO_SECRET_KEY=<operator-test-secret>
PAYMONGO_WEBHOOK_SECRET=<operator-signing-secret>
```

Add the daily Laravel scheduler cron (`php artisan schedule:run`) and retain backups/snapshots before migrations and schema changes. PayMongo remains sandbox-only; register its signed webhook at `https://staging-api.<domain>/api/v1/webhooks/paymongo` only after the operator configures the live host.

### Deploy

The workflow `.github/workflows/deploy-staging.yml` deploys only on manual dispatch for the `staging` ref; pushes do not deploy. It serializes runs, rejects stale SHAs, refuses to reset a dirty host checkout, enters maintenance mode, resets to the dispatched commit, restores `www-data` group write access on the existing writable paths, installs production dependencies, clears config, migrates, exits maintenance mode, writes `APP_COMMIT`, caches config, and calls the HTTPS health endpoint. It requires HTTP success, `data.status=ok`, and the exact deployed `data.commit`.

Configure these three GitHub Actions secrets: `STAGING_HOST`, `STAGING_USER`, and `STAGING_SSH_KEY`, plus the repository Actions variable `STAGING_HOST_KEY`. Use port 22. No database, PayMongo, admin, or AWS credentials belong in Actions. The SSH key is loaded through a short-lived agent. Before the first dispatch, collect the host's public key line with `ssh-keyscan "$STAGING_HOST"` on a trusted operator machine and independently verify its fingerprint with the operator using a trusted source. Set `STAGING_HOST_KEY` to exactly one verified, unhashed output line (`<host> <keytype> <base64>`); do not use a SHA256 fingerprint, a hashed `ssh-keyscan -H` line, or multiple lines. The workflow writes this exact pin as the sole entry in SSH's temporary known-hosts file and fails closed if the variable is absent or contains multiple lines; SSH rejects any host that does not present the pinned key. The host checkout, private `.env`, app key, database grants, Composer/PHP, and HTTPS must be ready before dispatch.

Deployment does not reset or seed QA data. Migrations may change schema; coordinate checkout writer pauses as required. A failed run is not an automatic rollback; diagnose privately and retry only the current staging SHA. The first real live proof is the first promotion merge into `staging` after setup, with a green workflow and health fields `environment=staging`, `database=ok`, `paymongo_mode=sandbox`, `status=ok`, and exact `commit`. Until then, local/YAML checks are not live proof.

### Verify

On the host, inspect configuration and database without exposing credentials:

```bash
cd ~/porsca-api
php artisan config:clear
php artisan migrate --force
php artisan config:cache
php artisan db:show --database=staging
```

Confirm the isolated staging database and application tables. SQLite is only a local-test fallback. From an external network:

```bash
curl --fail https://staging-api.<domain>/api/v1/health
```

Require HTTP 200 and the staging, healthy database, sandbox, healthy status, and deployed full SHA fields above. After a human release owner starts a new cycle, reset once at its start with `php artisan qa:reset --force`; it is destructive and never a recovery step. Preserve cycle data thereafter.

### Rollback

Before risky schema changes, take a snapshot and database dump. For an application-only failure, inspect the failed deployment, then check out the known-good full commit and reinstall dependencies, clear/cache configuration, and verify health. Do not revert across migrations that have changed or consumed data without an explicit recovery plan. Migrations are forward-only; if schema rollback is unsafe, fix forward. Protect financial/audit history: never delete records, run `db:wipe`, or use `qa:reset` as rollback. Any v3 checkout data makes rollback to incompatible code a no-go; follow [the checkout contract](CHECKOUT-CONTRACT.md) and operator-owned archival/reconciliation plan.

### Restore

For a host loss or data incident, stop writes and have the operator provision/recover a clean host and restore the appropriate database backup or Lightsail snapshot. Restore into an isolated database first and validate schema, record integrity, application configuration, and HTTPS health before routing staging traffic. Keep `.env` and keys host-only; reapply least-privilege ownership, `deploy:www-data` writable paths, firewall, TLS, and scheduler configuration. Record the restored revision and data point with the human release owner; restart QA only through a new cycle decision. Never overwrite or discard financial data without operator authorization.

## Checkout v3 promotion boundary

`porsca-mobile-api-v2` names the shipped RBAC-era contract; new durable checkout resources use `porsca-mobile-api-v3`, retaining `/api/v1` and old endpoints for compatibility. This foundation is an API-only candidate, not authorization to promote: update the paired mobile client/contract to v3, select exact paired full SHAs, run both repositories' checks, and obtain human formal QA approval. See [CHECKOUT-CONTRACT.md](CHECKOUT-CONTRACT.md).

## Release handoff

The staging record names exact API and mobile commits, baseline version, database environment, stable URL, and PayMongo sandbox context. Follow [QA-CYCLE.md](QA-CYCLE.md); human QA owns final approval.

## Appendix: Tailscale Funnel break-glass only

Use a local API and isolated local staging database only for operator checks or internal development builds when Lightsail is unavailable. Funnel is not the permanent tester origin, and a preview app cannot silently switch origins. Never use the developer database or copy live financial data. Keep local settings private.

```text
php artisan config:clear
php artisan serve --host=0.0.0.0 --port=8000
tailscale funnel 8000
```

Check the printed HTTPS URL before use and request `/api/v1/health`. Require healthy staging/sandbox fields, keep the API process and machine running, and record the exact temporary URL for any approved break-glass cycle. A changed stable origin, SHA, baseline, or sandbox context requires a new environment/cycle record. Internal clients use the full Funnel URL ending in `/api/v1`; preview rebuilds remain manual.
