<?php
// /api/meta.php — Meta Graph API proxy
// GET ?action=accounts&connector_id=X        → list all ad accounts for the saved token
// GET ?action=accounts&token=...             → list all ad accounts for a given token (used during connector setup)
// GET ?action=insights&connector_id=X&account_id=act_X&since=YYYY-MM-DD&until=YYYY-MM-DD
//     → daily ad-level insights normalized to our row schema (so the dashboard can ingest directly)
require_once __DIR__ . '/_db.php';
require_token();

const META_API = 'https://graph.facebook.com/v21.0';

function meta_get($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  if ($err) return ['__error' => $err];
  $j = json_decode($res, true);
  if (!is_array($j)) return ['__error' => 'Invalid response'];
  return $j;
}

function fetch_token_for_connector($pdo, $id) {
  $stmt = $pdo->prepare("SELECT token, url, meta FROM connectors WHERE id = ? AND active = 1 LIMIT 1");
  $stmt->execute([$id]);
  $r = $stmt->fetch();
  if (!$r) return null;
  // decode meta JSON for downstream filters (e.g. allowed_accounts whitelist)
  if (!empty($r['meta'])) {
    $d = json_decode($r['meta'], true);
    $r['meta_decoded'] = is_array($d) ? $d : null;
  } else {
    $r['meta_decoded'] = null;
  }
  return $r;
}

function fetch_all_pages($url) {
  $all = [];
  $next = $url;
  $safety = 0;
  while ($next && $safety < 50) {
    $j = meta_get($next);
    if (isset($j['__error'])) return ['__error' => $j['__error']];
    if (isset($j['error'])) return ['__error' => $j['error']['message'] ?? 'API error'];
    if (isset($j['data']) && is_array($j['data'])) {
      foreach ($j['data'] as $row) $all[] = $row;
    }
    $next = $j['paging']['next'] ?? null;
    $safety++;
  }
  return $all;
}

$pdo = db();
$action = $_GET['action'] ?? '';

// ── ACCOUNTS LIST ─────────────────────────────────────────────────
if ($action === 'accounts') {
  $token   = $_GET['token'] ?? '';
  $allowed = null; // when set, filter the response to whitelisted account_ids
  $useAll  = !empty($_GET['all']); // ?all=1 forces returning everything (used by Settings UI)
  if (!$token && !empty($_GET['connector_id'])) {
    $row = fetch_token_for_connector($pdo, (int)$_GET['connector_id']);
    if (!$row) send_json(['error' => 'Connector not found'], 404);
    $token = $row['token'];
    if (!$useAll && !empty($row['meta_decoded']['allowed_accounts']) && is_array($row['meta_decoded']['allowed_accounts'])) {
      $allowed = array_flip(array_map('strval', $row['meta_decoded']['allowed_accounts']));
    }
  }
  if (!$token) send_json(['error' => 'token or connector_id required'], 400);

  $url = META_API . '/me/adaccounts'
       . '?fields=id,account_id,name,currency,account_status,business_name'
       . '&limit=200&access_token=' . urlencode($token);
  $rows = fetch_all_pages($url);
  if (isset($rows['__error'])) send_json(['error' => $rows['__error']], 400);
  if ($allowed !== null) {
    $rows = array_values(array_filter($rows, function ($r) use ($allowed) {
      return isset($r['account_id']) && isset($allowed[$r['account_id']]);
    }));
  }
  send_json(['count' => count($rows), 'rows' => $rows]);
}

// ── ACCOUNT-LEVEL SPEND (for Reports → Amount Spent) ──────────────
// Returns total spend (regardless of campaign/adset/ad status) for one ad
// account in the given date range. Account-level insights aggregate every
// dollar that ever ran on the account, INCLUDING deleted entities.
//   GET ?action=account_spend&connector_id=X&account_id=act_X&since=YYYY-MM-DD&until=YYYY-MM-DD
//   → { spend: 1234.56, currency: "EGP" }
if ($action === 'account_spend') {
  $accountId = $_GET['account_id'] ?? '';
  $since     = $_GET['since']      ?? '';
  $until     = $_GET['until']      ?? '';
  $connId    = (int)($_GET['connector_id'] ?? 0);
  if (!$accountId || !preg_match('/^act_[0-9]+$/', $accountId)) send_json(['error' => 'Invalid account_id'], 400);
  if (!$since || !$until) send_json(['error' => 'since and until required (YYYY-MM-DD)'], 400);
  $token = $_GET['token'] ?? '';
  if (!$token && $connId) {
    $row = fetch_token_for_connector($pdo, $connId);
    if (!$row) send_json(['error' => 'Connector not found'], 404);
    $token = $row['token'];
  }
  if (!$token) send_json(['error' => 'token or connector_id required'], 400);
  $tr = json_encode(['since' => $since, 'until' => $until]);
  $url = META_API . '/' . $accountId . '/insights'
       . '?level=account'
       . '&fields=' . urlencode('spend,account_currency')
       . '&time_range=' . urlencode($tr)
       . '&access_token=' . urlencode($token);
  $rows = fetch_all_pages($url);
  if (isset($rows['__error'])) send_json(['error' => $rows['__error']], 400);
  $totalSpend = 0;
  $currency   = '';
  foreach ($rows as $r) {
    $totalSpend += (float)($r['spend'] ?? 0);
    if (!empty($r['account_currency'])) $currency = $r['account_currency'];
  }
  send_json(['spend' => $totalSpend, 'currency' => $currency, 'rows' => count($rows)]);
}

