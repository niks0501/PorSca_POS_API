# Testing and Postman

## Canonical API check

From the repository root run:

```sh
composer verify
```

Success means all Laravel feature and unit tests pass, followed by a clean Pint check. CI runs this same command in `.github/workflows/api.yml`.

The Laravel tests cover:

- public health and bearer-token protection
- product and stock validation
- checkout amount and payment persistence
- repeated idempotency keys
- atomic paid settlement and stock deduction
- failed and cancelled payments with no sale or deduction
- signed and repeated webhooks
- sandbox-only PayMongo behavior

## Postman collection

The collection runs the HTTP contract in order: health, products, checkout, payment read, webhook settlement, payment read again, sales, and transactions.

Required tools:

```sh
node --version
npx newman --version
```

Success: Node and Newman each print a version.

Prepare a local webhook verification value. This is not a PayMongo payment key. It stays in the ignored `.env` file:

```sh
WEBHOOK_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')"
sed -i "s/^PAYMONGO_WEBHOOK_SECRET=.*/PAYMONGO_WEBHOOK_SECRET=$WEBHOOK_SECRET/" .env
export PAYMONGO_WEBHOOK_SECRET="$WEBHOOK_SECRET"
php artisan config:clear
```

Success: `php artisan config:clear` says the configuration cache was cleared and `.env` has a new local-only webhook value.

Start the API in another terminal and run:

```sh
npx newman run postman/PorSca-API.postman_collection.json \
  -e postman/PorSca-API.postman_environment.json \
  --env-var webhook_secret="$PAYMONGO_WEBHOOK_SECRET"
```

Success: Newman reports 8 requests and all assertions passing. The seeded database must be fresh before this run because the collection buys one seeded product and reduces its stock.

For staging, copy the environment file to a file outside git and override `base_url`, `api_token`, and `webhook_secret` from the staging machine's environment. Never commit the copy:

```sh
cp postman/PorSca-API.postman_environment.json /tmp/porsca-staging.postman_environment.json
npx newman run postman/PorSca-API.postman_collection.json \
  -e /tmp/porsca-staging.postman_environment.json \
  --env-var base_url=https://<stable-api-host>/api/v1 \
  --env-var api_token="$API_TOKEN" \
  --env-var webhook_secret="$PAYMONGO_WEBHOOK_SECRET"
```

Success: every request and assertion passes against the same stable staging URL. Run this only with the cycle's approved data. Do not reset staging during the run.

## Evidence

For each formal QA cycle, retain:

- the `composer verify` output or CI run URL
- the Newman text or JSON report
- the collection and environment version used
- screenshots or response bodies for any payment and webhook check

Keep evidence with the cycle record. Do not retain API tokens, PayMongo secret keys, webhook secrets, or raw authorization headers.
