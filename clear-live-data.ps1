# Clears LIVE Trading records after saving a server-side database backup.
# Run from Trading: powershell -ExecutionPolicy Bypass -File .\clear-live-data.ps1
# Syntax check only (does not delete data): add -ValidateOnly
[CmdletBinding()]
param([switch]$ValidateOnly)

$ErrorActionPreference = 'Stop'
$sshTarget = 'root@187.127.159.53'
foreach ($program in @('ssh', 'scp')) {
    if (-not (Get-Command $program -ErrorAction SilentlyContinue)) {
        throw "Required command missing: $program"
    }
}

function Invoke-Checked {
    param([string]$Program, [string[]]$Arguments)
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program failed (exit $LASTEXITCODE). Stopped." }
}

$remoteScript = @'
#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd /var/www/tradeflow
test "$(pwd -P)" = /var/www/tradeflow
exec 9>.deploy.lock
flock -n 9 || { echo 'Another Trading deployment or reset is running.'; exit 1; }
compose() { docker compose -p tradeflow "$@"; }
test -s .env
test -s compose.yaml
mkdir -p /var/backups/tradeflow
backup=$(mktemp -d /var/backups/tradeflow/before-clear-$(date -u +%Y%m%d-%H%M%S)-XXXXXX)
echo "Backup directory: $backup"
# Recover writable Laravel runtime paths even if a previous deployment stopped
# while optimizing the application.
compose exec -T app sh -lc 'mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && chown -R www-data:www-data bootstrap/cache storage'
compose exec -T --user www-data app php artisan down --retry=30 --no-interaction
trap 'compose exec -T --user www-data app php artisan up --no-interaction' EXIT
compose exec -T db pg_dump -U tradeflow -d tradeflow --no-owner --no-acl -Fc > "$backup/database.dump"
test -s "$backup/database.dump"
compose exec -T db pg_restore --list < "$backup/database.dump" > "$backup/contents.txt"

# Preserve inventory products (including their current stock and rates), product
# groups, users, schema, and migration history. Clear transactional/workflow
# data in one transaction and restart only the cleared tables' identities.
compose exec -T db psql -v ON_ERROR_STOP=1 -U tradeflow -d tradeflow <<'SQL'
BEGIN;
CREATE TEMP TABLE inventory_before_clear AS
SELECT count(*) AS product_count,
       COALESCE(sum(stock), 0) AS stock_total,
       COALESCE(sum(stock * cost_cents), 0) AS stock_value
FROM public.products;
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_tables WHERE schemaname = 'public'
        AND tablename NOT IN ('migrations', 'cache', 'cache_locks',
            'daily_voucher_sequences', 'failed_jobs', 'gate_entries',
            'group_heads', 'job_batches', 'jobs', 'order_items', 'parties',
            'password_reset_tokens', 'products', 'records', 'sale_voucher_items',
            'sale_vouchers', 'saudas', 'sessions', 'users')
    ) THEN
        RAISE EXCEPTION 'Unexpected Trading tables: update the reset script before clearing data.';
    END IF;
END $$;
TRUNCATE TABLE public.cache, public.cache_locks,
public.daily_voucher_sequences, public.failed_jobs, public.gate_entries,
public.job_batches, public.jobs, public.order_items, public.parties,
public.password_reset_tokens, public.records, public.sale_voucher_items,
public.sale_vouchers, public.saudas, public.sessions RESTART IDENTITY;
DO $$
DECLARE
    before_row record;
    after_row record;
BEGIN
    SELECT * INTO before_row FROM inventory_before_clear;
    SELECT count(*) AS product_count,
           COALESCE(sum(stock), 0) AS stock_total,
           COALESCE(sum(stock * cost_cents), 0) AS stock_value
      INTO after_row
      FROM public.products;
    IF before_row.product_count IS DISTINCT FROM after_row.product_count
       OR before_row.stock_total IS DISTINCT FROM after_row.stock_total
       OR before_row.stock_value IS DISTINCT FROM after_row.stock_value THEN
        RAISE EXCEPTION 'Inventory verification failed; transaction will be rolled back.';
    END IF;
END $$;
COMMIT;
SELECT 'products_preserved' AS table_name, count(*) AS rows FROM products
UNION ALL SELECT 'group_heads_preserved', count(*) FROM group_heads
UNION ALL SELECT 'users_preserved', count(*) FROM users
UNION ALL SELECT 'parties_cleared', count(*) FROM parties
UNION ALL SELECT 'records', count(*) FROM records
UNION ALL SELECT 'saudas', count(*) FROM saudas
UNION ALL SELECT 'order_items', count(*) FROM order_items
UNION ALL SELECT 'sale_vouchers', count(*) FROM sale_vouchers
UNION ALL SELECT 'sale_voucher_items', count(*) FROM sale_voucher_items
UNION ALL SELECT 'gate_entries', count(*) FROM gate_entries;
SQL
compose exec -T --user www-data app php artisan up --no-interaction
trap - EXIT
echo "Live Trading records cleared. Backup: $backup/database.dump"
compose exec -T app php artisan about --only=environment >/dev/null
echo 'Inventory, product groups, users, schema, and migrations were preserved. Local data and other projects were not changed.'
'@

$scriptId = [guid]::NewGuid().ToString('N')
$localFile = Join-Path ([IO.Path]::GetTempPath()) "tradeflow-clear-$scriptId.sh"
$remoteFile = "/var/www/tradeflow/.clear-live-$scriptId.sh"
[IO.File]::WriteAllText($localFile, $remoteScript.Replace("`r`n", "`n"), [Text.UTF8Encoding]::new($false))
Invoke-Checked 'scp' @($localFile, "${sshTarget}:$remoteFile")

if ($ValidateOnly) {
    Invoke-Checked 'ssh' @('-o', 'ConnectTimeout=15', $sshTarget, "bash -n $remoteFile")
    Write-Host 'Shell syntax valid. No data was deleted.'
    return
}

Write-Host 'Backing up and clearing LIVE Trading data...'
Invoke-Checked 'ssh' @('-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', $sshTarget,
    "bash -n $remoteFile && bash $remoteFile")
