# deploy.ps1 — Upload files to Hostinger via FTP (with FTPS attempt)
# Usage:
#   .\deploy.ps1 shipping.html index.html
#   .\deploy.ps1 api/connectors.php
#   .\deploy.ps1 -All     (uploads core files)

param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Files,
  [switch]$All
)

$ErrorActionPreference = 'Stop'

# ── Load config ──────────────────────────────────────────────────────
$configPath = ".claude/ftp.json"
if (!(Test-Path $configPath)) {
  Write-Host "[ERROR] $configPath not found." -ForegroundColor Red
  Write-Host 'Create it with: { "host":"...", "user":"...", "password":"...", "remote_dir":"/public_html" }'
  exit 1
}
$cfg = Get-Content $configPath | ConvertFrom-Json
$ftpHost = $cfg.host
$ftpUser = $cfg.user
$ftpPass = $cfg.password
$remoteRoot = $cfg.remote_dir.TrimEnd('/')

# ── Default file list when -All is used ──────────────────────────────
if ($All) {
  $Files = @(
    'index.html', 'shipping.html', 'settings.html', 'topbar.js',
    'api/config.php', 'api/_db.php', 'api/setup.php',
    'api/orders.php', 'api/upload.php',
    'api/connectors.php', 'api/users.php', 'api/.htaccess'
  )
}

if (!$Files -or $Files.Count -eq 0) {
  Write-Host "Usage: .\deploy.ps1 file1 [file2 ...]   OR   .\deploy.ps1 -All"
  exit 1
}

# ── Ensure remote directory exists (silently ignores "already exists") ─
function Ensure-RemoteDir($relDir) {
  if (!$relDir -or $relDir -eq '.' -or $relDir -eq '/') { return }
  # Walk parents: a/b/c → a, a/b, a/b/c
  $parts = $relDir.Split('/') | Where-Object { $_ -ne '' }
  $cur = ''
  foreach ($p in $parts) {
    $cur = if ($cur) { "$cur/$p" } else { $p }
    $url = "ftp://$ftpHost$remoteRoot/$cur"
    foreach ($useTls in @($true, $false)) {
      try {
        $r = [System.Net.FtpWebRequest]::Create($url)
        $r.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $r.Credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
        $r.UsePassive = $true; $r.KeepAlive = $false; $r.EnableSsl = $useTls
        $resp = $r.GetResponse(); $resp.Close()
        break
      } catch { } # ignore — directory may already exist or TLS not supported
    }
  }
}

# ── Upload one file ──────────────────────────────────────────────────
function Upload-File($localPath, $relPath) {
  $dir = Split-Path -Parent $relPath
  if ($dir) { Ensure-RemoteDir ($dir -replace '\\','/') }
  $remotePath = "ftp://$ftpHost$remoteRoot/$relPath"
  $req = [System.Net.FtpWebRequest]::Create($remotePath)
  $req.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
  $req.Credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
  $req.UseBinary = $true
  $req.UsePassive = $true
  $req.KeepAlive = $false
  # Try FTPS first; fall back to plain FTP if not supported
  try {
    $req.EnableSsl = $true
    $bytes = [System.IO.File]::ReadAllBytes($localPath)
    $req.ContentLength = $bytes.Length
    $stream = $req.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()
    $resp = $req.GetResponse()
    $status = $resp.StatusDescription.Trim()
    $resp.Close()
    return "FTPS OK ($status)"
  } catch {
    # Retry without TLS
    $req2 = [System.Net.FtpWebRequest]::Create($remotePath)
    $req2.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $req2.Credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
    $req2.UseBinary = $true
    $req2.UsePassive = $true
    $req2.KeepAlive = $false
    $req2.EnableSsl = $false
    $bytes = [System.IO.File]::ReadAllBytes($localPath)
    $req2.ContentLength = $bytes.Length
    $s2 = $req2.GetRequestStream()
    $s2.Write($bytes, 0, $bytes.Length)
    $s2.Close()
    $resp2 = $req2.GetResponse()
    $status = $resp2.StatusDescription.Trim()
    $resp2.Close()
    return "FTP OK ($status)"
  }
}

