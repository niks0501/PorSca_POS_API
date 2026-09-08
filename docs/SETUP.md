# Local setup

## Requirements

Use PHP 8.3+, Composer, and SQLite. MySQL or PostgreSQL can be used by changing Laravel's normal `DB_*` settings.

## Install

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
```

Success looks like completed migrations and four seeded products, including in-stock, low-stock, and out-of-stock scenarios.

Start the server:

```sh
php artisan serve --host=0.0.0.0 --port=8000
```

Success looks like a server listening on port 8000. Check it:

```sh
curl http://127.0.0.1:8000/api/v1/health
```

The response must contain `"status":"ok"`.

## Settings

Copy `.env.example` before local work. `.env` is ignored by git. Never put these values in source, Postman collections, screenshots, or mobile code:

- `PAYMONGO_SECRET_KEY`
- `PAYMONGO_WEBHOOK_SECRET`
- `API_TOKEN`

The default `PAYMONGO_MODE=sandbox` is required. The API refuses to use a production mode. With no PayMongo secret, checkout returns a clearly labeled local sandbox QR practice payload. Product, stock, checkout, and all non-QR flows still run.

For a local request, send:

```http
Authorization: Bearer local-api-token
```

Replace the token when `API_TOKEN` has another value. The seeded rice product can be looked up with `GET /api/v1/products/barcode/4800000000010`; coffee is low stock and soap is out of stock for catalog and inventory checks.

## Canonical verification

Run the one command used by developers, FirstMate, and CI:

```sh
composer verify
```

Success means Laravel tests pass and Pint reports no formatting changes. The command clears cached config, runs `php artisan test`, then runs `vendor/bin/pint --test`.

## Useful commands

Run only tests:

```sh
php artisan test
```

Show routes:

```sh
php artisan route:list --path=api
```

Reset local data to the synthetic baseline:

```sh
php artisan migrate:fresh --seed
```

Do not use `migrate:fresh` against a staging database during a QA cycle. Use the staging process in [STAGING.md](STAGING.md).
