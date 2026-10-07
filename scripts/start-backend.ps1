# Starts the Spring Boot backend. Uses the portable JDK/Maven in ..\.tools when present, otherwise the ones on PATH.
$root = Split-Path -Parent $PSScriptRoot
$jdk = Get-ChildItem -Path "$root\.tools" -Directory -Filter "jdk-*" -ErrorAction SilentlyContinue | Select-Object -First 1
$mvn = Get-ChildItem -Path "$root\.tools" -Directory -Filter "apache-maven-*" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($jdk) { $env:JAVA_HOME = $jdk.FullName; $env:PATH = "$($jdk.FullName)\bin;$env:PATH" }
if ($mvn) { $env:PATH = "$($mvn.FullName)\bin;$env:PATH" }

Set-Location "$root\backend"
mvn spring-boot:run
