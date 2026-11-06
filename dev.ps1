param([string]$Command = 'help')

# Keep native flags such as PHP's -v out of PowerShell's common-parameter binding.
$ToolArguments = @($args)

$ErrorActionPreference = 'Stop'
$backendPath = $PSScriptRoot
$projectPath = Split-Path -Parent $backendPath
$toolsPath = Join-Path $projectPath '.tools'
$phpExecutable = [IO.Path]::GetFullPath((Join-Path $toolsPath 'php/php.exe'))
$composerFile = Join-Path $toolsPath 'composer.phar'
$mysqlExecutable = Join-Path $toolsPath 'mysql-dist/mysql-8.4.13-winx64/bin/mysql.exe'
$mysqlServer = Join-Path $toolsPath 'mysql-dist/mysql-8.4.13-winx64/bin/mysqld.exe'
$mysqlAdmin = Join-Path $toolsPath 'mysql-dist/mysql-8.4.13-winx64/bin/mysqladmin.exe'
$serverRouter = [IO.Path]::GetFullPath((Join-Path $backendPath 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'))
$serverState = Join-Path $toolsPath 'backend-process.json'
$appOptions = '--defaults-extra-file=' + (Join-Path $toolsPath 'mysql-app.cnf')
$adminOptions = '--defaults-extra-file=' + (Join-Path $toolsPath 'mysql-admin.cnf')

if (-not (Test-Path -LiteralPath $phpExecutable)) {
    throw 'Project-local PHP was not found. See backend/README.md for the development setup.'
}
$env:PATH = (Join-Path $toolsPath 'php') + ';' + (Join-Path $toolsPath 'bin') + ';' + $env:PATH
$env:COMPOSER_HOME = Join-Path $toolsPath 'composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $toolsPath 'composer-cache'
Set-Location -LiteralPath $backendPath

function Test-Database {
    $priorErrorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $mysqlExecutable $appOptions --connect-timeout=2 --batch --skip-column-names '--execute=SELECT 1;' *> $null
        return $LASTEXITCODE -eq 0
    } finally { $ErrorActionPreference = $priorErrorPreference }
}

function Test-LocalPort([int]$Port) {
    return @([Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners() |
        Where-Object { $_.Port -eq $Port }).Count -gt 0
}

function Start-Database {
    if (Test-Database) { return }
    if (Test-LocalPort 3306) { throw 'Port 3306 belongs to another database instance; no process was changed.' }
    $configArgument = '--defaults-file="' + (Join-Path $toolsPath 'mysql.ini') + '"'
    $databaseProcess = Start-Process -FilePath $mysqlServer -ArgumentList $configArgument -WindowStyle Hidden -PassThru
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if (Test-Database) { Write-Output 'MySQL started on 127.0.0.1:3306.'; return }
        if ($databaseProcess.HasExited) { break }
        Start-Sleep -Milliseconds 500
    }
    throw 'MySQL did not become ready. Check .tools/mysql-server.log.'
}

function Get-OwnedBackendProcess {
    if (-not (Test-Path -LiteralPath $serverState)) { return $null }
    $state = Get-Content -LiteralPath $serverState -Raw | ConvertFrom-Json
    $process = Get-Process -Id ([int]$state.process_id) -ErrorAction SilentlyContinue
    if ($process -and $process.Path -eq $phpExecutable -and
        $process.StartTime.ToUniversalTime().Ticks.ToString() -eq $state.start_time_utc_ticks) {
        return $process
    }
    return $null
}

switch ($Command.ToLowerInvariant()) {
    'php' { & $phpExecutable @ToolArguments; exit $LASTEXITCODE }
    'composer' { & $phpExecutable $composerFile @ToolArguments; exit $LASTEXITCODE }
    'artisan' { & $phpExecutable artisan @ToolArguments; exit $LASTEXITCODE }
    'mysql' { & $mysqlExecutable $appOptions @ToolArguments; exit $LASTEXITCODE }
    'serve' { Start-Database; & $phpExecutable artisan serve --host=127.0.0.1 --port=8000 --no-reload; exit $LASTEXITCODE }
    'start' {
        Start-Database
        if (Get-OwnedBackendProcess) { Write-Output 'EasyCare backend is already running at http://localhost:8000'; exit 0 }
        if (Test-LocalPort 8000) { throw 'Port 8000 is already in use; no existing process was changed.' }
        $serverArguments = @('-S', '127.0.0.1:8000', '-t', ('"' + (Join-Path $backendPath 'public') + '"'), ('"' + $serverRouter + '"'))
        $serverProcess = Start-Process -FilePath $phpExecutable -ArgumentList $serverArguments -WorkingDirectory (Join-Path $backendPath 'public') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $toolsPath 'backend-stdout.log') -RedirectStandardError (Join-Path $toolsPath 'backend-stderr.log')
        @{ process_id = $serverProcess.Id; start_time_utc_ticks = $serverProcess.StartTime.ToUniversalTime().Ticks.ToString() } | ConvertTo-Json | Set-Content -LiteralPath $serverState
        for ($attempt = 0; $attempt -lt 25; $attempt++) {
            try {
                $health = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8000/up' -TimeoutSec 2
                if ($health.StatusCode -eq 200) { Write-Output 'EasyCare backend: http://localhost:8000'; exit 0 }
            } catch { Start-Sleep -Milliseconds 300 }
            if ($serverProcess.HasExited) { break }
        }
        throw 'Laravel did not become ready. Check .tools/backend-stderr.log.'
    }
    'stop' {
        $ownedProcess = Get-OwnedBackendProcess
        if ($ownedProcess) { Stop-Process -Id $ownedProcess.Id; Write-Output 'EasyCare backend stopped.' }
        if (Test-Database) { & $mysqlAdmin $adminOptions shutdown; if ($LASTEXITCODE -ne 0) { throw 'MySQL shutdown failed.' }; Write-Output 'Local MySQL stopped.' }
        exit 0
    }
    'status' {
        & $phpExecutable -v
        & $phpExecutable $composerFile --version
        & $phpExecutable artisan --version
        if (Test-Database) { & $mysqlExecutable $appOptions '--execute=SELECT VERSION() AS mysql_version, DATABASE() AS database_name;' }
        else { Write-Output 'MySQL is stopped. Run .\dev.cmd start.' }
        if (Get-OwnedBackendProcess) { Write-Output 'Backend is running: http://localhost:8000' }
        else { Write-Output 'Backend is stopped. Run .\dev.cmd start.' }
        exit 0
    }
    default {
        Write-Output 'Usage: .\dev.cmd start | stop | status | serve | php <args> | composer <args> | artisan <args> | mysql <args>'
        exit 0
    }
}
