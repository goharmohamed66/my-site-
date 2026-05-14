<?php
// /api/fetch-image.php?url=<encoded image url>
// Server-side image proxy. Lets the browser use a pasted image URL as a
// reference without hitting CORS on the remote image host — the server
// fetches the bytes and streams them back same-origin.
require_once __DIR__ . '/_db.php';
require_token();

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($url === '' || !preg_match('#^https?://#i', $url)) {
  send_json(['error' => 'valid http(s) url required'], 400);
}

// Light SSRF guard — block loopback / private ranges (the token already
// limits this to the site owner, but no reason to allow internal hosts).
$host = parse_url($url, PHP_URL_HOST);
if (!$host || preg_match('#^(localhost|127\.|10\.|192\.168\.|169\.254\.|0\.0\.0\.0|\[?::1\]?$)#i', $host)) {
  send_json(['error' => 'blocked host'], 400);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_MAXREDIRS      => 5,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT        => 25,
  CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; image-proxy)',
]);
$data     = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype    = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err      = curl_error($ch);
curl_close($ch);

if ($data === false || $httpCode < 200 || $httpCode >= 300) {
  send_json(['error' => 'fetch failed', 'http' => $httpCode, 'detail' => $err], 502);
}

// Confirm it's actually an image — trust the content sniff over the header.
if (function_exists('finfo_open')) {
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $sniff = finfo_buffer($finfo, $data);
  finfo_close($finfo);
  if ($sniff) $ctype = $sniff;
}
if (!preg_match('#^image/(png|jpe?g|webp|gif)$#i', $ctype)) {
  send_json(['error' => 'url is not a supported image', 'content_type' => $ctype], 415);
}

header('Content-Type: ' . $ctype);
header('Content-Length: ' . strlen($data));
header('Access-Control-Allow-Origin: *');
header('Cache-Control: private, max-age=300');
echo $data;
