<?php
// POST /api/google-lens-search.php
// Body:
//   { image:  "data:image/...;base64,..."  }                     (single image)
//   { images: ["data:...", "data:...", ...] }                    (batch — runs
//                                                                 Lens once per
//                                                                 image, merges
//                                                                 every amazon
//                                                                 link found)
//   optional: { debug: 1 }
//
// Reverse-image search via Google Lens — and ONLY Google Lens. We hit
// lens.google.com/v3/upload (not the legacy /searchbyimage/upload that
// modern Google now bounces to a no-results shell), then parse the rendered
// HTML for every amazon.<tld> URL it contains.
//
// Lens renders its visual-matches list into the initial HTML via
// AF_initDataCallback({...}) blocks — large JSON-escaped arrays that hold
// every match's page URL, thumbnail, and title. We strip the JSON escapes
// from the entire response and then sweep the whole document with a single
// amazon-URL regex, so we catch links whether they sit in an <a href>, an
// AF_initDataCallback blob, or any other inline JSON.

require_once __DIR__ . '/_db.php';
require_token();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  send_json(['error' => 'Use POST'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);

$debug = !empty($body['debug']);

// Accept either single "image" or "images" array. Normalize to a list.
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

// Hard cap — running Lens 10+ times in a single request would burn the
// 30-second PHP timeout and rate-limit our IP. Eight is plenty.
if (count($images) > 8) $images = array_slice($images, 0, 8);

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

// ── Run Lens for every uploaded image, merge raw amazon hits ─────────
$perImage = [];
$allHits  = []; // url => true (pre-filter de-dup)

foreach ($images as $idx => $dataUrl) {
  list($bytes, $mime, $filename, $err) = decode_image($dataUrl);
  if ($err) {
    $perImage[] = ['index' => $idx, 'status' => 'error', 'note' => $err, 'found' => 0];
    continue;
  }
  $meta = run_google_lens($bytes, $mime, $filename, $UA);
  $perImage[] = [
    'index'     => $idx,
    'status'    => $meta['status'],
    'note'      => $meta['note'],
    'location'  => $meta['location'],
    'bytes'     => $meta['bytes'] ?? 0,
    'found'     => $meta['found'],
  ];
  foreach ($meta['links'] as $u) $allHits[$u] = true;
}

// ── Filter to product pages + de-dup by ASIN+host ────────────────────
$seen = [];
$unique = [];
foreach (array_keys($allHits) as $u) {
  $u2 = strip_amazon_tracking($u);
  if (!preg_match('#/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})#i', $u2, $am)) continue;
  $host = parse_url($u2, PHP_URL_HOST) ?: '';
  if ($host === '') continue;
  $key = strtolower($host) . '|' . strtoupper($am[1]);
  if (isset($seen[$key])) continue;
  $seen[$key] = true;
  $unique[] = [
    'url'     => $u2,
    'host'    => preg_replace('/^www\./', '', strtolower($host)),
    'country' => host_to_country($host),
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
  'images'  => $perImage,        // per-image Lens diagnostics
];
if ($debug) $out['rawHitCount'] = count($allHits);
send_json($out);

// ══════════════════════════════════════════════════════════════════════
// Google Lens
// ══════════════════════════════════════════════════════════════════════

function run_google_lens($bytes, $mime, $filename, $UA) {
  $meta = ['status' => 'ok', 'note' => '', 'location' => '', 'bytes' => 0,
           'found' => 0, 'links' => []];

  $tmp = tempnam(sys_get_temp_dir(), 'glens_');
  file_put_contents($tmp, $bytes);
  $cfile = new CURLFile($tmp, $mime, $filename);

  // CONSENT + SOCS skip the EU consent interstitial. NID is the long-lived
  // anonymous identifier — without it Lens occasionally responds with a
  // sign-in wall on the first hit.
  $cookie = 'CONSENT=YES+cb; SOCS=CAESHAgBEhJnd3NfMjAyNDA2MDQtMF9SQzIaAmVuIAEaBgiAyMq6Bg';

  // The current Lens upload endpoint. stcs is just a millisecond timestamp;
  // re=df asks Lens to treat the request as a "drop image" upload, which is
  // what gives us the full visual-matches page (vs the cropped re-search
  // sidebar). hl=en-US forces English results — easier for our regex sweep.
  $uploadUrl = 'https://lens.google.com/v3/upload'
             . '?hl=en-US'
             . '&re=df'
             . '&stcs=' . (int) (microtime(true) * 1000)
             . '&ep=gisbubu';

  $ch = curl_init($uploadUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,         // capture the redirect first
    CURLOPT_HEADER         => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['encoded_image' => $cfile],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_HTTPHEADER     => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
      'Origin: https://lens.google.com',
      'Referer: https://lens.google.com/',
      'Cookie: ' . $cookie,
    ],
  ]);
  $res  = curl_exec($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);
  @unlink($tmp);

  if (!$res) {
    $meta['status'] = 'error'; $meta['note'] = 'lens upload network failed';
    return $meta;
  }

  $headerSize = $info['header_size'] ?? 0;
  $headers    = substr($res, 0, $headerSize);
  $location   = '';
  if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) $location = trim($m[1]);

  // Lens normally responds with 302 → /search?p=...  occasionally with 303.
  // If we got the search page in the body directly, use that.
  if ($location === '' && ($info['http_code'] ?? 0) === 200) {
    $body = substr($res, $headerSize);
    $meta['location'] = $uploadUrl;
    $meta['bytes']    = strlen($body);
    $meta['links']    = extract_amazon_urls($body);
    $meta['found']    = count($meta['links']);
    return $meta;
  }
  if ($location === '') {
    $meta['status'] = 'error';
    $meta['note']   = 'no redirect from lens upload (http ' . ($info['http_code'] ?? 0) . ')';
    return $meta;
  }
  if (strpos($location, 'http') !== 0) {
    $location = 'https://lens.google.com' . (strpos($location, '/') === 0 ? '' : '/') . $location;
  }
  $meta['location'] = $location;

  // ── Fetch the rendered Lens results page ────────────────────────────
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
      'Referer: https://lens.google.com/',
      'Cookie: ' . $cookie,
    ],
  ]);
  $html = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $meta['bytes'] = strlen($html ?? '');
  if (!$html || $http >= 400) {
    $meta['status'] = 'error';
    $meta['note']   = 'results fetch failed (http ' . $http . ')';
    return $meta;
  }
  if (stripos($html, 'detected unusual traffic') !== false
      || stripos($html, 'recaptcha') !== false
      || stripos($html, 'sorry/index') !== false) {
    $meta['status'] = 'blocked';
    $meta['note']   = 'captcha / unusual-traffic — try again later';
    return $meta;
  }

  $meta['links'] = extract_amazon_urls($html);
  $meta['found'] = count($meta['links']);
  // Distinguish "Lens ran but matched nothing" from "Lens didn't run at all"
  if ($meta['found'] === 0 && stripos($html, 'AF_initDataCallback') === false) {
    $meta['status'] = 'empty';
    $meta['note']   = 'Lens served a non-results page (no AF_initDataCallback found)';
  }
  return $meta;
}

// ══════════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════════

function decode_image($dataUrl) {
  if (!is_string($dataUrl) || $dataUrl === '') return [null, null, null, 'empty image'];
  $mime = 'image/jpeg';
  $bytes = null;
  if (preg_match('#^data:([^;]+);base64,(.+)$#', $dataUrl, $m)) {
    $mime  = $m[1];
    $bytes = base64_decode($m[2], true);
  } else {
    $bytes = base64_decode($dataUrl, true);
  }
  if ($bytes === false || $bytes === '' || strlen($bytes) < 200) {
    return [null, null, null, 'could not decode image bytes'];
  }
  $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
  return [$bytes, $mime, 'image.' . $ext, null];
}

// Pull every amazon.<tld> URL out of arbitrary HTML — including the
// AF_initDataCallback JSON blobs Lens uses to ship visual-match data into
// the page. We strip the three common escape forms first so the regex
// stays simple and the same pattern matches every variant.
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
  // Wrapped form: /url?q=<encoded amazon URL>
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
