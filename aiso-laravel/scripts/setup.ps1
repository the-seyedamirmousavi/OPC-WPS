# One-time setup of the AISO Laravel application (Windows PowerShell).
# Needs: PHP 8.2+ with the extensions pdo_sqlite, sqlite3, mbstring, xml, dom, zip, fileinfo, openssl, curl;
#        Composer 2; Node.js 20+ (only to build the screens; the built files are kept in public/build).
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

composer install --no-interaction --prefer-dist --optimize-autoloader

if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env' }
php artisan key:generate --force

if (-not (Test-Path 'database/database.sqlite')) { New-Item -ItemType File -Path 'database/database.sqlite' | Out-Null }
php artisan migrate --seed --force

npm ci
npm run build

Write-Host ''
Write-Host 'Setup finished. Start the application with scripts/start.ps1 and open http://localhost:8080'
Write-Host 'Seeded accounts: owner, manager, user1, user2, user3 (password: AISO_SEED_PASSWORD, default ChangeMe!123).'
