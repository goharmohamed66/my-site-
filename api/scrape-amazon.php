<?php
// /api/scrape-amazon.php?url=<encoded amazon product url>
//
// Free programmatic Amazon product scraper. Pulls product details +
// whatever reviews Amazon serves to an anonymous visitor on the main
// product page (typically 8-12 per request — the /product-reviews/
// subpage redirects to /ap/signin/ without an authenticated session,
// so deeper pagination isn't possible from a stateless backend).
//
// Returns JSON with the same envelope shape as scrape-abjjad.php so
// the frontend can render both with one normalize function.
require_once __DIR__ . '/_db.php';
require_token();

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($url === '' || !preg_match('#^https://(www\.)?amazon\.[a-z.]+/#i', $url)) {
  send_json(['error' => 'A valid amazon.* product URL is required.'], 400);
}

// Pull ASIN out of /dp/{ASIN}/ or /gp/product/{ASIN}/
$asin = '';
if (preg_match('#/dp/([A-Z0-9]{10})(?:[/?]|$)#i', $url, $m)) $asin = strtoupper($m[1]);
elseif (preg_match('#/gp/product/([A-Z0-9]{10})(?:[/?]|$)#i', $url, $m)) $asin = strtoupper($m[1]);
if ($asin === '') {
  send_json(['error' => 'Could not find an ASIN (/dp/XXXXXXXXXX) in the URL.'], 400);
}

