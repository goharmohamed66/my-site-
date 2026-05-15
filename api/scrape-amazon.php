<?php
// /api/scrape-amazon.php?url=<encoded amazon product url>
//
// Cookie-authenticated Amazon scraper. Walks the /product-reviews/
// paginator (the same one the "Show 10 more reviews" button drives)
// using the user's session cookies to capture every review Amazon
// will serve to a logged-in visitor — typically 50–500+ per product.
//
// The cookies are loaded from a connector with provider=amazon_session
// matching the URL's domain (so amazon.eg URLs use a different cookie
// set than amazon.com). If no matching cookies are saved, this returns
// an error and the caller falls back to Apify.
require_once __DIR__ . '/_db.php';
require_token();

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($url === '' || !preg_match('#^https://(www\.)?amazon\.[a-z.]+/#i', $url)) {
  send_json(['error' => 'A valid amazon.* product URL is required.'], 400);
}

$asin = '';
if (preg_match('#/dp/([A-Z0-9]{10})(?:[/?]|$)#i', $url, $m)) $asin = strtoupper($m[1]);
elseif (preg_match('#/gp/product/([A-Z0-9]{10})(?:[/?]|$)#i', $url, $m)) $asin = strtoupper($m[1]);
elseif (preg_match('#/product-reviews/([A-Z0-9]{10})(?:[/?]|$)#i', $url, $m)) $asin = strtoupper($m[1]);
if ($asin === '') send_json(['error' => 'Could not find an ASIN in the URL.'], 400);

$host = parse_url($url, PHP_URL_HOST) ?: '';
$domain = preg_replace('/^www\./i', '', strtolower($host));

$maxPages = isset($_GET['maxPages']) ? max(1, min(100, (int)$_GET['maxPages'])) : 60;

// ── Load cookies for this domain ─────────────────────────────────────
$pdo = db();
$stmt = $pdo->prepare("SELECT token, meta FROM connectors WHERE type='scraping' AND provider='amazon_session'");
$stmt->execute();
$rows = $stmt->fetchAll();
$cookieRow = null;
foreach ($rows as $r) {
  $meta = json_decode($r['meta'] ?? '{}', true) ?: [];
  if (strtolower($meta['domain'] ?? '') === $domain) { $cookieRow = $r; break; }
}
// Fallback: any saved Amazon session row, even if domain doesn't match
if (!$cookieRow && count($rows)) $cookieRow = $rows[0];
if (!$cookieRow || empty($cookieRow['token'])) {
  send_json(['error' => 'No Amazon Session cookies found for ' . $domain . '. Open Settings → Connectors → Amazon Session and paste your cookies.'], 400);
}
$cookies = $cookieRow['token'];

// ── Helpers ──────────────────────────────────────────────────────────
function curl_html($url, $cookies) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => 'gzip, deflate',
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
      'Cookie: ' . $cookies,
      'Cache-Control: no-cache',
    ],
  ]);
  $data = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$http, $data];
}

function clean_text($s) {
  $s = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}
function clean_multiline($s) {
  $s = preg_replace('#</p>#i', "\n", (string)$s);
  $s = preg_replace('#<br\s*/?>#i', "\n", $s);
  $s = strip_tags($s);
  $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
  $s = preg_replace('/[ \t]+/u', ' ', $s);
  $s = preg_replace('/[ \t]+\n/u', "\n", $s);
  $s = preg_replace('/\n{3,}/u', "\n\n", $s);
  return trim($s);
}

