# Repairs only the Trading HTTPS Nginx virtual host. It does not deploy code
# and does not modify the Tradeflow database, Docker volumes, or other sites.
# Run: powershell -ExecutionPolicy Bypass -File .\repair-trading-nginx.ps1

$ErrorActionPreference = 'Stop'
$sshTarget = 'root@187.127.159.53'

foreach ($program in @('ssh')) {
    if (-not (Get-Command $program -ErrorAction SilentlyContinue)) {
        throw "Required command missing: $program"
    }
}

function Invoke-Checked {
    param([string]$Program, [string[]]$Arguments)
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program failed (exit $LASTEXITCODE). Repair stopped." }
}

$domain = 'trading.logicintegratorz.cloud'
$scriptId = [guid]::NewGuid().ToString('N')
$localFile = Join-Path ([IO.Path]::GetTempPath()) "tradeflow-nginx-repair-$scriptId.sh"
# Use the deployment staging directory, which is already provisioned with the
# correct remote permissions. Direct hidden files at the project root can be
# rejected or normalized by some SCP/SFTP server configurations.
$remoteFile = "/var/www/tradeflow/.deploy/nginx-repair-$scriptId.sh"

$remoteScript = @'
#!/usr/bin/env bash
set -Eeuo pipefail
domain='trading.logicintegratorz.cloud'
cd /var/www/tradeflow
test "$(pwd -P)" = /var/www/tradeflow
compose() { docker compose -p tradeflow "$@"; }

cert_dir="/etc/letsencrypt/live/$domain"
test -s "$cert_dir/fullchain.pem"
test -s "$cert_dir/privkey.pem"

app_binding="$(compose port app 8000 | head -n 1 || true)"
app_port="${app_binding##*:}"
[[ "$app_port" =~ ^[0-9]+$ ]] || { echo "Unable to determine Tradeflow app port: $app_binding" >&2; exit 1; }
curl -fsSI --max-time 15 "http://127.0.0.1:$app_port/login" >/dev/null

site="/etc/nginx/sites-available/$domain"
backup_dir="/var/backups/tradeflow/nginx-repair-$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
if [ -e "$site" ]; then cp -a "$site" "$backup_dir/$domain"; fi

cat > "$site" <<NGINX
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

    ssl_certificate $cert_dir/fullchain.pem;
    ssl_certificate_key $cert_dir/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:$app_port;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
NGINX

rm -f "/etc/nginx/sites-enabled/$domain"
ln -sfn "$site" "/etc/nginx/sites-enabled/00-$domain"
nginx -t
systemctl reload nginx

openssl s_client -connect 127.0.0.1:443 -servername "$domain" </dev/null 2>/dev/null \
    | openssl x509 -noout -ext subjectAltName | grep -F "DNS:$domain" >/dev/null
curl -fsSI --max-time 15 --resolve "$domain:443:127.0.0.1" "https://$domain/login" >/dev/null
echo "Trading HTTPS repaired successfully. Backup: $backup_dir"
'@

[IO.File]::WriteAllText($localFile, $remoteScript.Replace("`r`n", "`n"), [Text.UTF8Encoding]::new($false))
Invoke-Checked 'ssh' @('-o', 'ConnectTimeout=15', $sshTarget, 'mkdir -p /var/www/tradeflow/.deploy && chmod 700 /var/www/tradeflow/.deploy')
$encodedScript = [Convert]::ToBase64String([IO.File]::ReadAllBytes($localFile))
# Some servers accept SCP transfer but store it outside the requested path.
# Transfer and execute in one SSH shell instead, so the validated script is
# guaranteed to be the same one being executed.
$remoteCommand = "printf '%s' '$encodedScript' | base64 -d > '$remoteFile' && bash -n '$remoteFile' && bash '$remoteFile'"
Invoke-Checked 'ssh' @('-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4', $sshTarget, $remoteCommand)
Write-Host 'Trading Nginx repair completed successfully.'
