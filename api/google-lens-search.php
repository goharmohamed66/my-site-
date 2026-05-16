<?php
// POST /api/google-lens-search.php
// Body:
//   { image:  "data:image/...;base64,..." }                      (single image)
//   { images: ["data:...", "data:...", ...] }                    (batch — runs
//                                                                 Lens once per
//                                                                 image, merges
//                                                                 every amazon
//                                                                 link found)
//   optional: { debug: 1 }
//
// Reverse-image search via Google Lens — and ONLY Google Lens. Flow:
//   1. Save each uploaded image to /api/temp-image.php — gives us a stable
//      public URL Google can fetch.
//   2. Hit https://lens.google.com/uploadbyurl?url=<public-url>. Lens
//      downloads the image and runs the visual-matches search.
//   3. Parse the rendered Lens HTML for every amazon.<tld> URL it contains.
//
// Why uploadbyurl instead of POST multipart: Google's modern Lens upload
// endpoint frequently returns a JS-only shell when posted to from a server
// IP, but uploadbyurl works reliably. As a bonus, the same uploadbyurl
// link is opaque + reusable, so we return it as `verifyUrl` and the user
// can click it to confirm in their own browser that the image really did
// reach Lens.

require_once __DIR__ . '/_db.php';
require_token();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  send_json(['error' => 'Use POST'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);

$debug = !empty($body['debug']);

$images = [];
if (!empty($body['images']) && is_array($body['images'])) {
  foreach ($body['images'] as $img) {
    if (is_string($img) && $img !== '') $images[] = $img;
  }
}
if (empty($images) && !empty($body['image']) && is_string($body['image'])) {
  $images[] = $body['image'];
}
if (empty($images)) send_json(['error' => 'Provide "image" or "images".'], 400);
if (count($images) > 8) $images = array_slice($images, 0, 8);

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

// Resolve our own public base URL so we can build temp-image links Lens can
// fetch. This mirrors how temp-image.php builds its own URL.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$selfDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/api/google-lens-search.php');
$tempImageBase = $scheme . '://' . $host . $selfDir . '/temp-image.php';

// ── Run Lens for every uploaded image ────────────────────────────────
$perImage = [];
$allHits  = [];

foreach ($images as $idx => $dataUrl) {
  list($bytes, $mime, $ext, $err) = decode_image($dataUrl);
  if ($err) {
    $perImage[] = ['index' => $idx, 'status' => 'error', 'note' => $err, 'found' => 0,
                   'verifyUrl' => '', 'publicUrl' => ''];
    continue;
  }

  // 1. Stash the image where Lens can pull it from.
  $publicUrl = save_temp_image($bytes, $ext);
  if (!$publicUrl) {
    $perImage[] = ['index' => $idx, 'status' => 'error', 'note' => 'temp-image save failed',
                   'found' => 0, 'verifyUrl' => '', 'publicUrl' => ''];
    continue;
  }

  // 2. The Lens link the human can click to verify. Works in any browser.
  $verifyUrl = 'https://lens.google.com/uploadbyurl?url=' . rawurlencode($publicUrl) . '&hl=en-US';

  // 3. Run Lens server-side via the same uploadbyurl flow.
  $meta = run_lens_by_url($publicUrl, $UA);
  $meta['index']     = $idx;
  $meta['verifyUrl'] = $verifyUrl;
  $meta['publicUrl'] = $publicUrl;
  $perImage[] = $meta;
  foreach ($meta['links'] as $u) $allHits[$u] = true;
  unset($perImage[count($perImage)-1]['links']);  // don't ship raw hits
}

// ── Filter to product pages + de-dup by ASIN+host ────────────────────
$seen = [];
$unique = [];
foreach (array_keys($allHits) as $u) {
  $u2 = strip_amazon_tracking($u);
  if (!preg_match('#/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})#i', $u2, $am)) continue;
  $hst = parse_url($u2, PHP_URL_HOST) ?: '';
  if ($hst === '') continue;
  $key = strtolower($hst) . '|' . strtoupper($am[1]);
  if (isset($seen[$key])) continue;
  $seen[$key] = true;
  $unique[] = [
    'url'     => $u2,
    'host'    => preg_replace('/^www\./', '', strtolower($hst)),
    'country' => host_to_country($hst),
    'asin'    => strtoupper($am[1]),
    'engine'  => 'google-lens',
  ];
}

usort($unique, function ($a, $b) {
  if ($a['country'] === 'EG' && $b['country'] !== 'EG') return -1;
  if ($b['country'] === 'EG' && $a['country'] !== 'EG') return 1;
  return strcmp($a['country'], $b['country']);
});

$out = [
  'count'   => count($unique),
  'results' => $unique,
  'images'  => $perImage,
];
if ($debug) $out['rawHitCount'] = count($allHits);
send_json($out);

// ══════════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════════

function save_temp_image($bytes, $ext) {
  global $tempImageBase;
  $dir = __DIR__ . '/tmp-images';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  // Sweep stale (>1h) files so the folder stays bounded.
  $expiry = time() - 3600;
  foreach (glob($dir . '/*') as $f) {
    if (is_file($f) && filemtime($f) < $expiry) @unlink($f);
  }

  $id = bin2hex(random_bytes(10)) . '_' . time() . '.' . $ext;
  if (file_put_contents($dir . '/' . $id, $bytes) === false) return '';
  return $tempImageBase . '?id=' . $id;
}

function run_lens_by_url($publicUrl, $UA) {
  $meta = ['status' => 'ok', 'note' => '', 'location' => '', 'bytes' => 0,
           'found' => 0, 'links' => []];

  $cookie = 'CONSENT=YES+cb; SOCS=CAESHAgBEhJnd3NfMjAyNDA2MDQtMF9SQzIaAmVuIAEaBgiAyMq6Bg';

  // Lens's uploadbyurl endpoint. 302 → /search?p=<token> which renders the
  // visual-matches page. Following redirects is fine here — we don't need
  // the intermediate URL, only the final HTML.
  $url = 'https://lens.google.com/uploadbyurl'
       . '?url=' . rawurlencode($publicUrl)
       . '&hl=en-US';

  $ch = curl_init($url);
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
      'Referer: https://lens.google.com/',
      'Cookie: ' . $cookie,
    ],
  ]);
  $html = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  curl_close($ch);

  $meta['location'] = $finalUrl;
  $meta['bytes']    = strlen($html ?? '');

  if (!$html || $http >= 400) {
    $meta['status'] = 'error';
    $meta['note']   = 'lens fetch failed (http ' . $http . ')';
    return $meta;
  }
  if (stripos($html, 'detected unusual traffic') !== false
      || stripos($html, 'recaptcha') !== false
      || stripos($html, 'sorry/index') !== false) {
    $meta['status'] = 'blocked';
    $meta['note']   = 'captcha / unusual-traffic';
    return $meta;
  }
  // Lens UI is bootstrapped via AF_initDataCallback blobs. If they're missing,
  // the response is a no-results shell or an error page.
  if (stripos($html, 'AF_initDataCallback') === false) {
    $meta['status'] = 'empty';
    $meta['note']   = 'Lens served a shell page (no AF_initDataCallback) — image may have failed to fetch';
  }

  $meta['links'] = extract_amazon_urls($html);
  $meta['found'] = count($meta['links']);
  return $meta;
}

