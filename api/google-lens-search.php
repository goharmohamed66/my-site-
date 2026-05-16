<?php
// POST /api/google-lens-search.php
// Body: JSON  { image: "<data:image/...;base64,...>"  OR  imageUrl: "<https-url>" }
//
// Reverse-image search for the uploaded product photo on Google, then return
// every amazon.* product link the results contain (across all TLDs:
// amazon.com, amazon.eg, amazon.de, amazon.co.uk, amazon.com.tr, etc.).
//
// Implementation: posts the image to https://www.google.com/searchbyimage/upload
// (the legacy reverse-image endpoint that still works for server-side use),
// follows the 302 redirect to the search results page, then parses every
// outbound /url?q=... link in the HTML and keeps the ones whose target host
// matches amazon.<tld>.
//
// Because Google sometimes shows a consent interstitial, we send a baked-in
// CONSENT=YES+cb cookie that skips it. If the response still looks like a
// consent page or a captcha, the endpoint returns a clear error so the
// caller can surface it instead of silently returning zero results.

require_once __DIR__ . '/_db.php';
require_token();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  send_json(['error' => 'Use POST'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);

$imageDataUrl = isset($body['image']) ? (string)$body['image'] : '';
$imageUrl     = isset($body['imageUrl']) ? trim((string)$body['imageUrl']) : '';

if ($imageDataUrl === '' && $imageUrl === '') {
  send_json(['error' => 'Provide "image" (data URL / base64) or "imageUrl".'], 400);
}

// ── Decode the image bytes ───────────────────────────────────────────
$bytes = '';
$mime  = 'image/jpeg';
$filename = 'image.jpg';

if ($imageDataUrl !== '') {
  if (preg_match('#^data:([^;]+);base64,(.+)$#', $imageDataUrl, $m)) {
    $mime  = $m[1];
    $bytes = base64_decode($m[2], true);
  } else {
    $bytes = base64_decode($imageDataUrl, true);
  }
  if ($bytes === false || $bytes === '') {
    send_json(['error' => 'Could not decode base64 image data.'], 400);
  }
  $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
  $filename = 'image.' . $ext;
} else {
  $ch = curl_init($imageUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
  ]);
  $bytes = curl_exec($ch);
  $mime  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  if (!$bytes) send_json(['error' => 'Failed to fetch imageUrl.'], 400);
  if (stripos($mime, 'image/') !== 0) $mime = 'image/jpeg';
  $filename = 'image.' . (strpos($mime, 'png') !== false ? 'png' : 'jpg');
}

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';
// CONSENT cookie bypasses the EU consent interstitial. YES+cb is the value
// Google sets once a user clicks "Accept all" — it survives indefinitely
// and stops the search redirect from landing on consent.google.com.
$baseCookie = 'CONSENT=YES+cb; SOCS=CAESHAgBEhJnd3NfMjAyNDA2MDQtMF9SQzIaAmVuIAEaBgiAyMq6Bg';

// ── 1. Upload the image to Google reverse-image search ───────────────
$tmp = tempnam(sys_get_temp_dir(), 'glens_');
file_put_contents($tmp, $bytes);
$cfile = new CURLFile($tmp, $mime, $filename);

$ch = curl_init('https://www.google.com/searchbyimage/upload');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => false,        // we want the Location header
  CURLOPT_HEADER         => true,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => ['encoded_image' => $cfile, 'image_url' => '', 'sbisrc' => 'cr_1'],
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT        => 30,
  CURLOPT_USERAGENT      => $UA,
  CURLOPT_HTTPHEADER     => [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
    'Cookie: ' . $baseCookie,
  ],
]);
$res = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);
@unlink($tmp);

if (!$res) send_json(['error' => 'Upload to Google failed (network).'], 502);

// Extract Location header from the response (we asked for headers+body)
$headerSize = $info['header_size'] ?? 0;
$headers    = substr($res, 0, $headerSize);
$location   = '';
if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
  $location = trim($m[1]);
}
if ($location === '') {
  send_json([
    'error' => 'Google did not return a results redirect. The upload endpoint may have changed or your IP is rate-limited.',
    'http'  => $info['http_code'] ?? 0,
  ], 502);
}
// Normalize to absolute URL
if (strpos($location, 'http') !== 0) {
  $location = 'https://www.google.com' . (strpos($location, '/') === 0 ? '' : '/') . $location;
}

// ── 2. Fetch the results page ────────────────────────────────────────
$ch = curl_init($location);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_MAXREDIRS      => 5,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT        => 30,
  CURLOPT_ENCODING       => 'gzip, deflate',
  CURLOPT_USERAGENT      => $UA,
  CURLOPT_HTTPHEADER     => [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
    'Cookie: ' . $baseCookie,
    'Referer: https://www.google.com/',
  ],
]);
$html = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $httpCode >= 400) {
  send_json(['error' => 'Google results fetch failed (HTTP ' . $httpCode . ').'], 502);
}
if (stripos($html, 'detected unusual traffic') !== false || stripos($html, 'recaptcha') !== false) {
  send_json([
    'error' => 'Google is blocking the request (CAPTCHA / unusual-traffic). Try again later or rotate IP/cookies.',
    'searchUrl' => $location,
  ], 429);
}