# ── Snapshot every file we're about to upload into .deploy-history/<ts>/
# so you always have a way back if a deploy breaks something.
$ts = (Get-Date -Format 'yyyyMMdd-HHmmss')
$snapRoot = Join-Path (Get-Location) ".deploy-history/$ts"
foreach ($f in $Files) {
  $rel = $f -replace '\\','/' -replace '^\./',''
  $localPath = Join-Path (Get-Location) $rel
  if (!(Test-Path $localPath -PathType Leaf)) { continue }
  $dest = Join-Path $snapRoot $rel
  $destDir = Split-Path -Parent $dest
  if (!(Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
  Copy-Item -Path $localPath -Destination $dest -Force
}
Write-Host "[SNAPSHOT] saved → .deploy-history/$ts/" -ForegroundColor DarkCyan

# ── Auto cache-bust: re-stamp ?v=TIMESTAMP on every local script/css ref
# inside any HTML we're about to upload. This guarantees browsers pick up
# the freshest sidebar-tools.js / topbar.js / datepicker.js etc on next
# refresh — no Ctrl+F5 needed.
$VER = [int](Get-Date -UFormat %s)
$jsRegex  = '(<script\b[^>]*\bsrc=")(?!https?:|//)([^"?]+\.js)(\?v=[^"]*)?(")'
$cssRegex = '(<link\b[^>]*\bhref=")(?!https?:|//)([^"?]+\.css)(\?v=[^"]*)?(")'
foreach ($f in $Files) {
  $rel = $f -replace '\\','/'
  $rel = $rel -replace '^\./',''
  if ($rel -notmatch '\.html$') { continue }
  $localPath = Join-Path (Get-Location) $rel
  if (!(Test-Path $localPath -PathType Leaf)) { continue }
  # Read & write as raw UTF-8 bytes to avoid PowerShell 5.1's default ANSI
  # behavior (which corrupts Arabic text into Windows-1256 mojibake).
  $bytes = [System.IO.File]::ReadAllBytes($localPath)
  $s = [System.Text.Encoding]::UTF8.GetString($bytes)
  $s2 = [regex]::Replace($s, $jsRegex,  { param($m) $m.Groups[1].Value + $m.Groups[2].Value + "?v=$VER" + $m.Groups[4].Value }, 'IgnoreCase')
  $s2 = [regex]::Replace($s2, $cssRegex, { param($m) $m.Groups[1].Value + $m.Groups[2].Value + "?v=$VER" + $m.Groups[4].Value }, 'IgnoreCase')
  if ($s -ne $s2) {
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllBytes($localPath, $utf8NoBom.GetBytes($s2))
    Write-Host "[STAMP] $rel  ?v=$VER" -ForegroundColor DarkGray
  }
}

# ── Main loop ────────────────────────────────────────────────────────
$success = 0; $failed = 0
foreach ($f in $Files) {
  $rel = $f -replace '\\','/'
  $rel = $rel -replace '^\./',''   # strip leading "./" only, NOT a leading dot in filenames like .htaccess
  $localPath = Join-Path (Get-Location) $rel
  if (!(Test-Path $localPath -PathType Leaf)) {
    Write-Host "[SKIP] $rel (not found)" -ForegroundColor Yellow
    continue
  }
  Write-Host "[UPLOAD] $rel ... " -NoNewline
  try {
    $result = Upload-File $localPath $rel
    Write-Host $result -ForegroundColor Green
    $success++
  } catch {
    Write-Host "FAILED: $($_.Exception.Message)" -ForegroundColor Red
    $failed++
  }
}

Write-Host ""
Write-Host "Done. $success succeeded, $failed failed." -ForegroundColor Cyan
