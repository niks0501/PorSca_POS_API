# PorSca API

## What this is

This is the PorSca point-of-sale API. It is the source of truth for products, stock, durable checkouts, sales, payments, reconciliation, and payment events.

The API is a Laravel app. It uses PHP and MySQL for local development. A local QR Ph practice fixture is available without provider keys; see the [checkout contract](docs/CHECKOUT-CONTRACT.md) for its limits.

## What you need first

- PHP 8.3 or newer
- Composer
- MySQL
- A terminal
- A phone or Expo app only if you want to run the mobile app too

Check PHP and Composer:

```sh
php -v
composer --version
```

Success looks like PHP 8.3+ and a Composer version.

## Run it

Commands below are shown for both Windows PowerShell and Linux/WSL2 Bash. The API has no public registration, so set the first admin in `.env` before seeding:

```dotenv
ADMIN_NAME="Store Admin"
ADMIN_EMAIL=admin@example.test
ADMIN_PASSWORD=<choose-a-strong-password>
```

1. Install the PHP packages:

   ```sh
   composer install
   ```

   Success: Composer ends with `Generating optimized autoload files`.

2. Create the local MySQL database, then copy the settings and generate the app key. The examples use database `PorSca_POS` and user `root` with an empty password. Create the database as a MySQL administrator:

   Connect as a MySQL administrator in either shell:

   ```text
   mysql -u root -p
   ```

   At the resulting MySQL prompt, run:

   ```sql
   CREATE DATABASE IF NOT EXISTS PorSca_POS CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON PorSca_POS.* TO 'root'@'localhost';
   ```

   Copy `.env.example` and generate the app key:

   PowerShell: `Copy-Item .env.example .env`, then `php artisan key:generate`.

   Bash:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Success: MySQL creates the database and grants access without an error. Laravel says `Application key set successfully`. The `.env` defaults match this local setup. If the root account has a password, set `DB_PASSWORD` in `.env` before continuing.

3. Prepare the local database and sample products:

   ```sh
   php artisan migrate:fresh --seed
   ```

   Success: Laravel reports that the migrations and seeding finished.

4. Start the API:

   ```sh
   php artisan serve --host=0.0.0.0 --port=8000
   ```

   Success: Laravel prints a local URL, usually `http://127.0.0.1:8000`.

5. Open the health page:

   ```text
   http://127.0.0.1:8000/api/v1/health
   ```

   Success: the page shows JSON with `"status":"ok"`.

## Run both together

Start the API first. Keep it running. Find the computer's network address, such as `192.168.1.20`, then start the Expo app from the mobile repository:

Find the LAN IPv4 address:

```powershell
Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -ExpandProperty IPAddress
```

```bash
hostname -I
```

PowerShell:

```powershell
$env:EXPO_PUBLIC_API_URL = 'http://192.168.1.20:8000/api/v1'; npx expo start
```

Bash:

```bash
EXPO_PUBLIC_API_URL=http://192.168.1.20:8000/api/v1 npx expo start
```

Success: the app opens and its API requests use the computer's address. A phone and the computer must be on the same network. A phone cannot use `localhost` to reach the computer.

## Windows 11 native Wi-Fi LAN development

Use these steps when Laravel runs directly on Windows 11 and a phone connects over Wi-Fi. This setup needs no WSL, Hyper-V, proxy, or ADB.

1. Connect the PC and phone to the same Wi-Fi. Set the PC's Wi-Fi network to **Private** in Windows Settings. Check the profile in PowerShell:

   ```powershell
   Get-NetConnectionProfile
   ```

   Success: the connected Wi-Fi profile shows `NetworkCategory : Private`. If this is a trusted network but shows `Public`, run PowerShell as Administrator (replace `Wi-Fi` if the interface has a different name), then check again:

   ```powershell
   Set-NetConnectionProfile -InterfaceAlias "Wi-Fi" -NetworkCategory Private
   ```

   Success: `Get-NetConnectionProfile` shows `Private` for the Wi-Fi connection.

2. Find the PC's Wi-Fi IPv4 address:

   ```powershell
   Get-NetIPConfiguration
   ```

   Success: the connected Wi-Fi adapter shows an `IPv4Address`. Use this address for the phone; do not use `localhost` on the phone.

3. In PowerShell opened as Administrator, allow inbound TCP ports `8000` for the API and `8081` for Metro through Windows Firewall on the Private profile only:

   ```powershell
   New-NetFirewallRule -DisplayName "PorSca POS development - API and Metro" -Direction Inbound -Action Allow -Enabled True -Protocol TCP -LocalPort 8000,8081 -Profile Private
   ```

   Success: the rule is enabled for inbound traffic on `Private` only. `Public` is not selected.

4. Start the API and leave this PowerShell window open:

   ```powershell
   php artisan serve --host=0.0.0.0 --port=8000
   ```

   Success: Laravel says the server is running on port `8000`.

5. In another PowerShell window on the PC, check the API health endpoint:

   ```powershell
   curl.exe http://localhost:8000/api/v1/health
   ```

   Success: the response contains `"status":"ok"`. The real health path is `/api/v1/health`.

For the phone-side setup, follow the [mobile repo's Windows 11 Wi-Fi LAN section](https://github.com/alfredc-12/PorSca_POS/blob/fm/porsca-pos-win11-lan-docs-20261001/README.md#windows-11-native-wi-fi-lan-development).

## Check it works

Check health:

```text
curl.exe http://127.0.0.1:8000/api/v1/health
```

Success: the response contains `"status":"ok"`.

Sign in first, then list the seeded products with the returned token:

```sh
curl -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.test","password":"<your-ADMIN_PASSWORD>"}'

curl -H 'Authorization: Bearer <returned-token>' http://127.0.0.1:8000/api/v1/products
```

Success: the response contains a `data.items` list with products such as `Sinandomeng Rice 5kg`. Add `?search=coffee` for name search or call `/api/v1/products/barcode/4800000000019` for the seeded rice barcode lookup. Product responses include a consistent stock status (`in_stock`, `low_stock`, or `out_of_stock`).

## If something goes wrong

- **`composer` is not found:** install Composer, open a new terminal, and run `composer --version` again.
- **The health page says the database is unavailable:** check that MySQL is running and `.env` has the right database name, user, password, host, and port; then run `php artisan migrate:fresh --seed` and restart `php artisan serve --host=0.0.0.0 --port=8000`.
- **Products return `401`:** sign in at `POST /api/v1/auth/login` and send the returned token as `Authorization: Bearer <token>`. Tokens expire after `SANCTUM_EXPIRATION` minutes (default 30 days).
- **A phone cannot connect:** use the computer's network address instead of `localhost`, and allow port 8000 through the local firewall.

## Learn more

- [Setup and local commands](docs/SETUP.md)
- [API architecture and mobile contract](docs/ARCHITECTURE.md)
- [Testing and Postman](docs/TESTING.md)
- [Staging guide](docs/STAGING.md)
- [Formal QA cycle](docs/QA-CYCLE.md)
- [Laravel documentation](https://laravel.com/docs)
