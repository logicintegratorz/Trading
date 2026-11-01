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
compose exec -T --user www-data app php artisan down --retry=30 --no-interaction
trap 'compose exec -T --user www-data app php artisan up --no-interaction' EXIT
compose exec -T db pg_dump -U tradeflow -d tradeflow --no-owner --no-acl -Fc > "$backup/database.dump"
test -s "$backup/database.dump"
compose exec -T db pg_restore --list < "$backup/database.dump" > "$backup/contents.txt"

# Keep the schema and migration history. A single transaction clears records
# and restarts IDs; new tables require this explicit list to be updated.
compose exec -T db psql -v ON_ERROR_STOP=1 -U tradeflow -d tradeflow <<'SQL'
BEGIN;
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_tables WHERE schemaname = 'public'
        AND tablename NOT IN ('migrations', 'cache', 'cache_locks', 'failed_jobs',
            'group_heads', 'job_batches', 'jobs', 'order_items', 'parties',
            'password_reset_tokens', 'products', 'records', 'saudas', 'sessions', 'users')
    ) THEN
        RAISE EXCEPTION 'Unexpected Trading tables: update the reset script before clearing data.';
    END IF;
END $$;
TRUNCATE TABLE public.cache, public.cache_locks, public.failed_jobs,
public.group_heads, public.job_batches, public.jobs, public.order_items,
public.parties, public.password_reset_tokens, public.products,
public.records, public.saudas, public.sessions, public.users RESTART IDENTITY;
COMMIT;
SELECT 'group_heads' AS table_name, count(*) AS rows FROM group_heads
UNION ALL SELECT 'parties', count(*) FROM parties
UNION ALL SELECT 'products', count(*) FROM products
UNION ALL SELECT 'records', count(*) FROM records
UNION ALL SELECT 'saudas', count(*) FROM saudas
UNION ALL SELECT 'order_items', count(*) FROM order_items
UNION ALL SELECT 'users', count(*) FROM users;
SQL
compose exec -T --user www-data app php artisan up --no-interaction
trap - EXIT
echo "Live Trading records cleared. Backup: $backup/database.dump"
curl --fail --silent --show-error --retry 3 --retry-delay 2 --max-time 20 \
    -o /dev/null https://trading.logicintegratorz.cloud/
echo 'Trading HTTPS is responding. Local data and other projects were not changed.'
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
