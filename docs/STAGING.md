# Staging guide

Staging is for formal QA. It is not a developer scratch database.

Preparing a staging environment does not create or start a formal QA cycle. A human release owner creates the cycle record only after selecting a paired mobile/API candidate and deciding to begin formal testing.

## Branch and promotion rules

1. Make a feature branch from `main`.
2. Open a pull request from the feature branch to `staging`.
3. Run the API checks on that pull request.
4. If QA finds a defect, branch from `staging` and open a fix pull request back to `staging`.
5. Do not patch `staging` directly.
6. Promote the exact API commit and the paired mobile commit to `main` only after a human approves the formal QA cycle.
7. Never merge a release by an agent.

`main` is stable. `staging` started at the same commit as `main` and should remain the QA integration branch.

## Merging while the check runners are down (billing lock)

Checks that never started are not the same as checks that failed. Code that the runners actually failed must never be merged without a fix.

Merging into `staging` while the runners are down is allowed only when:

1. The PR body shows a green local run of the canonical `composer verify` command.
2. A human reviewed the diff.
3. The billing lock is confirmed as the only reason the checks sat out.

Promoting into `main` still waits for green checks or a completed human QA round with approval. Quotas reset monthly, so this is temporary. Narrowing check triggers is a separate later decision.

## Permanent host: alwaysdata Free

