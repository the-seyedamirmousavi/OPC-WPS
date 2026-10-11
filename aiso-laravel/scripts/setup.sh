#!/usr/bin/env bash
# One-time setup of the AISO Laravel application (Linux / macOS).
# Needs: PHP 8.2+ with pdo_sqlite, sqlite3, mbstring, xml, dom, zip, fileinfo, openssl, curl; Composer 2;
#        Node.js 20+ (only to build the screens; the built files are kept in public/build).
set -euo pipefail
cd "$(dirname "$0")/.."

composer install --no-interaction --prefer-dist --optimize-autoloader

[ -f .env ] || cp .env.example .env
php artisan key:generate --force

[ -f database/database.sqlite ] || touch database/database.sqlite
php artisan migrate --seed --force

npm ci
npm run build

echo
echo "Setup finished. Start with scripts/start.sh and open http://localhost:8080"
echo "Seeded accounts: owner, manager, user1, user2, user3 (password: AISO_SEED_PASSWORD, default ChangeMe!123)."