// Extract product details from the main /dp/ page HTML.
function parse_product_details($html) {
  $out = ['title'=>'','brand'=>'','price'=>'','rating'=>'','ratingCount'=>'','features'=>[],'description'=>'','image'=>'','breadcrumb'=>'','images'=>[]];
  if (preg_match('#<span[^>]*id=["\']productTitle["\'][^>]*>([\s\S]*?)</span>#', $html, $m)) $out['title'] = clean_text($m[1]);
  if (preg_match('#<a[^>]*id=["\']bylineInfo["\'][^>]*>([\s\S]*?)</a>#', $html, $m)) {
    $out['brand'] = preg_replace('/^(?:Visit the\s+|Brand:\s*)/iu', '', clean_text($m[1]));
    $out['brand'] = preg_replace('/\s+Store$/iu', '', $out['brand']);
  }
  if (preg_match('#<span[^>]*class=["\'][^"\']*a-offscreen[^"\']*["\'][^>]*>([^<]+)</span>#', $html, $m)) $out['price'] = clean_text($m[1]);
  if (preg_match('#data-hook=["\']average-star-rating["\'][\s\S]*?>([\d.]+) out of#i', $html, $m)) $out['rating'] = $m[1];
  if (preg_match('#id=["\']acrCustomerReviewText["\'][^>]*>([\s\S]*?)</span>#', $html, $m)) $out['ratingCount'] = clean_text($m[1]);
  if (preg_match('#<div[^>]*id=["\']feature-bullets["\'][^>]*>([\s\S]*?)</div>\s*</div>#', $html, $m)) {
    if (preg_match_all('#<span[^>]*class=["\'][^"\']*a-list-item[^"\']*["\'][^>]*>([\s\S]*?)</span>#', $m[1], $bms)) {
      foreach ($bms[1] as $b) {
        $t = clean_text($b);
        if ($t && stripos($t, 'see more') === false && strlen($t) > 3) $out['features'][] = $t;
      }
    }
  }
  if (preg_match('#<div[^>]*id=["\']productDescription["\'][^>]*>([\s\S]*?)</div>#', $html, $m)) {
    $d = clean_multiline($m[1]);
    if (!(preg_match('/^[A-Z_]+$/u', $d) && strlen($d) < 50)) $out['description'] = $d;
  }
  if (preg_match_all('#"hiRes":"([^"]+)"#', $html, $ims)) {
    foreach ($ims[1] as $u) $out['images'][] = stripcslashes($u);
    $out['images'] = array_values(array_unique($out['images']));
    if (!empty($out['images'])) $out['image'] = $out['images'][0];
  } elseif (preg_match('#<img[^>]*id=["\']landingImage["\'][^>]*src=["\']([^"\']+)["\']#', $html, $m)) {
    $out['image'] = $m[1]; $out['images'] = [$m[1]];
  }
  if (preg_match('#<div[^>]*id=["\']wayfinding-breadcrumbs_feature_div["\'][^>]*>([\s\S]*?)</div>\s*</div>#', $html, $m)) {
    if (preg_match_all('#<a[^>]*class=["\'][^"\']*a-link-normal[^"\']*["\'][^>]*>([\s\S]*?)</a>#', $m[1], $bms)) {
      $crumbs = array_filter(array_map('clean_text', $bms[1]));
      $out['breadcrumb'] = implode(' › ', $crumbs);
    }
  }
  return $out;
}

// Extract reviews from a /product-reviews/ page HTML. Each review sits
// inside <li id="..." data-hook="review">.
function parse_reviews_from_page($html) {
  $out = [];
  if (!preg_match_all('#<li[^>]*id=["\'](R[A-Z0-9]+)["\'][^>]*data-hook=["\']review["\'][\s\S]*?</li>#u', $html, $matches, PREG_SET_ORDER)) return $out;
  foreach ($matches as $mm) {
    $block = $mm[0];
    $id    = $mm[1];

    $author = '';
    if (preg_match('#<span[^>]*class=["\'][^"\']*a-profile-name[^"\']*["\'][^>]*>([\s\S]*?)</span>#', $block, $am)) $author = clean_text($am[1]);

    $rating = null;
    if (preg_match('#data-hook=["\']review-star-rating["\'][^>]*>[\s\S]*?([\d.]+)\s*out of\s*5#i', $block, $rm)) $rating = (float)$rm[1];
    elseif (preg_match('#class=["\'][^"\']*a-star-(\d)[^"\']*["\']#', $block, $rm)) $rating = (int)$rm[1];

    $title = '';
    if (preg_match('#<a[^>]*data-hook=["\']review-title["\'][^>]*>([\s\S]*?)</a>#', $block, $tm)) {
      if (preg_match_all('#<span[^>]*>([\s\S]*?)</span>#', $tm[1], $sms)) {
        $cands = array_filter(array_map('clean_text', $sms[1]),
          function ($s) { return $s && !preg_match('/^[\d.]+\s*out of\s*5/i', $s); });
        if ($cands) $title = array_values($cands)[count($cands) - 1];
      }
    }
    if ($title === '' && preg_match('#data-hook=["\']review-title["\'][^>]*>([\s\S]*?)</[a-z0-9]+>#', $block, $tm)) {
      $title = clean_text($tm[1]);
    }

    $body = '';
    if (preg_match('#data-hook=["\']review-body["\'][^>]*>[\s\S]*?<span[^>]*>([\s\S]*?)</span>#', $block, $bm)) {
      $body = clean_multiline($bm[1]);
    }

    $date = '';
    if (preg_match('#data-hook=["\']review-date["\'][^>]*>([\s\S]*?)</span>#', $block, $dm)) $date = clean_text($dm[1]);

    $verified = (bool) preg_match('#data-hook=["\']avp-badge(?:-linkless)?["\']#', $block);

    $variant = '';
    if (preg_match('#data-hook=["\']format-strip(?:-linkless)?["\'][^>]*>([\s\S]*?)</(?:a|span)>#', $block, $vm)) $variant = clean_text($vm[1]);

    $out[] = compact('id','author','rating','title','body','date','verified','variant');
  }
  return $out;
}

