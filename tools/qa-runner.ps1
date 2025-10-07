Param(
    [string]$PhpPath = "",
    [switch]$VerboseOutput
)

$ErrorActionPreference = "Stop"

function Write-Info($msg) { Write-Host $msg -ForegroundColor Cyan }
function Write-Ok($msg) { Write-Host $msg -ForegroundColor Green }
function Write-Warn($msg) { Write-Host $msg -ForegroundColor Yellow }
function Write-Err($msg) { Write-Host $msg -ForegroundColor Red }

function Test-Command([string]$cmd) {
    try { $null = Get-Command $cmd -ErrorAction Stop; return $true } catch { return $false }
}

function Resolve-Php() {
    if ($PhpPath -and (Test-Path $PhpPath)) { return $PhpPath }

    if (Test-Command php) { return "php" }

    $commonPaths = @(
        "$Env:ProgramFiles\PHP",
        "$Env:ProgramFiles\php",
        "$Env:ProgramFiles\xampp\php",
        "$Env:ProgramFiles(x86)\PHP",
        "$Env:ProgramFiles(x86)\php",
        "$Env:ProgramFiles(x86)\xampp\php",
        "$Env:LOCALAPPDATA\Programs\PHP",
        "$Env:ChocolateyInstall\bin"
    ) | Where-Object { $_ -and (Test-Path $_) }

    foreach ($dir in $commonPaths) {
        $candidate = Join-Path $dir "php.exe"
        if (Test-Path $candidate) { return $candidate }
    }

    return $null
}

function Invoke-QA($php) {
    Write-Info "=== HIC Plugin QA (Windows) ==="
    Write-Info "Working dir: $(Get-Location)"
    Write-Info "PHP: $php"

    $cmd = "`"$php`" qa-runner.php"
    if ($VerboseOutput) { Write-Info "Command: $cmd" }

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $php
    $psi.Arguments = "qa-runner.php"
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.UseShellExecute = $false

    $p = New-Object System.Diagnostics.Process
    $p.StartInfo = $psi
    $null = $p.Start()
    $stdout = $p.StandardOutput.ReadToEnd()
    $stderr = $p.StandardError.ReadToEnd()
    $p.WaitForExit()

    if ($stdout) { Write-Host $stdout }
    if ($stderr) { Write-Warn $stderr }

    return $p.ExitCode
}

Push-Location $PSScriptRoot\..
try {
    $php = Resolve-Php
    if (-not $php) {
        Write-Warn "PHP non trovato nel PATH o in percorsi comuni."
        if (Test-Command wsl) {
            Write-Info "Rilevato WSL. Provo: wsl php qa-runner.php"
            & wsl php qa-runner.php
            exit $LASTEXITCODE
        }
        if (Test-Command docker) {
            Write-Info "Rilevato Docker. Provo container php:cli"
            $code = docker run --rm -v "$(Get-Location):/app" -w /app php:8-cli php qa-runner.php
            exit $LASTEXITCODE
        }
        Write-Err "Impossibile eseguire i QA: installa PHP o usa WSL/Docker."
        exit 1
    }

    $exit = Invoke-QA $php
    if ($exit -eq 0) { Write-Ok "Tutti i check sono passati" } else { Write-Err "Alcuni check sono falliti" }
    exit $exit
}
finally {
    Pop-Location
}


