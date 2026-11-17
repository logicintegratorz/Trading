# Deploy local changes: powershell -ExecutionPolicy Bypass -File .\deploy.ps1
# Check packaging without connecting to the server: .\deploy.ps1 -PackageOnly
# The current UI uses public/*.css and public/app.js. For future Vite changes,
# run npm run build before deploying; public/build is included in the upload.
[CmdletBinding()]
param(
    [switch]$PackageOnly,
    # Prints the remote deployment steps when diagnosing a failed deployment.
    # It does not expose the server password or application secrets.
    [switch]$DebugRemote
)

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
    'artisan', 'composer.json', 'composer.lock', 'package.json', 'vite.config.js',
    'Dockerfile', 'compose.yaml')
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
[[ "$release_id" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$ ]] || { echo 'Invalid release identifier.' >&2; exit 1; }
cd /var/www/tradeflow || { echo 'Deployment directory /var/www/tradeflow is missing.' >&2; exit 1; }
test "$(pwd -P)" = /var/www/tradeflow || { echo 'Unexpected deployment directory.' >&2; exit 1; }
exec 9>.deploy.lock
flock -n 9 || { echo 'Another Trading deployment is running.'; exit 1; }
for required_file in .env Dockerfile compose.yaml; do
    [[ -s "$required_file" ]] || { echo "Required server file is missing or empty: $required_file" >&2; exit 1; }
done
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
tar -xzf "/var/www/tradeflow/.deploy/$release_id.tar.gz" -C "$stage"
printf '.env\n.git\nnode_modules\nvendor\n' > "$stage/.dockerignore"

# Build with the Dockerfile included in this release. Keeping an old server
# Dockerfile would leave the old PHP-FPM/web setup active after an upgrade.
# Keep the SSH session readable. Docker's default output includes hundreds of
# compiler lines while PHP extensions are built; quiet mode still returns any
# genuine build failure but prints the image id only after a successful build.
docker build --quiet -t "tradeflow-app:$release_id" "$stage"
if docker image inspect tradeflow-app:latest >/dev/null 2>&1; then
    docker image tag tradeflow-app:latest "tradeflow-app:backup-$release_id"
    printf '%s\n' "tradeflow-app:backup-$release_id" > "$backup/image.txt"
else
    printf '%s\n' 'No earlier tradeflow-app:latest image was present.' > "$backup/image.txt"
fi

code_paths=(app bootstrap config database public resources routes artisan composer.json composer.lock package.json vite.config.js Dockerfile compose.yaml)
if [ -f package-lock.json ]; then code_paths+=(package-lock.json); fi
# A legacy server may not yet have newly added files (for example nginx.conf).
# Archive only paths that already exist; the staged release supplies missing ones.
backup_paths=()
for path in "${code_paths[@]}"; do
    if [ -e "$path" ]; then backup_paths+=("$path"); fi
done
tar -czf "$backup/code.tar.gz" "${backup_paths[@]}"
cp .env compose.yaml Dockerfile "$backup/"
# Repair permissions on both legacy and current images before Artisan writes
# the maintenance marker or cache files. This also lets a deployment recover
# from an earlier release that created these paths as root.
compose exec -T app sh -lc 'mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && chown -R www-data:www-data bootstrap/cache storage'
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

# The Laravel container receives HTTP from the host proxy, but all public URLs
# and cookies must use the public HTTPS origin. Set this before recreating the
# app container so its environment has the corrected values immediately.
domain='trading.logicintegratorz.cloud'
if grep -q '^APP_URL=' .env; then
    sed -i "s|^APP_URL=.*|APP_URL=https://$domain|" .env
else
    printf '\nAPP_URL=https://%s\n' "$domain" >> .env
fi
if grep -q '^SESSION_SECURE_COOKIE=' .env; then
    sed -i 's/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=true/' .env
else
    printf 'SESSION_SECURE_COOKIE=true\n' >> .env
fi