// ── 3. Extract every outbound link to amazon.<tld> ───────────────────
// Google wraps result links as:  <a href="/url?q=<encoded-target>&sa=U&...">
// and also occasionally as plain  href="https://...amazon.../..." inside JSON
// blobs. We pull both.
$amazonHostRe = '#amazon\.(?:com|eg|ae|sa|de|co\.uk|fr|it|es|nl|pl|se|com\.tr|com\.au|com\.mx|com\.br|ca|in|sg|co\.jp|cn)#i';

$found = []; // host => [ { url, host, country, snippet } ]

// 3a. /url?q= wrappers
if (preg_match_all('#href="(?:/url\?q=|/imgres\?imgrefurl=)([^"&]+)#i', $html, $m)) {
  foreach ($m[1] as $raw) {
    $u = urldecode($raw);
    if (preg_match($amazonHostRe, $u)) {
      $u = strip_amazon_tracking($u);
      $h = parse_url($u, PHP_URL_HOST) ?: '';
      if ($h === '') continue;
      $found[$u] = ['url' => $u, 'host' => $h, 'country' => host_to_country($h), 'title' => ''];
    }
  }
}
// 3b. plain absolute amazon URLs
if (preg_match_all('#https?://[^"\s<>()]*' . substr($amazonHostRe, 1, -2) . '[^"\s<>()]*#i', $html, $m)) {
  foreach ($m[0] as $u) {
    $u = strip_amazon_tracking($u);
    $h = parse_url($u, PHP_URL_HOST) ?: '';
    if ($h === '' || isset($found[$u])) continue;
    $found[$u] = ['url' => $u, 'host' => $h, 'country' => host_to_country($h), 'title' => ''];
  }
}

// Drop links that aren't product pages (we want /dp/ or /gp/product/ pages,
// not category / search / customer-reviews pages without ASIN context).
$products = [];
foreach ($found as $row) {
  if (preg_match('#/(?:dp|gp/product)/[A-Z0-9]{10}#i', $row['url'])) {
    $products[] = $row;
  }
}

// De-duplicate by ASIN+host so the same listing surfaced via two URL shapes
// doesn't show up twice.
$seen = [];
$unique = [];
foreach ($products as $p) {
  if (preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})#i', $p['url'], $m)) {
    $key = strtolower($p['host']) . '|' . strtoupper($m[1]);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $p['asin'] = strtoupper($m[1]);
    $unique[] = $p;
  }
}

// Sort: amazon.eg first, then the rest alphabetically by country code.
usort($unique, function ($a, $b) {
  $ra = ($a['country'] === 'EG') ? '' : $a['country'];
  $rb = ($b['country'] === 'EG') ? '' : $b['country'];
  return strcmp($ra, $rb);
});

send_json([
  'count'     => count($unique),
  'results'   => $unique,
  'searchUrl' => $location,
]);

// ── Helpers ──────────────────────────────────────────────────────────
function strip_amazon_tracking($u) {
  // Cut everything after /dp/ASIN or /gp/product/ASIN — drops ref=, tag=, etc.
  if (preg_match('#^(https?://[^/]+/(?:[^/]+/)?(?:dp|gp/product)/[A-Z0-9]{10})#i', $u, $m)) {
    return $m[1];
  }
  // Otherwise drop everything from the first "?" or "&ref" onward
  $u = preg_replace('/[?&]ref(_)?=.*$/i', '', $u);
  return $u;
}

function host_to_country($host) {
  $h = strtolower($host);
  $h = preg_replace('/^www\./', '', $h);
  // Map amazon.<tld> → ISO country
  $map = [
    'amazon.com'    => 'US',  'amazon.eg'     => 'EG',
    'amazon.ae'     => 'AE',  'amazon.sa'     => 'SA',
    'amazon.de'     => 'DE',  'amazon.co.uk'  => 'GB',
    'amazon.fr'     => 'FR',  'amazon.it'     => 'IT',
    'amazon.es'     => 'ES',  'amazon.nl'     => 'NL',
    'amazon.pl'     => 'PL',  'amazon.se'     => 'SE',
    'amazon.com.tr' => 'TR',  'amazon.com.au' => 'AU',
    'amazon.com.mx' => 'MX',  'amazon.com.br' => 'BR',
    'amazon.ca'     => 'CA',  'amazon.in'     => 'IN',
    'amazon.sg'     => 'SG',  'amazon.co.jp'  => 'JP',
    'amazon.cn'     => 'CN',
  ];
  return $map[$h] ?? strtoupper(preg_replace('/^amazon\./', '', $h));
}
