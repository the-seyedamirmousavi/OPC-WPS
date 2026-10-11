#!/usr/bin/env bash
# Starts the AISO application on http://localhost:8080 (Linux / macOS). Usage: scripts/start.sh [port]
set -euo pipefail
cd "$(dirname "$0")/.."
php artisan serve --host 0.0.0.0 --port "${1:-8080}"
