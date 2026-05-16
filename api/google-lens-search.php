<?php
// POST /api/google-lens-search.php
// Body: JSON  { image: "<data:image/...;base64,...>"  OR  imageUrl: "<https-url>"
//             , debug?: 1 }
//
// Reverse-image search across MULTIPLE engines (Google + Yandex + Bing) and
// merge every amazon.* product link they return. Yandex is the strongest of
// the three for finding "the same product elsewhere" — it tolerates the
// Hostinger datacenter IP that Google often routes to its JS-only Lens page,
// and it indexes Arabic/Middle-East listings (amazon.eg / amazon.ae / .sa)
// better than Bing.
//
// Implementation notes
// ─────────────────────
// • We run each engine in a try/catch — a single failed engine does not break
//   the others. The response.engines field reports per-engine status so the
//   frontend can show "Google failed but Yandex found 12 links".
// • Link extraction is intentionally broad: we strip JSON-escaped slashes
//   from the raw HTML and then sweep with a single regex that catches every
//   amazon.<tld> URL whether it sits in an <a href>, a JSON blob, or a data-
//   attribute. The /dp/ASIN filter is applied AFTER merging, not per-engine.
// • debug=1 echoes the redirect URL we landed on and the byte length of each
//   engine's response so you can tell whether an empty result means "engine
//   blocked" vs "engine ran but nothing matched".

require_once __DIR__ . '/_db.php';
require_token();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  send_json(['error' => 'Use POST'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);

$imageDataUrl = isset($body['image']) ? (string)$body['image'] : '';
$imageUrl     = isset($body['imageUrl']) ? trim((string)$body['imageUrl']) : '';
$debug        = !empty($body['debug']);

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
  list($bytes, $mime) = fetch_image_url($imageUrl);
  if (!$bytes) send_json(['error' => 'Failed to fetch imageUrl.'], 400);
  $filename = 'image.' . (strpos($mime, 'png') !== false ? 'png' : 'jpg');
}

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

// ── Run each engine in turn ──────────────────────────────────────────
$engines  = [];
$rawHits  = []; // url => true (for de-dup before filtering)
$rawLines = []; // list of [url, engine]

list($gLinks, $gMeta) = engine_google($bytes, $mime, $filename, $UA);
$engines['google'] = $gMeta;
foreach ($gLinks as $u) { if (!isset($rawHits[$u])) { $rawHits[$u] = true; $rawLines[] = [$u, 'google']; } }

list($yLinks, $yMeta) = engine_yandex($bytes, $mime, $filename, $UA);
$engines['yandex'] = $yMeta;
foreach ($yLinks as $u) { if (!isset($rawHits[$u])) { $rawHits[$u] = true; $rawLines[] = [$u, 'yandex']; } }

list($bLinks, $bMeta) = engine_bing($bytes, $mime, $filename, $UA);
$engines['bing'] = $bMeta;
foreach ($bLinks as $u) { if (!isset($rawHits[$u])) { $rawHits[$u] = true; $rawLines[] = [$u, 'bing']; } }

// ── Filter to product pages + de-dup by ASIN+host ────────────────────
$seen = [];
$unique = [];
foreach ($rawLines as $pair) {
  $u = strip_amazon_tracking($pair[0]);
  if (!preg_match('#/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})#i', $u, $am)) continue;
  $host = parse_url($u, PHP_URL_HOST) ?: '';
  if ($host === '') continue;
  $key = strtolower($host) . '|' . strtoupper($am[1]);
  if (isset($seen[$key])) continue;
  $seen[$key] = true;
  $unique[] = [
    'url'     => $u,
    'host'    => preg_replace('/^www\./', '', strtolower($host)),
    'country' => host_to_country($host),
    'asin'    => strtoupper($am[1]),
    'engine'  => $pair[1],
  ];
}

// Sort: EG first, then alphabetical country code.
usort($unique, function ($a, $b) {
  if ($a['country'] === 'EG' && $b['country'] !== 'EG') return -1;
  if ($b['country'] === 'EG' && $a['country'] !== 'EG') return 1;
  return strcmp($a['country'], $b['country']);
});

$out = [
  'count'   => count($unique),
  'results' => $unique,
  'engines' => $engines,
];
if ($debug) $out['rawHitCount'] = count($rawHits);
send_json($out);

// ══════════════════════════════════════════════════════════════════════
// Engines
// ══════════════════════════════════════════════════════════════════════

