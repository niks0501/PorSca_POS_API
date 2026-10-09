# Local setup

## Requirements

Use PHP 8.3+, Composer, and MySQL. The local example uses database `PorSca_POS` and user `root` with an empty password. MySQL must be running. Commands are provided for Windows PowerShell and Linux/WSL2 Bash.

## Admin account

The API has no public registration. The first admin comes from `.env`. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` (and optionally `ADMIN_NAME`), then seed:

```text
php artisan migrate:fresh --seed
```

`ADMIN_PASSWORD` is only used when that email does not exist yet, so re-seeding never resets a password you changed later. The seeder skips itself and warns when the values are missing outside production, and refuses to seed production without them.

Tokens are Sanctum personal access tokens that expire after `SANCTUM_EXPIRATION` minutes (default `43200`, 30 days). Keep `ADMIN_PASSWORD` and `SANCTUM_EXPIRATION` out of source and commits.

## Install

Connect to MySQL as an administrator in PowerShell or Bash:

```text
mysql -u root -p
```

At the MySQL prompt, create the local database and grant the local application account access:

```sql
CREATE DATABASE IF NOT EXISTS PorSca_POS CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON PorSca_POS.* TO 'root'@'localhost';
```

Success: MySQL creates the database and grants access without an error. If your local root account has a password, put it in `DB_PASSWORD` in `.env`.

Install packages, copy the example settings, and generate the application key.

PowerShell:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Bash:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

The example settings use `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=PorSca_POS`, `DB_USERNAME=root`, and an empty `DB_PASSWORD`. Put your admin email and password into `ADMIN_EMAIL` and `ADMIN_PASSWORD`. Keep `.env` private; it is ignored by git. Success: Composer completes, and Laravel says `Application key set successfully`.

Prepare the local database and sample products (both shells):

```text
php artisan migrate:fresh --seed
```

Success looks like completed migrations and four seeded products, including in-stock, low-stock, and out-of-stock scenarios.

Start the server (both shells):

```text
php artisan serve --host=0.0.0.0 --port=8000
```

Success looks like a server listening on port 8000. Check its health in another terminal (both shells):

```text
curl.exe http://127.0.0.1:8000/api/v1/health
```

(The command name `curl.exe` works in PowerShell and Bash on Windows; on Linux/WSL2 use `curl` instead.) The response must contain `"status":"ok"`.

## Settings

Copy `.env.example` before local work. `.env` is ignored by git. Never put these values in source, Postman collections, screenshots, or mobile code:

- `PAYMONGO_SECRET_KEY`
- `PAYMONGO_WEBHOOK_SECRET`
- `ADMIN_PASSWORD`

The v3 checkout defaults and fail-closed provider/simulation gates are documented in [CHECKOUT-CONTRACT.md](CHECKOUT-CONTRACT.md#configuration-and-provider-gates). `CHECKOUT_HOLD_SECONDS` defaults to 1800 and must equal `PAYMONGO_QR_EXPIRY_SECONDS` for new QR attempts; both values/timestamps are stored independently. `CHECKOUT_SIMULATION_ENABLED` and `CHECKOUT_PROVIDER_FINALITY_ENABLED` default to false. Do not enable them as a substitute for the open provider validation gates.

The default `PAYMONGO_MODE=sandbox` is required. The API refuses to use a production mode. With no PayMongo secret, checkout returns a clearly labeled local sandbox QR practice payload. Product, stock, checkout, and all non-QR flows still run.

For a local request, first sign in at `POST /api/v1/auth/login` with the seeded admin email and password, then send `Authorization: Bearer <token>` with the returned token. The seeded rice product can be looked up with `GET /api/v1/products/barcode/4800000000019`; coffee is low stock and soap is out of stock for catalog and inventory checks.

## Canonical verification

Run the one command used by developers, FirstMate, and CI (both shells):

```text
composer verify
```

Success means Laravel tests pass and Pint reports no formatting changes. The command clears cached config, runs `php artisan test`, then runs `vendor/bin/pint --test`.

## Useful commands

Run only tests, show routes, or reset local data (both shells):

```text
php artisan test
php artisan route:list --path=api
php artisan migrate:fresh --seed
```

The reset deletes and rebuilds the database selected by `DB_CONNECTION`. Use it only for local development. Do not use it against a staging database during a QA cycle. Follow [STAGING.md](STAGING.md) for staging.
