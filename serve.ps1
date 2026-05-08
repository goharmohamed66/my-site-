$ErrorActionPreference = 'Stop'
$port = 8000
$root = (Get-Location).Path
$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://localhost:$port/")
$listener.Start()
Write-Host "Serving $root on http://localhost:$port"

$mime = @{
  '.html'='text/html; charset=utf-8'; '.htm'='text/html; charset=utf-8'
  '.css'='text/css; charset=utf-8'; '.js'='text/javascript; charset=utf-8'
  '.mjs'='text/javascript; charset=utf-8'; '.json'='application/json; charset=utf-8'
  '.png'='image/png'; '.jpg'='image/jpeg'; '.jpeg'='image/jpeg'; '.gif'='image/gif'
  '.svg'='image/svg+xml'; '.ico'='image/x-icon'; '.webp'='image/webp'
  '.woff'='font/woff'; '.woff2'='font/woff2'; '.ttf'='font/ttf'
  '.txt'='text/plain; charset=utf-8'; '.map'='application/json; charset=utf-8'
}

while ($listener.IsListening) {
  try {
    $context = $listener.GetContext()
  } catch { break }
  $req = $context.Request
  $res = $context.Response
  try {
    $rel = [System.Net.WebUtility]::UrlDecode($req.Url.LocalPath).TrimStart('/')
    if ([string]::IsNullOrEmpty($rel)) { $rel = 'index.html' }
    $full = Join-Path $root $rel
    if ((Test-Path $full -PathType Container)) {
      $full = Join-Path $full 'index.html'
    }
    if (Test-Path $full -PathType Leaf) {
      $ext = [System.IO.Path]::GetExtension($full).ToLower()
      $type = if ($mime.ContainsKey($ext)) { $mime[$ext] } else { 'application/octet-stream' }
      $bytes = [System.IO.File]::ReadAllBytes($full)
      $res.ContentType = $type
      $res.ContentLength64 = $bytes.Length
      $res.OutputStream.Write($bytes, 0, $bytes.Length)
      Write-Host "200 $rel"
    } else {
      $res.StatusCode = 404
      $msg = [System.Text.Encoding]::UTF8.GetBytes("404 Not Found: $rel")
      $res.OutputStream.Write($msg, 0, $msg.Length)
      Write-Host "404 $rel"
    }
  } catch {
    $res.StatusCode = 500
    Write-Host "500 $($_.Exception.Message)"
  } finally {
    try { $res.Close() } catch {}
  }
}