docker image tag "tradeflow-app:$release_id" tradeflow-app:latest
# The current Compose file exposes the Laravel app directly on port 8000.
# There is no `web` service; the host Nginx is the only public reverse proxy.
# Remove only stale services within the TradeFlow compose project (for example
# the former `web` service). Other projects on the server are untouched.
compose up -d --remove-orphans --no-deps --no-build --force-recreate app
compose exec -T app sh -lc 'mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && chown -R www-data:www-data bootstrap/cache storage'
compose exec -T app php artisan migrate --force --no-interaction
compose exec -T --user www-data app php artisan optimize --no-interaction
compose exec -T --user www-data app php artisan up --no-interaction
maintenance_started=0

# Configure only TradeFlow's host-level reverse proxy. The port is obtained
# from Docker Compose so it stays correct even if the published port changes.
app_binding="$(compose port app 8000 | head -n 1 || true)"
app_port="${app_binding##*:}"
if [[ "$app_port" =~ ^[0-9]+$ ]]; then
    host_site="/etc/nginx/sites-available/$domain"
    if [ -f "$host_site" ]; then cp -a "$host_site" "$backup/$domain.nginx.conf"; fi
    # Make the HTTP site available first so Certbot can complete the ACME
    # challenge. The 00 prefix gives this exact domain vhost precedence over
    # any legacy/default project configuration on the same server.
    cat > "$host_site" <<NGINX_SITE
server {
    listen 80;
    listen [::]:80;
    server_name $domain;

    location / {
        proxy_pass http://127.0.0.1:$app_port;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX_SITE
    rm -f "/etc/nginx/sites-enabled/$domain"
    ln -sfn "$host_site" "/etc/nginx/sites-enabled/00-$domain"
    nginx -t && systemctl reload nginx

    # Do not report success unless the host-level virtual host really routes
    # this domain to the Laravel application. A generic Nginx 404 means the
    # virtual host is not active and is never a successful deployment.
    curl -fsSI --max-time 15 "http://127.0.0.1:$app_port/login" >/dev/null
    curl -fsSI --max-time 15 -H "Host: $domain" http://127.0.0.1/login >/dev/null

    command -v certbot >/dev/null 2>&1 || { echo 'Certbot is required to configure Trading HTTPS.' >&2; exit 1; }
    # Obtain/retain the certificate only. Do not let Certbot edit an arbitrary
    # legacy Nginx vhost, which can leave this domain serving another project.
    certbot certonly --nginx --non-interactive --agree-tos --register-unsafely-without-email --keep-until-expiring -d "$domain"
    test -s "/etc/letsencrypt/live/$domain/fullchain.pem"
    test -s "/etc/letsencrypt/live/$domain/privkey.pem"

    # Explicitly bind both the certificate and the proxy to TradeFlow. This
    # removes the old Group ERP/default-site fallback for this hostname.
    cat > "$host_site" <<NGINX_SITE
server {
    listen 80;
    listen [::]:80;
    server_name $domain;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $domain;

    ssl_certificate /etc/letsencrypt/live/$domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:$app_port;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
NGINX_SITE
    nginx -t && systemctl reload nginx
    openssl s_client -connect 127.0.0.1:443 -servername "$domain" </dev/null 2>/dev/null \
        | openssl x509 -noout -ext subjectAltName | grep -F "DNS:$domain" >/dev/null
    curl -fsSI --max-time 15 --resolve "$domain:443:127.0.0.1" "https://$domain/login" >/dev/null
else
    echo "WARNING: App deployed, but the Docker app port could not be determined from: $app_binding" >&2
fi

# The public TLS certificate belongs to the host-level proxy, not this app
# container. It can be renewed independently, so a certificate mismatch must
# not roll back an otherwise healthy application deployment.
echo 'Application container, routes, views, migrations, and Nginx configuration verified.'
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
$remoteCommand = "bash -n /var/www/tradeflow/.deploy/$releaseId.sh && "
$remoteCommand += if ($DebugRemote) {
    "bash -x /var/www/tradeflow/.deploy/$releaseId.sh $releaseId"
} else {
    "bash /var/www/tradeflow/.deploy/$releaseId.sh $releaseId"
}
Invoke-Checked 'ssh' @('-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4', $sshTarget, $remoteCommand)
Write-Host 'Trading deployment completed successfully.'