// Google reverse image search via /searchbyimage/upload. The 302 used to
// land on www.google.com/search?tbs=sbi:… (classic HTML results) but Google
// now usually redirects to lens.google.com — a JS-only shell with nothing
// parseable. We try anyway: sometimes the classic path still hits, and even
// the Lens HTML contains a few JSON-embedded amazon URLs.
function engine_google($bytes, $mime, $filename, $UA) {
  $meta = ['status' => 'ok', 'found' => 0, 'location' => '', 'note' => ''];
  $links = [];

  $tmp = tempnam(sys_get_temp_dir(), 'glens_');
  file_put_contents($tmp, $bytes);
  $cfile = new CURLFile($tmp, $mime, $filename);

  $cookie = 'CONSENT=YES+cb; SOCS=CAESHAgBEhJnd3NfMjAyNDA2MDQtMF9SQzIaAmVuIAEaBgiAyMq6Bg';

  $ch = curl_init('https://www.google.com/searchbyimage/upload');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER         => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['encoded_image' => $cfile, 'image_url' => '', 'sbisrc' => 'cr_1'],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_HTTPHEADER     => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
      'Cookie: ' . $cookie,
    ],
  ]);
  $res = curl_exec($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);
  @unlink($tmp);

  if (!$res) { $meta['status'] = 'error'; $meta['note'] = 'upload network failed'; return [$links, $meta]; }

  $headerSize = $info['header_size'] ?? 0;
  $headers    = substr($res, 0, $headerSize);
  $location   = '';
  if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) $location = trim($m[1]);
  if ($location === '') {
    $meta['status'] = 'error';
    $meta['note']   = 'no redirect from upload (http ' . ($info['http_code'] ?? 0) . ')';
    return [$links, $meta];
  }
  if (strpos($location, 'http') !== 0) {
    $location = 'https://www.google.com' . (strpos($location, '/') === 0 ? '' : '/') . $location;
  }
  $meta['location'] = $location;

  // Fetch the results page.
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
      'Cookie: ' . $cookie,
      'Referer: https://www.google.com/',
    ],
  ]);
  $html = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $meta['bytes'] = strlen($html ?? '');
  if (!$html || $http >= 400) {
    $meta['status'] = 'error';
    $meta['note']   = 'results fetch failed (http ' . $http . ')';
    return [$links, $meta];
  }
  if (stripos($html, 'detected unusual traffic') !== false || stripos($html, 'recaptcha') !== false) {
    $meta['status'] = 'blocked';
    $meta['note']   = 'captcha / unusual-traffic';
    return [$links, $meta];
  }

  $links = extract_amazon_urls($html);
  $meta['found'] = count($links);
  return [$links, $meta];
}

// Yandex reverse image search. Yandex returns proper HTML results — including
// a "Sites containing information about the image" section — even from
// datacenter IPs. It's the most reliable engine for the "find this exact
// product everywhere it's sold" use case.
function engine_yandex($bytes, $mime, $filename, $UA) {
  $meta = ['status' => 'ok', 'found' => 0, 'location' => '', 'note' => ''];
  $links = [];

  $tmp = tempnam(sys_get_temp_dir(), 'yand_');
  file_put_contents($tmp, $bytes);
  $cfile = new CURLFile($tmp, $mime, $filename);

  // Yandex's image upload endpoint. format=json-raw returns a JSON envelope
  // containing the redirect URL we need to follow for full HTML results.
  $uploadUrl = 'https://yandex.com/images-apphost/image-download?cbird=37&images_avatars_size=preview&images_avatars_namespace=images-cbir';
  $ch = curl_init($uploadUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['upfile' => $cfile],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_HTTPHEADER     => [
      'Accept: */*',
      'Accept-Language: en-US,en;q=0.9',
      'Origin: https://yandex.com',
      'Referer: https://yandex.com/images/',
    ],
  ]);
  $upRes = curl_exec($ch);
  $upHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  @unlink($tmp);

  // Parse the JSON response — it carries the image identifier we feed into
  // the public reverse-search URL.
  $imgKey = '';
  if ($upRes) {
    $j = json_decode($upRes, true);
    if (is_array($j)) {
      // Yandex returns either {url: "//avatars.../...", ...} or {image_shard_id: ..., image_filename: ...}
      if (!empty($j['url'])) {
        $imgKey = $j['url'];
      } elseif (!empty($j['image_shard_id']) && !empty($j['image_filename'])) {
        $imgKey = '/' . $j['image_shard_id'] . '/' . $j['image_filename'];
      }
    }
    // Fallback — sometimes Yandex still uses the older response shape.
    if (!$imgKey && preg_match('#"url"\s*:\s*"([^"]+)"#', $upRes, $m)) {
      $imgKey = $m[1];
    }
  }
  if (!$imgKey) {
    $meta['status'] = 'error';
    $meta['note']   = 'upload failed (http ' . $upHttp . ', len ' . strlen($upRes ?? '') . ')';
    return [$links, $meta];
  }

  // Build the cbir search URL. cbir_id is a colon-joined "shard:filename".
  $cbir = preg_replace('#^https?:|^//#', '', $imgKey);
  $cbir = ltrim($cbir, '/');
  $cbirId = str_replace('/', ':', preg_replace('#^[^/]+/get-images-cbir/#', '', $cbir));
  $searchUrl = 'https://yandex.com/images/search?cbir_id=' . rawurlencode($cbirId)
             . '&rpt=imageview&cbir_page=sites';
  $meta['location'] = $searchUrl;

  $ch = curl_init($searchUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => 'gzip, deflate',
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_HTTPHEADER     => [
      'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
      'Accept-Language: en-US,en;q=0.9',
      'Referer: https://yandex.com/images/',
    ],
  ]);
  $html = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $meta['bytes'] = strlen($html ?? '');
  if (!$html || $http >= 400) {
    $meta['status'] = 'error';
    $meta['note']   = 'sites fetch failed (http ' . $http . ')';
    return [$links, $meta];
  }
  if (stripos($html, 'showcaptcha') !== false || stripos($html, 'captcha') !== false) {
    $meta['status'] = 'blocked';
    $meta['note']   = 'yandex captcha';
    return [$links, $meta];
  }

  $links = extract_amazon_urls($html);
  $meta['found'] = count($links);
  return [$links, $meta];
}

