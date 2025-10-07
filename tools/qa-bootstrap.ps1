Param(
    [switch]$VerboseOutput
)

$ErrorActionPreference = "Stop"

function Write-Info($m){ Write-Host $m -ForegroundColor Cyan }
function Write-Ok($m){ Write-Host $m -ForegroundColor Green }
function Write-Warn($m){ Write-Host $m -ForegroundColor Yellow }
function Write-Err($m){ Write-Host $m -ForegroundColor Red }

function Test-Cmd([string]$c){ try{ $null = Get-Command $c -ErrorAction Stop; return $true }catch{ return $false } }

function Get-LatestPhpZipUrl(){
  $api = "https://windows.php.net/downloads/releases/releases.json"
  try {
    $json = Invoke-WebRequest -Uri $api -UseBasicParsing -TimeoutSec 60 | Select-Object -ExpandProperty Content
    $data = $json | ConvertFrom-Json
    if ($null -eq $data){ return $null }
    $candidates = @()
    foreach ($k in $data.PSObject.Properties.Name){
      $rel = $data.$k
      if ($rel -and $rel.version -and $rel.thread_safe){
        $ver = [string]$rel.version
        $files = @($rel.ts_x64, $rel.zip_x64, $rel.zip) | Where-Object { $_ -is [string] -and $_.EndsWith('.zip') }
        foreach ($z in $files){ if ($z -match 'Win32-vs\d+-x64'){ $candidates += "https://windows.php.net/downloads/releases/$z" } }
      }
    }
    $candidates = $candidates | Select-Object -Unique
    if ($candidates.Count -gt 0){ return $candidates[0] }
  } catch { return $null }
  return $null
}

function Get-PortablePhp(){
  $cacheDir = Join-Path $PSScriptRoot "cache\php"
  New-Item -ItemType Directory -Force -Path $cacheDir | Out-Null
  $zipPath = Join-Path $cacheDir "php-portable.zip"
  $phpDir = Join-Path $cacheDir "php"
  $phpExe = Join-Path $phpDir "php.exe"
  if (Test-Path $phpExe){ return $phpExe }

  $urls = @()
  $dynamic = Get-LatestPhpZipUrl
  if ($dynamic){ $urls += $dynamic }
  # Stable archives fallbacks
  $urls += @(
    "https://windows.php.net/downloads/releases/archives/php-8.2.10-Win32-vs16-x64.zip",
    "https://windows.php.net/downloads/releases/archives/php-8.2.8-Win32-vs16-x64.zip",
    "https://windows.php.net/downloads/releases/archives/php-8.1.23-Win32-vs16-x64.zip"
  )

  foreach ($u in $urls){
    Write-Info "Tentativo download PHP: $u"
    try {
      Invoke-WebRequest -Uri $u -OutFile $zipPath -UseBasicParsing -TimeoutSec 120
      try {
        if (Test-Path $phpDir) { Remove-Item -Recurse -Force $phpDir }
        Expand-Archive -Path $zipPath -DestinationPath $phpDir -Force
        $exe = Get-ChildItem -Recurse -Path $phpDir -Filter php.exe | Select-Object -First 1
        if ($exe){ return $exe.FullName }
      } catch { Write-Warn "Estrazione fallita: $($_.Exception.Message)" }
    } catch {
      Write-Warn "Download fallito: $($_.Exception.Message)"
    }
  }
  return $null
}

Push-Location $PSScriptRoot\..
try{
  $php = $null
  if (Test-Cmd php){ $php = "php" } else { $php = Get-PortablePhp }
  if (-not $php){ Write-Err "PHP non disponibile (system o portable)."; exit 1 }
  Write-Info "PHP: $php"
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
  if ($stdout){ Write-Host $stdout }
  if ($stderr){ Write-Warn $stderr }
  if ($p.ExitCode -eq 0){ Write-Ok "QA completati con successo" } else { Write-Err "QA con errori ($($p.ExitCode))" }
  exit $p.ExitCode
}
finally{
  Pop-Location
}
