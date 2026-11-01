# PostgreSQL setup and data transfer

Tradeflow now uses PostgreSQL as its default database. Laravel migrations and application queries are PostgreSQL-compatible.

## 1. Create the database

Install PostgreSQL 16 or newer, then create an empty database named `tradeflow` and a PostgreSQL user with permission to create and modify tables in it.

## 2. Transfer the existing data safely

Keep the current source database active in `.env` and add the target credentials:

```dotenv
PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DATABASE=tradeflow
PGSQL_USERNAME=postgres
PGSQL_PASSWORD=your-password
PGSQL_SSLMODE=prefer
```

Run:

```powershell
.\.runtime\php.exe artisan db:move-to-postgres
```

The command creates the PostgreSQL schema, refuses to overwrite non-empty target tables, copies the complete business dataset, updates PostgreSQL sequences, and verifies every row count. It never modifies or deletes the source database.

## 3. Switch the application

After successful verification, change `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=tradeflow
DB_USERNAME=postgres
DB_PASSWORD=your-password
DB_SSLMODE=prefer
```

Then clear cached configuration:

```powershell
.\.runtime\php.exe artisan optimize:clear
```

The old SQLite database should be retained as a rollback backup until client acceptance testing is complete.
