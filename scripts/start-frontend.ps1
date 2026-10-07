# Starts the Next.js frontend in development mode (installs dependencies on first run).
$root = Split-Path -Parent $PSScriptRoot
Set-Location "$root\frontend"
if (-not (Test-Path node_modules)) { npm install }
if (-not $env:AISO_API_URL) { $env:AISO_API_URL = "http://localhost:8080" }
npm run dev
