# PorSca API

## What this is

This is the PorSca point-of-sale API. It is the source of truth for products, stock, sales, payments, and payment events.

The API is a Laravel app. It uses PHP and SQLite on a local machine. QR Ph practice payments work without payment keys.

## What you need first

- PHP 8.3 or newer
- Composer
- A terminal
- A phone or Expo app only if you want to run the mobile app too

Check PHP and Composer:

```sh
php -v
composer --version
```

Success looks like PHP 8.3+ and a Composer version.

## Run it

1. Install the PHP packages:

   ```sh
   composer install
   ```

   Success: Composer ends with `Generating optimized autoload files`.

2. Copy the local settings:

   ```sh
   cp .env.example .env
   php artisan key:generate
   ```

   Success: Laravel says `Application key set successfully`.

3. Prepare the local database and sample products:

   ```sh
   php artisan migrate:fresh --seed
   ```

   Success: Laravel reports that the migrations and seeding finished.

4. Start the API:

   ```sh
   php artisan serve
   ```

   Success: Laravel prints a local URL, usually `http://127.0.0.1:8000`.

5. Open the health page:

   ```text
   http://127.0.0.1:8000/api/v1/health
   ```

   Success: the page shows JSON with `"status":"ok"`.

## Run both together

Start the API first. Keep it running. Find the computer's network address, such as `192.168.1.20`, then start the Expo app from the mobile repository:

```sh
EXPO_PUBLIC_API_BASE_URL=http://192.168.1.20:8000/api/v1 npx expo start
```

Success: the app opens and its API requests use the computer's address. A phone and the computer must be on the same network. A phone cannot use `localhost` to reach the computer.

## Check it works

Check health:

```sh
curl http://127.0.0.1:8000/api/v1/health
```

Success: the response contains `"status":"ok"`.

List the seeded products:

```sh
curl -H 'Authorization: Bearer local-api-token' http://127.0.0.1:8000/api/v1/products
```

Success: the response contains a `data.items` list with products such as `Sinandomeng Rice 5kg`.

## If something goes wrong

- **`composer` is not found:** install Composer, open a new terminal, and run `composer --version` again.
- **The health page says the database is unavailable:** run `php artisan migrate:fresh --seed`, then restart `php artisan serve`.
- **Products return `401`:** use `Authorization: Bearer local-api-token`, or set the same value in `API_TOKEN` in `.env`.
- **A phone cannot connect:** use the computer's network address instead of `localhost`, and allow port 8000 through the local firewall.

## Learn more

- [Setup and local commands](docs/SETUP.md)
- [API architecture and mobile contract](docs/ARCHITECTURE.md)
- [Testing and Postman](docs/TESTING.md)
- [Staging guide](docs/STAGING.md)
- [Formal QA cycle](docs/QA-CYCLE.md)
- [Laravel documentation](https://laravel.com/docs)