function find_next_page_token($html) {
  // The "Show 10 more reviews" button carries data-reviews-state-param
  // with a JSON blob containing nextPageToken (HTML-entity-encoded).
  if (preg_match('#nextPageToken&quot;:&quot;([^&]+)&quot;#', $html, $m)) return $m[1];
  if (preg_match('#"nextPageToken":"([^"]+)"#', $html, $m)) return $m[1];
  return '';
}

// ── 1. Fetch the main product page (cookies attached) ────────────────
$productPageUrl = 'https://www.' . $domain . '/dp/' . $asin;
list($http, $html) = curl_html($productPageUrl, $cookies);
if ($http !== 200 || !$html) {
  send_json(['error' => 'Failed to fetch product page', 'http' => $http], 502);
}
if (preg_match('#/ap/signin#', $html) && strlen($html) < 100000) {
  send_json(['error' => 'Cookies are expired or invalid (Amazon redirected to signin). Update them in Settings → Connectors → Amazon Session.'], 401);
}
$product = parse_product_details($html);

// ── 2. Walk the /product-reviews/ paginator ──────────────────────────
$reviewsById = [];
$pageNumber  = 1;
$nextToken   = '';
$pagesFetched = 0;
$emptyStreak  = 0;
$reviewsBase = 'https://www.' . $domain . '/-/en/product-reviews/' . $asin . '/';

while ($pagesFetched < $maxPages) {
  $params = [
    '_encoding'   => 'UTF8',
    'ie'          => 'UTF8',
    'reviewerType'=> 'all_reviews',
    'pageNumber'  => $pageNumber,
  ];
  if ($nextToken !== '') $params['nextPageToken'] = $nextToken;
  $u = $reviewsBase . '?' . http_build_query($params);
  list($h, $body) = curl_html($u, $cookies);
  $pagesFetched++;
  if ($h !== 200 || !$body) { $emptyStreak++; if ($emptyStreak >= 2) break; continue; }
  if (preg_match('#/ap/signin#', $body) && strlen($body) < 100000) {
    send_json(['error' => 'Cookies expired mid-pagination. Update them in Settings.'], 401);
  }

  $items = parse_reviews_from_page($body);
  $addedThisPage = 0;
  foreach ($items as $rv) {
    if (!isset($reviewsById[$rv['id']])) {
      $reviewsById[$rv['id']] = $rv;
      $addedThisPage++;
    }
  }
  $newTok = find_next_page_token($body);
  if ($addedThisPage === 0) { $emptyStreak++; if ($emptyStreak >= 2) break; }
  else { $emptyStreak = 0; }
  if ($newTok === '' || $newTok === $nextToken) break;
  $nextToken = $newTok;
  $pageNumber++;
}

$reviews = array_values($reviewsById);

send_json([
  'ok'          => true,
  'url'         => $url,
  'asin'        => $asin,
  'domain'      => $domain,
  'title'       => $product['title'],
  'brand'       => $product['brand'],
  'price'       => $product['price'],
  'rating'      => $product['rating'],
  'ratingCount' => $product['ratingCount'],
  'features'    => $product['features'],
  'description' => $product['description'],
  'image'       => $product['image'],
  'images'      => $product['images'],
  'breadcrumb'  => $product['breadcrumb'],
  'reviews'     => $reviews,
  'stats'       => [
    'reviewsCaptured' => count($reviews),
    'reviewPagesFetched' => $pagesFetched,
  ],
]);
