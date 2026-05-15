<?php
// /api/scrape-abjjad.php?url=<encoded abjjad book url>
//
// Server-side scraper for abjjad.com book pages. Fetches the main page
// (book metadata + first 20 reviews), then walks the AJAX pagination
// endpoint (?cpage=2,3,...) until the dataset is empty. Returns one
// JSON envelope with the book details and the merged review list.
//
// Why server-side: abjjad responses do not include CORS headers, so a
// browser fetch is blocked. The site also rate-limits aggressive
// clients; doing it from PHP keeps the user token off the client and
// lets us add per-page sleeps if we ever need them.
require_once __DIR__ . '/_db.php';
require_token();

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($url === '' || !preg_match('#^https://(www\.)?abjjad\.com/book/#i', $url)) {
  send_json(['error' => 'A valid abjjad.com /book/ URL is required.'], 400);
}

// Pull bookId + editionId out of /book/{bookId}/{slug}/{editionId}[/...]
$path = parse_url($url, PHP_URL_PATH) ?: '';
$parts = array_values(array_filter(explode('/', $path)));
$bookId = $parts[1]  ?? '';
$editionId = $parts[3] ?? '';
if (!preg_match('/^\d+$/', $bookId) || !preg_match('/^\d+$/', $editionId)) {
  send_json(['error' => 'Could not extract bookId / editionId from URL.'], 400);
}

$maxPages = isset($_GET['maxPages']) ? max(1, min(200, (int)$_GET['maxPages'])) : 100;

// ── helpers ──────────────────────────────────────────────────────────
function curl_text($url, $method = 'GET') {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => ['Accept-Language: ar,en;q=0.8', 'Accept: text/html,application/json'],
  ]);
  if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
  }
  $data = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$http, $data];
}

