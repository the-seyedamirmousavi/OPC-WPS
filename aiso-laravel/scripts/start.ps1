# Starts the AISO application on http://localhost:8080 (Windows PowerShell).
# Use -Port to change the port. For production behind a web server, point it at public/ instead.
param([int]$Port = 8080)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
php artisan serve --host 0.0.0.0 --port $Port
