<?php
// /api/temp-image.php
//   POST (with Authorization)  — saves a base64 image, returns { url, id }
//   GET  ?id=<id>               — serves the saved image bytes (public, no auth)
//
// Purpose: give Google Lens (and any other external service that needs to
// fetch our uploaded image by URL) a stable, public URL it can pull. The
// reverse-image-search backend uses this so it can drive Lens via the
// uploadbyurl flow — which is far more reliable than multipart POST and,
// crucially, gives the user a clickable Lens link they can open themselves
// to verify the image actually arrived.
//
// Storage: ./tmp-images/ next to this file. Files self-expire — each call
// sweeps anything older than 1 hour, so the folder never grows unbounded.

require_once __DIR__ . '/_db.php';

$DIR = __DIR__ . '/tmp-images';
if (!is_dir($DIR)) @mkdir($DIR, 0775, true);

// Sweep stale files (older than 1h) on every hit. Keeps the folder bounded
// without needing a cron job.
$expiry = time() - 3600;
foreach (glob($DIR . '/*') as $f) {
  if (is_file($f) && filemtime($f) < $expiry) @unlink($f);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  // Sanitize but keep the file extension dot — the saved filename is
  // <hex>_<timestamp>.<ext> so the regex needs to allow '.' as well.
  $id = isset($_GET['id']) ? preg_replace('/[^a-z0-9_.]/i', '', $_GET['id']) : '';
  if ($id === '') { http_response_code(400); echo 'id required'; exit; }
  $path = $DIR . '/' . $id;
  if (!is_file($path)) { http_response_code(404); echo 'not found'; exit; }

  // Sniff mime from magic bytes (no fileinfo extension dependency).
  $head = file_get_contents($path, false, null, 0, 12);
  $mime = 'application/octet-stream';
  if (substr($head, 0, 3) === "\xFF\xD8\xFF")            $mime = 'image/jpeg';
  elseif (substr($head, 0, 8) === "\x89PNG\r\n\x1A\n")   $mime = 'image/png';
  elseif (substr($head, 0, 4) === "RIFF" && substr($head, 8, 4) === 'WEBP') $mime = 'image/webp';
  elseif (substr($head, 0, 3) === 'GIF')                 $mime = 'image/gif';

  header('Content-Type: ' . $mime);
  header('Content-Length: ' . filesize($path));
  header('Cache-Control: public, max-age=3600');
  header('Access-Control-Allow-Origin: *');
  readfile($path);
  exit;
}

if ($method === 'POST') {
  require_token();
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);
  $img = isset($body['image']) ? (string)$body['image'] : '';
  if ($img === '') send_json(['error' => 'image required'], 400);

  $mime = 'image/jpeg'; $bytes = '';
  if (preg_match('#^data:([^;]+);base64,(.+)$#', $img, $m)) {
    $mime = $m[1]; $bytes = base64_decode($m[2], true);
  } else {
    $bytes = base64_decode($img, true);
  }
  if ($bytes === false || strlen($bytes) < 200) send_json(['error' => 'decode failed'], 400);

  $ext = ($mime === 'image/png') ? 'png'
        : (($mime === 'image/webp') ? 'webp'
        : (($mime === 'image/gif') ? 'gif' : 'jpg'));
  $id  = bin2hex(random_bytes(10)) . '_' . time() . '.' . $ext;
  $path = $DIR . '/' . $id;
  if (file_put_contents($path, $bytes) === false) {
    send_json(['error' => 'write failed'], 500);
  }

  // Build the public URL using the request host/path. Works for any
  // deployment without hard-coding the Hostinger domain.
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $self   = $_SERVER['SCRIPT_NAME'] ?? '/api/temp-image.php';
  $url    = $scheme . '://' . $host . $self . '?id=' . $id;

  send_json(['id' => $id, 'url' => $url, 'bytes' => strlen($bytes)]);
}

http_response_code(405);
echo 'Use GET ?id=… or POST';