// ── helpers ──────────────────────────────────────────────────────────
function curl_text($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => 'gzip, deflate',
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language: en-US,en;q=0.9',
      'Accept-Encoding: gzip, deflate',
      'Cache-Control: no-cache',
      'Sec-Fetch-Dest: document',
      'Sec-Fetch-Mode: navigate',
      'Sec-Fetch-Site: none',
      'Upgrade-Insecure-Requests: 1',
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

// ── 1. Fetch the product page ────────────────────────────────────────
[$http, $html] = curl_text($url);
if ($http !== 200 || !$html) {
  send_json(['error' => 'Failed to fetch the product page', 'http' => $http], 502);
}
// Bot-wall detection — Amazon serves these intermittently. The page is
// huge (~1.3MB normally); the captcha/dogs page is tiny.
if (strlen($html) < 50000 && preg_match('#Sorry, we just need|To discuss automated|Enter the characters#i', $html)) {
  send_json(['error' => 'Amazon returned a bot-wall page. Try again in a minute, or use a different network.'], 503);
}

// ── 2. Parse product details ─────────────────────────────────────────
$title = '';
if (preg_match('#<span[^>]*id=["\']productTitle["\'][^>]*>([\s\S]*?)</span>#', $html, $m)) {
  $title = clean_text($m[1]);
}

$brand = '';
if (preg_match('#<a[^>]*id=["\']bylineInfo["\'][^>]*>([\s\S]*?)</a>#', $html, $m)) {
  $brand = clean_text($m[1]);
  // "Visit the X Store" / "Brand: X" → take the X
  $brand = preg_replace('/^(?:Visit the\s+|Brand:\s*)/iu', '', $brand);
  $brand = preg_replace('/\s+Store$/iu', '', $brand);
}

$price = '';
if (preg_match('#<span[^>]*class=["\'][^"\']*a-offscreen[^"\']*["\'][^>]*>([^<]+)</span>#', $html, $m)) {
  $price = clean_text($m[1]);
}

$rating = '';
if (preg_match('#<span[^>]*data-hook=["\']rating-out-of-text["\'][^>]*>([\s\S]*?)</span>#', $html, $m)) {
  $rating = clean_text($m[1]);
} elseif (preg_match('#data-hook=["\']average-star-rating["\'][\s\S]*?>([\d.]+) out of#i', $html, $m)) {
  $rating = $m[1];
}

$ratingCount = '';
if (preg_match('#<span[^>]*data-hook=["\']total-review-count["\'][^>]*>([\s\S]*?)</span>#', $html, $m)) {
  $ratingCount = clean_text($m[1]);
} elseif (preg_match('#id=["\']acrCustomerReviewText["\'][^>]*>([\s\S]*?)</span>#', $html, $m)) {
  $ratingCount = clean_text($m[1]);
}

// Feature bullets (the "About this item" list)
$features = [];
if (preg_match('#<div[^>]*id=["\']feature-bullets["\'][^>]*>([\s\S]*?)</div>\s*</div>#', $html, $m)) {
  $section = $m[1];
  if (preg_match_all('#<span[^>]*class=["\'][^"\']*a-list-item[^"\']*["\'][^>]*>([\s\S]*?)</span>#', $section, $bms)) {
    foreach ($bms[1] as $b) {
      $t = clean_text($b);
      // Skip the "See more product details" link variants
      if ($t && stripos($t, 'see more') === false && strlen($t) > 3) {
        $features[] = $t;
      }
    }
  }
}

// Product description (varies — sometimes in #productDescription, sometimes
// embedded in A+ content). Try the simple block first. Filter out the
// Amazon catalog placeholders ("PEST_CONTROL_DEVICE", "RECREATION_BALL"
// etc.) that some sellers leave instead of real copy.
$description = '';
if (preg_match('#<div[^>]*id=["\']productDescription["\'][^>]*>([\s\S]*?)</div>#', $html, $m)) {
  $description = clean_multiline($m[1]);
  if (preg_match('/^[A-Z_]+$/u', $description) && strlen($description) < 50) {
    $description = '';
  }
}

// Thumbnail / hi-res cover image
$image = '';
if (preg_match('#"hiRes":"([^"]+)"#', $html, $m)) {
  $image = stripcslashes($m[1]);
} elseif (preg_match('#<img[^>]*id=["\']landingImage["\'][^>]*src=["\']([^"\']+)["\']#', $html, $m)) {
  $image = $m[1];
}

// Breadcrumb / category trail
$breadcrumb = '';
if (preg_match('#<div[^>]*id=["\']wayfinding-breadcrumbs_feature_div["\'][^>]*>([\s\S]*?)</div>\s*</div>#', $html, $m)) {
  $bc = $m[1];
  if (preg_match_all('#<a[^>]*class=["\'][^"\']*a-link-normal[^"\']*["\'][^>]*>([\s\S]*?)</a>#', $bc, $bms)) {
    $crumbs = array_map('clean_text', $bms[1]);
    $crumbs = array_filter($crumbs);
    $breadcrumb = implode(' › ', $crumbs);
  }
}

// ── 3. Parse review blocks ───────────────────────────────────────────
// Each review wrapper has data-hook="review". The block runs from there
// through the next data-hook="review" or end of the reviews container.
$reviews = [];
if (preg_match_all('#<[^>]+data-hook=["\']review["\'][^>]*>([\s\S]*?)(?=<[^>]+data-hook=["\']review["\']|<div[^>]*id=["\']reviews-medley-footer["\']|$)#u', $html, $blocks)) {
  foreach ($blocks[0] as $block) {
    // Author name
    $author = '';
    if (preg_match('#<span[^>]*class=["\'][^"\']*a-profile-name[^"\']*["\'][^>]*>([\s\S]*?)</span>#', $block, $am)) {
      $author = clean_text($am[1]);
    }

    // Star rating — embedded in class "a-star-N" or text "N out of 5"
    $reviewRating = null;
    if (preg_match('#data-hook=["\']review-star-rating["\'][^>]*>[\s\S]*?([\d.]+)\s*out of\s*5#i', $block, $rm)) {
      $reviewRating = (float)$rm[1];
    } elseif (preg_match('#class=["\'][^"\']*a-star-(\d)[^"\']*["\']#', $block, $rm)) {
      $reviewRating = (int)$rm[1];
    }

    // Review title — Amazon ships two markups depending on the surface:
    //  1. Detail-page reviews: <a data-hook="review-title"> with the star
    //     icon, hidden text, and the actual title in the LAST <span>.
    //  2. Top-reviews carousel: <h5 data-hook="reviewTitle">title</h5>.
    $reviewTitle = '';
    if (preg_match('#<a[^>]*data-hook=["\']review-title["\'][^>]*>([\s\S]*?)</a>#', $block, $tm)) {
      if (preg_match_all('#<span[^>]*>([\s\S]*?)</span>#', $tm[1], $sms)) {
        $candidates = array_filter(array_map('clean_text', $sms[1]),
          function ($s) { return $s && !preg_match('/^[\d.]+\s*out of\s*5/i', $s); });
        if ($candidates) $reviewTitle = array_values($candidates)[count($candidates) - 1];
      }
    }
    if ($reviewTitle === '' && preg_match('#data-hook=["\']reviewTitle["\'][^>]*>([\s\S]*?)</h5>#', $block, $tm2)) {
      $reviewTitle = clean_text($tm2[1]);
    }

    // Review body — three possible markups, in order of preference:
    //  1. data-hook="review-body" → text is in the first nested <span>.
    //  2. data-hook="reviewRichContentContainer" → text in nested <p><span>.
    //  3. data-hook="reviewText" → fallback wrapper, also has <p><span>.
    $body = '';
    if (preg_match('#data-hook=["\']review-body["\'][^>]*>[\s\S]*?<span[^>]*>([\s\S]*?)</span>#', $block, $bm)) {
      $body = clean_multiline($bm[1]);
    }
    if ($body === '' && preg_match('#data-hook=["\']reviewRichContentContainer["\'][^>]*>([\s\S]*?)</div>#', $block, $bm2)) {
      $body = clean_multiline($bm2[1]);
    }
    if ($body === '' && preg_match('#data-hook=["\']reviewText["\'][^>]*>([\s\S]*?)</div>\s*</div>\s*</div>#', $block, $bm3)) {
      // Strip the collapsed/expanded helper spans then take the text.
      $inner = preg_replace('#<div[^>]*class=["\'][^"\']*a-teaser-describedby[^"\']*["\'][^>]*>[\s\S]*?</div>#', '', $bm3[1]);
      $body = clean_multiline($inner);
    }

    // Date — "Reviewed in Egypt on 5 June 2025"
    $date = '';
    if (preg_match('#data-hook=["\']review-date["\'][^>]*>([\s\S]*?)</span>#', $block, $dm)) {
      $date = clean_text($dm[1]);
    }

    // Verified purchase — the linkless variant is what amazon.eg ships.
    $verified = (bool) preg_match('#data-hook=["\']avp-badge(?:-linkless)?["\']#', $block);

    // Variant info (color/size) — both linked and linkless forms exist.
    $variant = '';
    if (preg_match('#data-hook=["\']format-strip(?:-linkless)?["\'][^>]*>([\s\S]*?)</(?:a|span)>#', $block, $vm)) {
      $variant = clean_text($vm[1]);
    }

    if ($author || $body || $reviewRating !== null) {
      $reviews[] = [
        'author'   => $author,
        'rating'   => $reviewRating,
        'title'    => $reviewTitle,
        'body'     => $body,
        'date'     => $date,
        'verified' => $verified,
        'variant'  => $variant,
      ];
    }
  }
}

send_json([
  'ok'          => true,
  'url'         => $url,
  'asin'        => $asin,
  'title'       => $title,
  'brand'       => $brand,
  'price'       => $price,
  'rating'      => $rating,
  'ratingCount' => $ratingCount,
  'features'    => $features,
  'description' => $description,
  'image'       => $image,
  'breadcrumb'  => $breadcrumb,
  'reviews'     => $reviews,
  'stats'       => [
    'reviewsCaptured' => count($reviews),
    'note' => 'Amazon\'s /product-reviews/ subpage redirects to /ap/signin/ for anonymous visitors, so only the reviews shown on the main product page are reachable without a logged-in session.',
  ],
]);
