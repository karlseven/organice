# organice DB installer.
#
# Reads .env for the database name and credentials, creates the database if it
# is missing, then runs schema.sql and procedures.sql against it.
#
# Order matters: schema.sql sets the database's default collation, and stored
# procedures capture that default at CREATE time. Procedures must always be
# (re)created after it — see the comment at the top of schema.sql.

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent $here

$mysql = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe'
if (-not (Test-Path $mysql)) {
  $found = (Get-Command mysql -ErrorAction SilentlyContinue).Source
  if ($found) { $mysql = $found } else { throw "mysql.exe not found - set `$mysql at the top of this script" }
}

$envFile = Join-Path $root '.env'
if (-not (Test-Path $envFile)) { throw "No .env - copy .env.example to .env and fill it in first" }

$cfg = @{}
foreach ($line in Get-Content $envFile) {
  $line = $line.Trim()
  if ($line -eq '' -or $line.StartsWith('#') -or -not $line.Contains('=')) { continue }
  $parts = $line.Split('=', 2)
  $cfg[$parts[0].Trim()] = $parts[1].Trim()
}

$dbName = $cfg['DB_NAME']; $dbUser = $cfg['DB_USER']; $dbHost = $cfg['DB_HOST']
if (-not $dbName -or -not $dbUser) { throw 'DB_NAME and DB_USER must be set in .env' }

Write-Host "Installing into '$dbName' as '$dbUser'..." -ForegroundColor Cyan

# MYSQL_PWD keeps the password off the command line, where it would be visible
# to any other process listing.
$env:MYSQL_PWD = $cfg['DB_PASS']
try {
  # Created with the right collation from the start; schema.sql re-asserts it
  # anyway so a pre-existing database is corrected too.
  & $mysql -u $dbUser -h $dbHost -e "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
  if ($LASTEXITCODE -ne 0) { throw 'could not create the database' }

  Get-Content (Join-Path $here 'schema.sql') -Raw | & $mysql -u $dbUser -h $dbHost -D $dbName
  if ($LASTEXITCODE -ne 0) { throw 'schema.sql failed' }
  Write-Host '  schema.sql      ok' -ForegroundColor Green

  Get-Content (Join-Path $here 'procedures.sql') -Raw | & $mysql -u $dbUser -h $dbHost -D $dbName
  if ($LASTEXITCODE -ne 0) { throw 'procedures.sql failed' }
  Write-Host '  procedures.sql  ok' -ForegroundColor Green

  Write-Host "`nDone. Next:" -ForegroundColor Cyan
  Write-Host '  php scripts/seed.php you@example.com "a-long-password"'
  Write-Host '  php -S localhost:8080 -t public public/index.php'
} finally {
  Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}
