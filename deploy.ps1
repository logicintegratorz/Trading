# Deploy local changes: powershell -ExecutionPolicy Bypass -File .\deploy.ps1
# Check packaging without connecting to the server: .\deploy.ps1 -PackageOnly
# The current UI uses public/*.css and public/app.js. For future Vite changes,
# run npm run build before deploying; public/build is included in the upload.
[CmdletBinding()]
param([switch]$PackageOnly)

$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$sshTarget = 'root@187.127.159.53'
$releaseId = (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
$temporaryDirectory = Join-Path ([IO.Path]::GetTempPath()) "tradeflow-deploy-$releaseId"
New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null

function Invoke-Checked {
    param([string]$Program, [string[]]$Arguments)
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program failed (exit $LASTEXITCODE). Deployment stopped." }
}

foreach ($program in @('tar', 'ssh', 'scp')) {
    if (-not (Get-Command $program -ErrorAction SilentlyContinue)) {
        throw "Required command missing: $program"
    }
}

$archive = Join-Path $temporaryDirectory 'source.tar.gz'
$sourcePaths = @('app', 'bootstrap', 'config', 'database/migrations',
    'database/factories', 'database/seeders', 'public', 'resources', 'routes',
    'artisan', 'composer.json', 'composer.lock', 'package.json', 'vite.config.js')
foreach ($path in $sourcePaths) {
    if (-not (Test-Path (Join-Path $projectRoot $path))) { throw "Source missing: $path" }
}
if (Test-Path (Join-Path $projectRoot 'package-lock.json')) { $sourcePaths += 'package-lock.json' }

# Explicit source list keeps local .env, databases, runtime, and dependencies out.
Invoke-Checked 'tar' (@('-czf', $archive, '--exclude=bootstrap/cache/*',
    '--exclude=public/hot', '--exclude=public/storage', '-C', $projectRoot) + $sourcePaths)
Write-Host "Source package: $archive"

$remoteScript = @'
#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
release_id="$1"
[[ "$release_id" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$ ]] || exit 1
cd /var/www/tradeflow
test "$(pwd -P)" = /var/www/tradeflow
exec 9>.deploy.lock
flock -n 9 || { echo 'Another Trading deployment is running.'; exit 1; }
test -s .env
test -s Dockerfile
test -s compose.yaml
test -s nginx.conf
stage="/var/www/tradeflow/.deploy/$release_id"
backup="/var/backups/tradeflow/$release_id"
mkdir -p "$stage" "$backup"
maintenance_started=0
on_failure() {
    code=$?
    trap - ERR
    echo "Deployment failed. Backup: $backup" >&2
    if [ "$maintenance_started" = 1 ]; then
        echo 'Trading remains in maintenance mode. Review the error before reopening it.' >&2
        echo 'Recovery command: cd /var/www/tradeflow && docker compose -p tradeflow exec -T --user www-data app php artisan up --no-interaction' >&2
    fi
    echo 'No database rollback is attempted automatically.' >&2
    exit "$code"
}
trap on_failure ERR
compose() { docker compose -p tradeflow "$@"; }
sha256sum /etc/nginx/sites-available/grouperp.in /etc/nginx/sites-available/logicstack /etc/nginx/sites-available/new-logicstacks > "$backup/other-sites.sha256"
tar -xzf "/var/www/tradeflow/.deploy/$release_id.tar.gz" -C "$stage"
cp Dockerfile "$stage/Dockerfile"
printf '.env\n.git\nnode_modules\nvendor\n' > "$stage/.dockerignore"

# Build with the known production Dockerfile, not the local development one.
docker build -t "tradeflow-app:$release_id" "$stage"
docker image tag tradeflow-app:latest "tradeflow-app:backup-$release_id"
printf '%s\n' "tradeflow-app:backup-$release_id" > "$backup/image.txt"

code_paths=(app bootstrap config database public resources routes artisan composer.json composer.lock package.json vite.config.js)
if [ -f package-lock.json ]; then code_paths+=(package-lock.json); fi
tar -czf "$backup/code.tar.gz" "${code_paths[@]}"
cp .env compose.yaml Dockerfile nginx.conf "$backup/"
compose exec -T --user www-data app php artisan down --retry=30 --no-interaction
maintenance_started=1
compose exec -T db pg_dump -U tradeflow -d tradeflow --no-owner --no-acl -Fc > "$backup/database.dump"
test -s "$backup/database.dump"

# Replace only application code. Keep server configuration and persistent data.
mkdir "$backup/previous-source"
if [ -f "$stage/package-lock.json" ] && [ ! -f package-lock.json ]; then code_paths+=(package-lock.json); fi
for path in "${code_paths[@]}"; do
    if [ -e "$path" ]; then mv -- "$path" "$backup/previous-source/$path"; fi
    if [ -e "$stage/$path" ]; then cp -a -- "$stage/$path" "$path"; fi
done
docker image tag "tradeflow-app:$release_id" tradeflow-app:latest
compose up -d --no-deps --no-build --force-recreate app web
compose exec -T app php artisan migrate --force --no-interaction
compose exec -T --user www-data app php artisan optimize --no-interaction
compose exec -T web nginx -t
compose exec -T --user www-data app php artisan up --no-interaction
maintenance_started=0

for path in /up / /inventory /saudas /purchase-orders /sale-orders /reports /app.css /app.js; do
    curl --fail --silent --show-error --retry 5 --retry-all-errors --retry-delay 2 \
        --max-time 20 -o /dev/null "https://trading.logicintegratorz.cloud$path"
    printf 'OK %s\n' "$path"
done
sha256sum -c "$backup/other-sites.sha256"
printf '\nDeployed: https://trading.logicintegratorz.cloud\nBackup: %s\n' "$backup"
echo 'Backups and release images are retained; no other projects are restarted.'
'@
$scriptFile = Join-Path $temporaryDirectory 'deploy-remote.sh'
[IO.File]::WriteAllText($scriptFile, $remoteScript.Replace("`r`n", "`n"), [Text.UTF8Encoding]::new($false))

if ($PackageOnly) {
    Write-Host "Packaging verified. Server unchanged. Remote script: $scriptFile"
    return
}

Invoke-Checked 'ssh' @('-o', 'ConnectTimeout=15', $sshTarget, 'mkdir -p /var/www/tradeflow/.deploy && chmod 700 /var/www/tradeflow/.deploy')
Invoke-Checked 'scp' @($archive, "${sshTarget}:/var/www/tradeflow/.deploy/$releaseId.tar.gz")
Invoke-Checked 'scp' @($scriptFile, "${sshTarget}:/var/www/tradeflow/.deploy/$releaseId.sh")
Invoke-Checked 'ssh' @('-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4', $sshTarget,
    "bash -n /var/www/tradeflow/.deploy/$releaseId.sh && bash /var/www/tradeflow/.deploy/$releaseId.sh $releaseId")
Write-Host 'Trading deployment completed successfully.'