function meta_content($html, $itemprop) {
  $re = '#<meta[^>]*itemprop=["\']' . preg_quote($itemprop, '#') . '["\'][^>]*content=["\']([^"\']+)["\']#i';
  return preg_match($re, $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
}
function meta_attr($html, $attr, $key) {
  // Generic: extract <meta {attr}="{key}" content="..."> regardless of order
  $re = '#<meta[^>]*' . preg_quote($attr, '#') . '=["\']' . preg_quote($key, '#') . '["\'][^>]*content=["\']([^"\']+)["\']#i';
  if (preg_match($re, $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
  // Try with attribute order flipped (content first)
  $re2 = '#<meta[^>]*content=["\']([^"\']+)["\'][^>]*' . preg_quote($attr, '#') . '=["\']' . preg_quote($key, '#') . '["\']#i';
  return preg_match($re2, $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
}

// Pull every review block out of one chunk of HTML — used for both the
// main page (where ~20 review blocks are inline with full schema.org
// markup) AND each paginated POST response (where each PagedArray entry
// is one review block with reduced markup: no itemprop attrs, the date
// uses <time class="time"> instead of <time itemprop="datePublished">,
// and the rating sits in <span class="rating"> instead of <meta>).
// The regex matches either format by the wrapping <div class="thebadge
// storybadge…" data-id="..."> and captures up to the next wrapper.
function parse_reviews_from_html($html) {
  $out = [];
  $re = '#<div[^>]*class=["\'][^"\']*thebadge\b[^"\']*storybadge\b[^"\']*["\'][^>]*data-id=["\'](\d+)["\'][^>]*>([\s\S]*?)(?=<div[^>]*class=["\'][^"\']*thebadge\b[^"\']*storybadge|$)#u';
  if (!preg_match_all($re, $html, $matches, PREG_SET_ORDER)) return $out;
  foreach ($matches as $m) {
    $block = $m[0];
    $id    = $m[1];

    // Author name — main page uses <span itemprop="name">…</span>,
    // pagination puts the name directly in <h5 class="name">…</h5>.
    $author = '';
    if (preg_match('#<span[^>]*itemprop=["\']name["\'][^>]*>([\s\S]*?)</span>#u', $block, $am)) {
      $author = trim(html_entity_decode(strip_tags($am[1]), ENT_QUOTES, 'UTF-8'));
    }
    if ($author === '' && preg_match('#<h5[^>]*class=["\'][^"\']*name[^"\']*["\'][^>]*>([\s\S]*?)</h5>#u', $block, $am2)) {
      $author = trim(html_entity_decode(strip_tags($am2[1]), ENT_QUOTES, 'UTF-8'));
    }

    $authorUrl = '';
    if (preg_match('#href=["\'](/profile/[^"\']+)["\']#', $block, $um)) {
      $authorUrl = 'https://www.abjjad.com' . html_entity_decode($um[1], ENT_QUOTES, 'UTF-8');
    }

    // Rating — main page <meta itemprop="ratingValue" content="N">;
    // pagination <span class="rating">N</span>.
    $rating = null;
    if (preg_match('#<meta[^>]*itemprop=["\']ratingValue["\'][^>]*content=["\'](\d+(?:\.\d+)?)["\']#', $block, $rm)) {
      $rating = $rm[1] + 0;
    }
    if ($rating === null && preg_match('#<span[^>]*class=["\'][^"\']*\brating\b[^"\']*["\'][^>]*>(\d+(?:\.\d+)?)</span>#u', $block, $rm2)) {
      $rating = $rm2[1] + 0;
    }

    // Body — both formats wrap text in <div class="blurringBody">…</div>.
    // Preserve paragraph breaks (</p>, <br>) as newlines before stripping.
    $body = '';
    if (preg_match('#<div[^>]*class=["\'][^"\']*blurringBody[^"\']*["\'][^>]*>([\s\S]*?)</div>#u', $block, $bm)) {
      $inner = $bm[1];
      $inner = preg_replace('#</p>#i',  "\n", $inner);
      $inner = preg_replace('#<br\s*/?>#i', "\n", $inner);
      $body  = trim(strip_tags($inner));
      $body  = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
      $body  = preg_replace('/[ \t]+\n/u', "\n", $body);
      $body  = preg_replace('/\n{3,}/u', "\n\n", $body);
    }

    // Date — <time itemprop="datePublished" datetime="..."> (main) OR
    // <time class="time" datetime="..."> (pagination). Both carry datetime.
    $date = '';
    if (preg_match('#<time[^>]*datetime=["\']([^"\']+)["\']#', $block, $dm)) {
      $date = $dm[1];
    }

    $out[] = [
      'id'        => $id,
      'author'    => $author,
      'authorUrl' => $authorUrl,
      'rating'    => $rating,
      'body'      => $body,
      'date'      => $date,
    ];
  }
  return $out;
}

// ── 1. Fetch the main book page ──────────────────────────────────────
[$http, $html] = curl_text($url, 'GET');
if ($http !== 200 || !$html) {
  send_json(['error' => 'Failed to fetch the book page', 'http' => $http], 502);
}

// JSON-LD Book block carries the structured metadata.
$book = null;
if (preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>([\s\S]*?)</script>#i', $html, $jms)) {
  foreach ($jms[1] as $jm) {
    $obj = json_decode(trim($jm), true);
    if (!$obj) continue;
    $items = isset($obj['@type']) ? [$obj] : (is_array($obj) ? $obj : []);
    foreach ($items as $it) {
      if (!is_array($it)) continue;
      if (($it['@type'] ?? '') === 'Book') { $book = $it; break 2; }
    }
  }
}

// Description sits in a <span itemprop="description">…</span> further down.
$summary = '';
if (preg_match('#<span[^>]*itemprop=["\']description["\'][^>]*>([\s\S]*?)</span>#u', $html, $sm)) {
  $summary = trim(strip_tags($sm[1]));
  $summary = html_entity_decode($summary, ENT_QUOTES, 'UTF-8');
  $summary = preg_replace('/[ \t]+\n/u', "\n", $summary);
  $summary = preg_replace('/\n{3,}/u', "\n\n", $summary);
}

// Aggregate rating + counts (visible above the rating breakdown).
$ratingValue = '';
$reviewCount = '';
$ratingCount = '';
if (preg_match('#<meta[^>]*itemprop=["\']ratingValue["\'][^>]*content=["\']([\d.]+)["\']#', $html, $mm)) $ratingValue = $mm[1];
if (preg_match('#<meta[^>]*itemprop=["\']reviewCount["\'][^>]*content=["\'](\d+)["\']#', $html, $mm)) $reviewCount = (int)$mm[1];
if (preg_match('#itemprop=["\']ratingCount["\'][^>]*>(\d+)<#', $html, $mm)) $ratingCount = (int)$mm[1];

$ogTitle = meta_attr($html, 'property', 'og:title');
$ogImage = meta_attr($html, 'property', 'og:image');
$metaDesc = meta_attr($html, 'name', 'description');

// First page reviews are inline in the HTML.
$reviewsById = [];
foreach (parse_reviews_from_html($html) as $rv) {
  $reviewsById[$rv['id']] = $rv;
}
$initialCount = count($reviewsById);

// ── 2. Walk the AJAX pagination endpoint ─────────────────────────────
$pageUrl = 'https://www.abjjad.com/book/' . $bookId . '/reviews/page?editionId=' . $editionId;
$pagesFetched = 0;
$emptyStreak  = 0;
for ($cpage = 2; $cpage <= $maxPages; $cpage++) {
  $u = $pageUrl . '&cpage=' . $cpage;
  [$h, $body] = curl_text($u, 'POST');
  $pagesFetched++;
  if ($h !== 200 || !$body) { $emptyStreak++; if ($emptyStreak >= 2) break; continue; }
  $j = json_decode($body, true);
  $chunks = $j['d']['PagedArray'] ?? [];
  if (!is_array($chunks) || !count($chunks)) { $emptyStreak++; if ($emptyStreak >= 2) break; continue; }

  $addedThisPage = 0;
  foreach ($chunks as $chunk) {
    foreach (parse_reviews_from_html($chunk) as $rv) {
      if (!isset($reviewsById[$rv['id']])) {
        $reviewsById[$rv['id']] = $rv;
        $addedThisPage++;
      }
    }
  }
  // Stop when a page adds nothing new — we've caught up with the tail.
  if ($addedThisPage === 0) { $emptyStreak++; if ($emptyStreak >= 2) break; }
  else { $emptyStreak = 0; }
}

// Drop rating-only entries — abjjad treats every star tap as a "review",
// but they're just numbers without any text content. The caller wants
// substantive reviews only. Keep both counts on the envelope so the UI
// can still show "X text reviews out of Y total ratings".
$totalCollected = count($reviewsById);
$reviews = array_values(array_filter($reviewsById, function ($r) {
  return isset($r['body']) && trim((string)$r['body']) !== '';
}));
// Newest first.
usort($reviews, function ($a, $b) {
  return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
});

send_json([
  'ok'           => true,
  'url'          => $url,
  'bookId'       => $bookId,
  'editionId'    => $editionId,
  'title'        => $book['name'] ?? $ogTitle ?? '',
  'author'       => $book['author']['name'] ?? '',
  'authorUrl'    => $book['author']['url']  ?? '',
  'image'        => $book['image'] ?? $ogImage ?? '',
  'genre'        => $book['genre'] ?? [],
  'isbn'         => $book['isbn'] ?? '',
  'publisher'    => $book['publisher']['name'] ?? '',
  'pages'        => $book['numberOfPages'] ?? null,
  'datePublished'=> $book['datePublished']  ?? '',
  'language'     => $book['inLanguage']     ?? '',
  'summary'      => $summary,
  'metaDescription' => $metaDesc,
  'rating'       => $ratingValue,
  'ratingCount'  => $ratingCount,
  'reviewCount'  => $reviewCount,
  'reviews'      => $reviews,
  'stats'        => [
    'initialReviewsFromMainPage' => $initialCount,
    'paginatedPagesFetched'      => $pagesFetched,
    'totalEntriesCollected'      => $totalCollected,
    'textReviewsReturned'        => count($reviews),
  ],
]);