Use the no-card **Free** managed PHP plan. The permanent API origin is
`https://<account>.alwaysdata.net/api/v1`; `<account>` is deliberately unchosen.
Choose it once: changing the origin requires a new manually built preview app.
The operator checklist is [issue 37](https://github.com/niks0501/PorSca_POS_API/issues/37).
No automatic preview builds, auto-merge, or QA approvals are introduced.

### Site and isolated MariaDB

In the alwaysdata panel, create a PHP site at `<account>.alwaysdata.net` with
document root **`/home/<account>/porsca-api/public`**, never the project root.
Select a PHP version compatible with `composer.lock` for both the site and SSH CLI.
Enable HTTPS. Create a dedicated MariaDB staging database and user in the panel;
use its exact prefixed database name and grants, not the developer database.

Over SSH, clone the public API repository into `~/porsca-api` and select `staging`:

```bash
ssh <account>@ssh-<account>.alwaysdata.net
git clone https://github.com/niks0501/PorSca_POS_API.git ~/porsca-api
cd ~/porsca-api
git checkout staging
composer install --no-dev --optimize-autoloader
cp .env.example .env
chmod 600 .env
php artisan key:generate
chmod -R u+rwX storage bootstrap/cache
```

Configure these values privately on the host. Obtain database names, user, and
password from the panel; generate a strong admin password privately. Obtain
PayMongo **test** credentials and webhook signing material from the operator,
following [the local webhook contract](ARCHITECTURE.md#paymongo-webhook).
Never copy database or payment secrets to Actions, mobile code, or source.

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://<account>.alwaysdata.net
DB_CONNECTION=staging
STAGING_DB_CONNECTION=mysql
STAGING_DB_HOST=mysql-<account>.alwaysdata.net
STAGING_DB_PORT=3306
STAGING_DB_DATABASE=<account>_porsca_staging
STAGING_DB_USERNAME=<panel-database-user>
STAGING_DB_PASSWORD=<private-database-password>
ADMIN_NAME=<store-admin-name>
ADMIN_EMAIL=<store-admin-email>
ADMIN_PASSWORD=<private-strong-admin-password>
SANCTUM_EXPIRATION=43200
PAYMONGO_MODE=sandbox
PAYMONGO_SECRET_KEY=<operator-test-secret>
PAYMONGO_WEBHOOK_SECRET=<operator-signing-secret>
```

Keep storage within the Free plan quota (code, logs and database share disk space),
and maintain the account according to the provider's inactivity policy. No
long-running Laravel development server or queue service is required.

### SSH deployment automation

`.github/workflows/deploy-staging.yml` deploys every push to `staging`, with
serialized runs and no cancellation of an active deployment. Manual dispatch
must select `staging`; other refs skip deployment. Approval-only merges remain
unchanged. Configure exactly three Actions secrets:

- `STAGING_HOST`: `ssh-<account>.alwaysdata.net`
- `STAGING_USER`: `<account>`
- `STAGING_SSH_KEY`: dedicated SSH private key authorized for that account

The key is loaded via stdin into a short-lived SSH agent, not written to disk.
Host keys are obtained with `ssh-keyscan` through a pipe: this is trust-on-first-use,
not an independently pinned host identity. Verify the host fingerprint through
the provider during setup; network interception of key discovery remains a
bootstrap limitation of this three-secret configuration.

The host checkout must already exist at `~/porsca-api`, with its private `.env`,
application key, database grants, working Composer/PHP CLI, and HTTPS site.
The workflow fetches `staging`, rejects stale queued SHAs rather than rolling
back a newer staging tip, resets tracked code to the event SHA, installs
production dependencies, clears old configuration, and migrates with `--force`.
It records `APP_COMMIT` in the host's `.env` **before** `config:cache` so the HTTP
health payload reports that revision. It then requests the HTTPS health URL from
host-owned `APP_URL`, requiring successful HTTP, `data.status=ok` and the exact
`data.commit`. A stale run can be superseded by the next push or dispatched again
on the latest `staging` revision.

Deployment never resets or seeds QA data. Migrations can alter schema; coordinate
checkout writer pauses as required below. Deployment failure is not a rollback:
inspect the failed step privately, correct the cause and rerun for the current
staging SHA. Do not delete financial records to recover.

The host is not provisioned yet. YAML/local tests do not prove live deployment;
the first approved merge after operator setup must prove the full job and SHA
health check. The separate mobile staging-state job supplies paired-SHA evidence;
an API deploy alone is not a paired release or formal QA approval.

### Baseline and health checks

After environment changes, run over SSH from `~/porsca-api`:

```bash
php artisan config:clear
php artisan migrate --force
php artisan config:cache
php artisan db:show --database=staging
```

The database output must identify the panel's isolated staging database and
application tables. SQLite is only an isolated local-test fallback, never the
permanent staging database.

Only after a human release owner creates a new cycle, reset once at its start:

```bash
php artisan qa:reset --force
```

Success ends with `Staging reset to qa-baseline-2026-10.` and seeds four products.
This command runs fresh migrations and seeds on the staging connection and is
destructive. Once a cycle starts, preserve the database: do not reset, reseed or
edit rows by hand. A new baseline requires a new cycle ID and reset.

From outside the host:

```bash
curl --fail https://<account>.alwaysdata.net/api/v1/health
```

PowerShell:

```powershell
Invoke-RestMethod -Uri 'https://<account>.alwaysdata.net/api/v1/health'
```

Require HTTP 200 and `data.environment=staging`, `data.database=ok`,
`data.paymongo_mode=sandbox`, `data.status=ok`, and `data.commit` equal to the
deployed full API SHA. Before the first automated deployment, `commit` defaults
to `unknown`; that is not paired-SHA evidence. The mobile origin includes
`/api/v1` and never uses `localhost`.

## Checkout v3 promotion boundary

`porsca-mobile-api-v2` names the shipped RBAC-era contract; the new durable checkout resources use `porsca-mobile-api-v3`, retaining `/api/v1` and the old endpoints for compatibility. This foundation is an API-only candidate, not authorization to promote: update the mobile single API client and its contract document to v3, select exact paired full SHAs, run both repositories' checks, and obtain human formal QA approval before paired promotion. Existing v2 mobile clients do not obtain v3 recovery/exclusivity by merely changing a header.

Deploy the additive checkout migration with payment/checkout writers paused, then the matching API code; preserve old records with null new fields rather than fabricated historical identities. Rollback with any v3 checkout records is refused to protect financial/audit history; use an operator-owned archival/reconciliation plan, not deletion. [CHECKOUT-CONTRACT.md](CHECKOUT-CONTRACT.md) defines requests, states, gates and evidence semantics. GATE-01..06 remain open; deterministic fixtures are not official PayMongo acceptance. Simulation retrieval is explicitly enabled staging-admin-only, and provider finality is fail-closed until the required attempt correlation is validated. The current adapter never infers non-payability from intent failure, expiry timers or hold release.

## Release handoff

The staging record must name the exact API commit, the exact mobile commit, the baseline version, the database environment, the stable URL, and the PayMongo sandbox context. Follow [QA-CYCLE.md](QA-CYCLE.md). Human QA owns the final approval.

## Appendix: Tailscale Funnel break-glass only

A local API and isolated local staging database may be used for operator checks,
Newman, or internal dev-client builds when the permanent host is unavailable.
This is not the permanent tester origin: the preview app bakes its URL and cannot
silently switch to Funnel. Never substitute the developer database or copy live
financial data. Keep private local settings outside git.

Start the API on that machine and keep it awake:

```text
php artisan config:clear
php artisan serve --host=0.0.0.0 --port=8000
```

In another terminal (PowerShell or Bash):

```text
tailscale funnel 8000
```

Check the printed HTTPS URL before use:

```bash
curl --fail https://<tailscale-funnel-host>/api/v1/health
```

```powershell
Invoke-RestMethod -Uri 'https://<tailscale-funnel-host>/api/v1/health'
```

Require the same healthy sandbox/staging fields. Keep the API process and laptop
running; do not promise uptime from a laptop. Record the exact URL for any approved
break-glass cycle. If the origin, SHA, baseline or sandbox context changes, stop
the cycle and create a new environment/cycle record. An internal client must use
the full Funnel URL ending in `/api/v1`; preview rebuilds remain manual.
