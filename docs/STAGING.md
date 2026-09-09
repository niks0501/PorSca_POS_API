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

## Isolated database

The staging database must not be the developer database. Use a database named `porsca_staging` in MySQL/PostgreSQL, or the separate SQLite file `database/porsca_staging.sqlite`.

Set these values only on the staging machine:

```dotenv
APP_ENV=staging
APP_DEBUG=false
DB_CONNECTION=sqlite
STAGING_DB_CONNECTION=sqlite
STAGING_DB_DATABASE=/absolute/path/to/porsca_staging.sqlite
API_TOKEN=<staging-token>
PAYMONGO_MODE=sandbox
PAYMONGO_SECRET_KEY=<PayMongo-test-secret>
PAYMONGO_WEBHOOK_SECRET=<server-webhook-secret>
```

For MySQL, set `STAGING_DB_CONNECTION=mysql`, `STAGING_DB_DATABASE=porsca_staging`, and the `STAGING_DB_HOST`, `STAGING_DB_PORT`, `STAGING_DB_USERNAME`, and `STAGING_DB_PASSWORD` values. Keep all secrets on the staging host.

After the human release owner has created a new QA cycle, create or reset the database once at its start:

```sh
touch database/porsca_staging.sqlite
php artisan qa:reset --force
```

Success looks like the command ending with `Staging reset to qa-baseline-2026-02` and four products in the new database. This runs `migrate:fresh --seed` against the `staging` connection. It is destructive, so it is allowed only before a cycle starts.

After the cycle starts, preserve the database. Do not run `migrate:fresh`, reseed, or edit rows by hand. A new baseline requires a new cycle ID and a fresh reset.

Start the API with the staging settings:

```sh
php artisan serve --host=0.0.0.0 --port=8000
```

Success:

```sh
curl https://<stable-api-host>/api/v1/health
```

returns `environment: staging`, `database: ok`, and `status: ok`.

## Local machine and Tailscale Funnel

A local staging server is reachable only while the computer is on and the process is running. A home or office network can also block inbound traffic. A Tailscale Funnel URL must be enabled on the same machine that runs the API:

```sh
tailscale funnel 8000
```

Success looks like Tailscale printing an HTTPS URL. Check that URL before giving it to testers:

```sh
curl https://<tailscale-funnel-host>/api/v1/health
```

The Funnel URL must be stable for the complete QA cycle. Do not use a temporary tunnel URL. Do not promise uptime from a developer laptop. Keep the laptop awake, keep the API process running, and record the exact stable URL in the cycle record. If the URL changes, stop the cycle and create a new environment record.

The mobile build must use the full URL ending in `/api/v1`. A phone must not use `localhost`.

## Release handoff

The staging record must name the exact API commit, the exact mobile commit, the baseline version, the database environment, the stable URL, and the PayMongo sandbox context. Follow [QA-CYCLE.md](QA-CYCLE.md). Human QA owns the final approval.
