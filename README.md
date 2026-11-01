# Tradeflow

A Laravel 13 application using Blade for the frontend, PHP controllers for business logic, and PostgreSQL for persistent storage.

## Included

- Responsive dashboard with current-month totals, inventory value, open orders, six-month performance, recent activity, and low-stock alerts.
- Masters, Inventory, Sauda, Purchase, Sale, Dispatch, Purchase Orders, Sale Orders, and Reports modules.
- Searchable, paginated registers and validated forms with CSRF protection.
- Purchases increase stock and update weighted average cost.
- Sales lock the product row and reject insufficient stock.
- Purchases add inventory and sales create dispatch entries that reduce inventory.
- Amounts are stored as integer paise and displayed in INR.

## Local setup

Requirements: PHP 8.3+, Composer 2, PostgreSQL 16+, and the `pdo_pgsql` PHP extension. Create a `tradeflow` PostgreSQL database and a dedicated database user. Then run:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Update the PostgreSQL credentials in `.env` before migration. Open http://127.0.0.1:8000.

For a safe transfer from the existing SQLite installation, follow [POSTGRESQL.md](POSTGRESQL.md).

Optional illustrative data:

```powershell
php artisan db:seed
```

## Docker setup

```powershell
Copy-Item .env.docker.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Open http://127.0.0.1:8000. Run `docker compose exec app php artisan db:seed` for optional sample data.

## Tests

Feature tests use an in-memory SQLite database and never touch the PostgreSQL database configured in `.env`:

```powershell
php artisan test
```

Tests cover every page, exact money storage, purchase costing, sales stock changes, insufficient-stock rollback, order isolation, validation, and search scoping.

## Current scope

Entries contain one product each. Authentication, roles, tax invoices, payments, and returns are suitable next phases. Add authentication before exposing client business data publicly.