// Bing visual search. Public endpoint at bing.com/images/search?view=detailv2
// &iss=sbi — accepts a multipart upload and returns HTML with "Pages with
// this image" links. Often returns 0 hits but it's cheap to try as a third
// signal and covers some listings Google/Yandex miss.
function engine_bing($bytes, $mime, $filename, $UA) {
  $meta = ['status' => 'ok', 'found' => 0, 'location' => '', 'note' => ''];
  $links = [];

  $tmp = tempnam(sys_get_temp_dir(), 'bing_');
  file_put_contents($tmp, $bytes);
  $cfile = new CURLFile($tmp, $mime, $filename);

  $ch = curl_init('https://www.bing.com/images/search?view=detailv2&iss=sbiupload&form=SBIVSP&sbisrc=ImgDropper');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['imgurl' => '', 'cbir' => 'sbi', 'imageBin' => base64_encode($bytes)],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => 'gzip, deflate',
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_HTTPHEADER     => [
      'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
      'Accept-Language: en-US,en;q=0.9',
      'Origin: https://www.bing.com',
      'Referer: https://www.bing.com/visualsearch',
    ],
  ]);
  $html = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  curl_close($ch);
  @unlink($tmp);

  $meta['location'] = $finalUrl;
  $meta['bytes']    = strlen($html ?? '');
  if (!$html || $http >= 400) {
    $meta['status'] = 'error';
    $meta['note']   = 'http ' . $http;
    return [$links, $meta];
  }
  $links = extract_amazon_urls($html);
  $meta['found'] = count($links);
  return [$links, $meta];
}

// ══════════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════════

function fetch_image_url($url) {
  $ch = curl_init($url);
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
  if (stripos($mime, 'image/') !== 0) $mime = 'image/jpeg';
  return [$bytes, $mime];
}

// Pull every amazon.<tld> URL out of an arbitrary HTML/JSON blob. Handles:
//   • direct hrefs:      href="https://www.amazon.com/dp/B0XXX"
//   • Google /url? wraps: href="/url?q=https://www.amazon.com/dp/..."
//   • JSON-escaped:      "https:\/\/www.amazon.com\/dp\/B0XXX"
//   • HTML-entity escaped: &amp; → &
function extract_amazon_urls($html) {
  // Decode the three common escape forms before matching so the regex stays simple.
  $clean = $html;
  $clean = str_replace(['\\/', '\\u002F', '\\u003D'], ['/', '/', '='], $clean);
  $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

  $hostRe = 'amazon\.(?:com|eg|ae|sa|de|co\.uk|fr|it|es|nl|pl|se|com\.tr|com\.au|com\.mx|com\.br|ca|in|sg|co\.jp|cn)';
  $pattern = '#https?://(?:www\.|m\.|smile\.)?' . $hostRe . '/[^"\s<>\\\'()\]\[]+#i';

  $out = [];
  if (preg_match_all($pattern, $clean, $m)) {
    foreach ($m[0] as $u) {
      // Strip trailing junk that the greedy class sometimes pulls in.
      $u = preg_replace('#[\\\\,;:]+$#', '', $u);
      $out[$u] = true;
    }
  }
  // Also pull /url?q=<amazon-url> wrappers separately — they appear in some
  // Google variants without the protocol because q= is URL-encoded.
  if (preg_match_all('#/url\?q=([^"&]+' . $hostRe . '[^"&]*)#i', $clean, $m)) {
    foreach ($m[1] as $raw) {
      $u = urldecode($raw);
      $out[$u] = true;
    }
  }
  return array_keys($out);
}

function strip_amazon_tracking($u) {
  // Anchor the URL at /dp/ASIN or /gp/product/ASIN or /gp/aw/d/ASIN and drop
  // everything after. Removes tag=, ref=, language paths, etc.
  if (preg_match('#^(https?://[^/]+/(?:[^/?#]+/)?(?:dp|gp/product|gp/aw/d)/[A-Z0-9]{10})#i', $u, $m)) {
    return $m[1];
  }
  $u = preg_replace('/[?&](?:ref|ref_|tag|psc|th|linkCode|linkId).*$/i', '', $u);
  return $u;
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