function decode_image($dataUrl) {
  if (!is_string($dataUrl) || $dataUrl === '') return [null, null, null, 'empty image'];
  $mime = 'image/jpeg';
  if (preg_match('#^data:([^;]+);base64,(.+)$#', $dataUrl, $m)) {
    $mime  = $m[1];
    $bytes = base64_decode($m[2], true);
  } else {
    $bytes = base64_decode($dataUrl, true);
  }
  if ($bytes === false || strlen($bytes) < 200) return [null, null, null, 'could not decode image bytes'];
  $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
  return [$bytes, $mime, $ext, null];
}

function extract_amazon_urls($html) {
  $clean = $html;
  $clean = str_replace(['\\/', '\\u002F', '\\u003D'], ['/', '/', '='], $clean);
  $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

  $hostRe = 'amazon\.(?:com|eg|ae|sa|de|co\.uk|fr|it|es|nl|pl|se|com\.tr|com\.au|com\.mx|com\.br|ca|in|sg|co\.jp|cn)';
  $pattern = '#https?://(?:www\.|m\.|smile\.)?' . $hostRe . '/[^"\s<>\\\'()\]\[]+#i';

  $out = [];
  if (preg_match_all($pattern, $clean, $m)) {
    foreach ($m[0] as $u) {
      $u = preg_replace('#[\\\\,;:]+$#', '', $u);
      $out[$u] = true;
    }
  }
  if (preg_match_all('#/url\?q=([^"&]+' . $hostRe . '[^"&]*)#i', $clean, $m)) {
    foreach ($m[1] as $raw) $out[urldecode($raw)] = true;
  }
  return array_keys($out);
}

function strip_amazon_tracking($u) {
  if (preg_match('#^(https?://[^/]+/(?:[^/?#]+/)?(?:dp|gp/product|gp/aw/d)/[A-Z0-9]{10})#i', $u, $m)) {
    return $m[1];
  }
  return preg_replace('/[?&](?:ref|ref_|tag|psc|th|linkCode|linkId).*$/i', '', $u);
}

function host_to_country($host) {
  $h = preg_replace('/^www\./', '', strtolower($host));
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
