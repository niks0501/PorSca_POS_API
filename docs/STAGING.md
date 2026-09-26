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

Staging uses its own MySQL database, `porsca_staging`. Do not point it at the developer database. Set these values on the staging machine and keep real secrets there:

```dotenv
APP_ENV=staging
APP_DEBUG=false
DB_CONNECTION=staging
STAGING_DB_CONNECTION=mysql
STAGING_DB_DATABASE=porsca_staging
STAGING_DB_HOST=127.0.0.1
STAGING_DB_PORT=3306
STAGING_DB_USERNAME=porsca_staging
STAGING_DB_PASSWORD=<strong-staging-password>
API_TOKEN=<staging-token>
PAYMONGO_MODE=sandbox
PAYMONGO_SECRET_KEY=<PayMongo-test-secret>
PAYMONGO_WEBHOOK_SECRET=<server-webhook-secret>
```

Create the database and a dedicated user if they do not already exist. Replace the password placeholder with the same private password used in the environment file. Run these statements as a MySQL administrator:

```sql
CREATE DATABASE IF NOT EXISTS porsca_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'porsca_staging'@'localhost' IDENTIFIED BY '<strong-staging-password>';
GRANT ALL PRIVILEGES ON porsca_staging.* TO 'porsca_staging'@'localhost';
```

Success: MySQL creates the database and user and grants access without an error. If MySQL reports that either already exists, keep the existing account and make sure its password and grants match the environment values.

Only after the human release owner has created a new QA cycle, reset the isolated database once at its start:

```sh
php artisan qa:reset --force
```

Success: the command ends with `Staging reset to qa-baseline-2026-02.` and seeds four products. It runs a fresh migration and seed on the `staging` connection. This is destructive, so run it only before a cycle starts. After the cycle starts, preserve the database. Do not reset, reseed, or edit rows by hand. A new baseline requires a new cycle ID and a fresh reset.

After changing the environment file, clear cached configuration and restart the API so it reads the new values:

```sh
php artisan config:clear
php artisan serve --host=0.0.0.0 --port=8000
```

Success: the first command says the configuration cache was cleared; the server starts listening on port 8000. Keep the server running in this terminal. Verify the database has the expected tables:

```sh
php artisan db:show --database=staging
```

Success: the output identifies the `porsca_staging` database and lists tables including `migrations` and the application's product and sales tables.

Check the API health endpoint:

```sh
curl https://<stable-api-host>/api/v1/health
```

Success: the response has `environment: staging`, `database: ok`, and `status: ok` (HTTP 200).

SQLite is a fallback for isolated local checks only: configure `STAGING_DB_CONNECTION=sqlite` and `STAGING_DB_DATABASE` as an absolute path to a separate SQLite file. Do not use SQLite as the staging server database.

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