// ── INSIGHTS (DAILY AD-LEVEL) ─────────────────────────────────────
if ($action === 'insights') {
  $accountId = $_GET['account_id'] ?? '';
  $since     = $_GET['since']      ?? '';
  $until     = $_GET['until']      ?? '';
  $connId    = (int)($_GET['connector_id'] ?? 0);

  if (!$accountId) send_json(['error' => 'account_id required'], 400);
  if (!$since || !$until) send_json(['error' => 'since and until required (YYYY-MM-DD)'], 400);
  if (!preg_match('/^act_[0-9]+$/', $accountId)) send_json(['error' => 'Invalid account_id'], 400);

  $token = $_GET['token'] ?? '';
  if (!$token && $connId) {
    $row = fetch_token_for_connector($pdo, $connId);
    if (!$row) send_json(['error' => 'Connector not found'], 404);
    $token = $row['token'];
  }
  if (!$token) send_json(['error' => 'token or connector_id required'], 400);

  $fields = 'date_start,campaign_name,adset_name,ad_name,campaign_id,adset_id,ad_id,'
          . 'spend,impressions,reach,frequency,clicks,inline_link_clicks,outbound_clicks,'
          . 'video_thruplay_watched_actions,'
          . 'actions,action_values';

  $tr = json_encode(['since' => $since, 'until' => $until]);

  $url = META_API . '/' . $accountId . '/insights'
       . '?level=ad'
       . '&fields=' . urlencode($fields)
       . '&time_range=' . urlencode($tr)
       . '&time_increment=1'
       . '&limit=500'
       . '&access_token=' . urlencode($token);

  $rows = fetch_all_pages($url);
  if (isset($rows['__error'])) send_json(['error' => $rows['__error']], 400);

  // ── normalize FB → dashboard row schema ───────────────────────────
  // We mimic the headers of an FB Ads-Manager export so processFileRows()
  // detects platform=facebook automatically.
  function pick_action(array $list, $type) {
    foreach ($list as $a) if (($a['action_type'] ?? '') === $type) return (float)($a['value'] ?? 0);
    return 0;
  }
  function pick_video(array $list) {
    // returns sum of value (FB returns array with single entry usually)
    $sum = 0;
    foreach ($list as $a) $sum += (float)($a['value'] ?? 0);
    return $sum;
  }

  $out = [];
  foreach ($rows as $r) {
    $actions = isset($r['actions']) && is_array($r['actions']) ? $r['actions'] : [];
    $values  = isset($r['action_values']) && is_array($r['action_values']) ? $r['action_values'] : [];
    $outbound = 0;
    if (isset($r['outbound_clicks']) && is_array($r['outbound_clicks'])) {
      foreach ($r['outbound_clicks'] as $oc) $outbound += (float)($oc['value'] ?? 0);
    }
    $row = [
      'Day'                          => $r['date_start']     ?? '',
      'Campaign name'                => $r['campaign_name']  ?? '',
      'Ad set name'                  => $r['adset_name']     ?? '',
      'Ad name'                      => $r['ad_name']        ?? '',
      'Campaign ID'                  => $r['campaign_id']    ?? '',
      'Ad ID'                        => $r['ad_id']          ?? '',
      'Amount spent (EGP)'           => $r['spend']          ?? 0,
      'Impressions'                  => $r['impressions']    ?? 0,
      'Reach'                        => $r['reach']          ?? 0,
      'Outbound clicks'              => $outbound,
      'Clicks (all)'                 => $r['clicks']         ?? 0,
      // FB v21 deprecated video_3_sec_watched_actions — fall back to video_view in actions[]
      '3-second video plays'         => pick_action($actions, 'video_view'),
      'ThruPlays'                    => isset($r['video_thruplay_watched_actions']) ? pick_video($r['video_thruplay_watched_actions']) : 0,
      'Purchases'                    => pick_action($actions, 'purchase') ?: pick_action($actions, 'offsite_conversion.fb_pixel_purchase'),
      'Purchases conversion value'   => pick_action($values,  'purchase') ?: pick_action($values,  'offsite_conversion.fb_pixel_purchase'),
      'Landing page views'           => pick_action($actions, 'landing_page_view'),
      'Content views'                => pick_action($actions, 'view_content') ?: pick_action($actions, 'offsite_conversion.fb_pixel_view_content'),
      'Adds to cart'                 => pick_action($actions, 'add_to_cart')  ?: pick_action($actions, 'offsite_conversion.fb_pixel_add_to_cart'),
      'Checkouts initiated'          => pick_action($actions, 'initiate_checkout') ?: pick_action($actions, 'offsite_conversion.fb_pixel_initiate_checkout'),
    ];
    $out[] = $row;
  }
  send_json(['count' => count($out), 'rows' => $out]);
}

send_json(['error' => 'Unknown action'], 400);
