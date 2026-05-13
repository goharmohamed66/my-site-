<?php
// /api/shipping.php — Server-side proxy for shipping carrier APIs.
// Routes by saved connector (connectors.provider) to the right carrier integration.
//
// GET ?action=fetch&connector_id=X[&since=YYYY-MM-DD&until=YYYY-MM-DD]
//   → returns a normalized array of shipping orders so the dashboard can
//     ingest them the same way it ingests an Excel/CSV upload.
//
// Normalized row schema:
//   {
//     order_id:  string,
//     product:   string,
//     city:      string,
//     status:    string  (DELIVERED | RETURNED | OUT_FOR_DELIVERY | PENDING | ...)
//     cod:       number,
//     fees:      number,
//     net:       number,
//     date:      "YYYY-MM-DD",
//     source:    "Bosta" | "J&T" | "QP" | "ShipBlu" | "Turbo",
//     employee:  string|null,
//     raw:       object   — the original carrier payload, for debugging
//   }
require_once __DIR__ . '/_db.php';
require_token();

const HTTP_TIMEOUT = 60;

function http_request($method, $url, $headers = [], $body = null) {
  $ch = curl_init($url);
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true,
  ];
  if ($body !== null) $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
  curl_setopt_array($ch, $opts);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return ['code' => $code, 'body' => $res, 'err' => $err];
}

function map_status($carrier, $statusStr, $statusCode = null) {
  $s = strtoupper(trim((string)$statusStr));
  // ORDER MATTERS: check UN-DELIVERED before DELIVERED, otherwise "UNDELIVERED"
  // matches the DELIVERED substring and gets mis-bucketed. Also accept QP's
  // typo'd spellings "Deliverd" / "Undeliverd" (missing E) — that's what
  // the live QP API actually returns for thousands of rows, so the strict
  // DELIVERED-only regex was silently dropping every row from the
  // delivered / returned tallies.
  if (preg_match('/(UN[ \-]?DELIVER(E)?D|FAILED|EXCEPTION)/iu', $s)) {
    // QP semantics (confirmed against their export): "Undelivered" is what
    // QP calls a returned-to-merchant shipment. Other carriers use it to
    // mean a failed delivery attempt that's still in the carrier network —
    // keep those distinct so they don't inflate the returned count.
    if ($carrier === 'qp') return 'RETURNED';
    return 'UNDELIVERED';
  }
  if (preg_match('/(DELIVER(E)?D|تم التسليم|سُلِّم)/iu', $s)) return 'DELIVERED';
  if (preg_match('/(RETURN|RTO|REJECT|REFUSE|راجع|مرتجع|إرجاع|رفض)/iu', $s)) return 'RETURNED';
  if (preg_match('/(OUT[ \-]?FOR[ \-]?DELIVERY|IN[ \-]?TRANSIT|في الطريق|للتوصيل)/iu', $s)) return 'OUT_FOR_DELIVERY';
  if (preg_match('/(CANCEL|ملغي)/iu', $s)) return 'CANCELED';
  if (preg_match('/(PENDING|HOLD|SCHEDULED|قيد الانتظار|جاهز)/iu', $s)) return 'PENDING';
  return $s ?: 'UNKNOWN';
}

function err($msg, $code = 400, $extra = []) { send_json(array_merge(['error' => $msg], $extra), $code); }

/* ─── BOSTA ─────────────────────────────────────────────────────────── */
// Bosta returns dates in JS Date.toString() form, e.g.
//   "Sun Apr 05 2026 13:50:16 GMT+0000 (Coordinated Universal Time)"
// PHP's strtotime() chokes on the trailing "(Coordinated…)" text — extract via regex.
function bosta_parse_date($d) {
  $months = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
             'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
  $candidates = ['createdAt','creationTimestamp','updatedAt','collectedAt','pickedUpTime','deliveryTime'];
  foreach ($candidates as $f) {
    if (empty($d[$f])) continue;
    $v = $d[$f];
    if (is_numeric($v)) {           // unix millis
      $ts = (int)$v / 1000;
      if ($ts > 0) return date('Y-m-d', $ts);
    }
    $s = (string)$v;
    // ISO 8601 fast-path
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return $m[1].'-'.$m[2].'-'.$m[3];
    // JS toString: "Sun Apr 05 2026 13:50:16 GMT+0000 (...)"
    if (preg_match('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})\s+(\d{4})/', $s, $m)) {
      return $m[3].'-'.str_pad($months[$m[1]],2,'0',STR_PAD_LEFT).'-'.str_pad($m[2],2,'0',STR_PAD_LEFT);
    }
    // Last-ditch: strip the parenthesised TZ name and try strtotime
    $clean = trim(preg_replace('/\([^)]+\)/', '', $s));
    $ts = strtotime($clean);
    if ($ts) return date('Y-m-d', $ts);
  }
  if (!empty($d['updates'][0]['timestamp'])) {
    $ts = strtotime((string)$d['updates'][0]['timestamp']);
    if ($ts) return date('Y-m-d', $ts);
  }
  return null;
}

// Build the list of candidate "sheet-equivalent" State labels for a
// Bosta delivery. A row is considered to match a given label if ANY of
// these candidates equals it (case-insensitive).
//
// Why a list and not a single string: Bosta's bulk search endpoint
// strips state.value down to a coarse summary ("Delivered" / "Created"
// for ~98% of rows) regardless of what the sheet would actually show.
// The detailed labels the merchant sees in the Bosta dashboard sheet
// — "Received at warehouse", "Route assigned", "Rejected Return
// (Archived) - On Hold", etc. — only live on the per-delivery
// endpoint, which is heavily rate-limited.
//
// So instead of trying to reconstruct the exact Bosta label from the
// thin search payload, we emit BOTH:
//   1. The flattened verdict (Delivered / Returned / Returned to
//      Origin / Canceled) derived from `type` + `state` — covers the
//      common case.
//   2. The shipment's broad direction tag ("Returned" if type is any
//      return flavor) — so when the merchant adds every possible
//      return sub-state to their returned_values list, EVERY
//      return-direction shipment still matches one of them.
//   3. The raw API state.value as-is — so simple states like "Created"
//      / "Lost Or Damaged" still flow through.
//
// The matcher in bosta_status() walks delivered_values / returned_values
// and considers the row matched if ANY candidate label hits ANY entry.
function bosta_sheet_state_candidates($d) {
  $typeRaw  = (string)($d['type']['value']  ?? '');
  $stateRaw = (string)($d['state']['value'] ?? '');
  $stateCode = isset($d['state']['code']) ? (int)$d['state']['code'] : null;
  $type  = strtolower(trim($typeRaw));
  $state = strtolower(trim($stateRaw));
  $isReturnType = (strpos($type, 'return') !== false);

  // Bosta's state.value is a coarse human label and lies for the
  // returned-back-to-merchant case: state.code=46 shows
  // state.value="Delivered" but the shipment is actually a return that
  // came back to the merchant. Bosta portal's own "Delivered" filter
  // counts ONLY state.code=45 — confirmed against a live pull
  // (May 1-13: code=45 = 873, matched the portal's 874; code=46 = 511
  // were the returns the portal correctly excluded).
  //
  // So map state.code → canonical sheet label FIRST, and only fall
  // back to state.value when the code is missing / unknown.
  // Code 45 = delivered to destination. For Send shipments that's a
  // real delivery; for Return-direction shipments it means "delivered
  // back to merchant" — i.e. a return from the merchant's POV.
  // Code 46 = returned (regardless of type).
  $retLabel = ($type === 'return to origin') ? 'Returned to Origin' : 'Returned';
  $codeLabel = null;
  switch ($stateCode) {
    case 10: $codeLabel = 'Created';              break;
    case 20: $codeLabel = $isReturnType ? $retLabel : 'Route assigned';        break;
    case 22: $codeLabel = $isReturnType ? $retLabel : 'Picked up';             break;
    case 24: $codeLabel = $isReturnType ? $retLabel : 'Received at warehouse'; break;
    case 30: $codeLabel = $isReturnType ? $retLabel : 'Out for delivery';      break;
    case 41: $codeLabel = $isReturnType ? $retLabel : 'Out for delivery';      break;
    case 45: $codeLabel = $isReturnType ? $retLabel : 'Delivered';             break;
    case 46: $codeLabel = $retLabel;              break;
    case 47: $codeLabel = $isReturnType ? $retLabel : 'Awaiting for Action';   break;
    case 48: $codeLabel = 'Canceled';             break;
    case 49: $codeLabel = 'Terminated';           break;
  }

  // When state.code yielded a definitive label, USE ONLY THAT — don't
  // append state.value, otherwise a code=46 Send shipment (semantically
  // "Returned") gets BOTH "Returned" AND state.value="Delivered" as
  // candidates, and the matcher checks delivered_values first → flips
  // the row to DELIVERED. State.value is misleading for code 46.
  $cands = [];
  if ($codeLabel !== null) {
    $cands[] = $codeLabel;
    // For return-type rows, also append "Returned" / "Returned to Origin"
    // so the merchant's returned_values list catches them even if they
    // only listed one of the two labels.
    if ($isReturnType) {
      if ($codeLabel !== 'Returned')          $cands[] = 'Returned';
      if ($codeLabel !== 'Returned to Origin' && $type === 'return to origin') $cands[] = 'Returned to Origin';
    }
  } else {
    // No state.code — fall back to legacy state.value heuristics.
    if ($state === 'canceled' || $state === 'terminated') {
      $cands[] = 'Canceled';
    } elseif ($state === 'delivered') {
      if ($type === 'return to origin')      $cands[] = 'Returned to Origin';
      elseif ($isReturnType)                 $cands[] = 'Returned';
      else                                   $cands[] = 'Delivered';
    }
    if ($isReturnType) {
      $cands[] = 'Returned';
      if ($type === 'return to origin') $cands[] = 'Returned to Origin';
    }
    if ($stateRaw !== '') $cands[] = $stateRaw;
  }
  $seen = []; $out = [];
  foreach ($cands as $c) { $k = strtolower($c); if (isset($seen[$k])) continue; $seen[$k] = 1; $out[] = $c; }
  return $out;
}

// Convenience for callers that just want one label (e.g. the column
// stored in DB). Returns the first candidate, which is the most
// specific verdict.
function bosta_sheet_state($d) {
  $c = bosta_sheet_state_candidates($d);
  return $c ? $c[0] : (string)($d['state']['value'] ?? '');
}

// Decide DELIVERED / RETURNED / etc. for a Bosta row. Reads the
// connector's column_map (delivered_values / returned_values, comma-
// separated) first — those are sheet-style labels and are matched
// against bosta_sheet_state(). Falls back to a hardcoded type+state
// heuristic when the connector has no overrides.
function bosta_status($d, $conn = null) {
  $candidates = bosta_sheet_state_candidates($d);

  $cm = null;
  if ($conn && isset($conn['meta'])) {
    $meta = is_string($conn['meta']) ? (json_decode($conn['meta'], true) ?: []) : $conn['meta'];
    $cm = $meta['column_map'] ?? null;
  }
  if ($cm) {
    // Match if ANY candidate label hits ANY entry in the values list.
    $matchAny = function($values, $candidates){
      if (!$values) return false;
      $set = array_filter(array_map('trim', explode(',', (string)$values)));
      foreach ($set as $v) {
        if ($v === '') continue;
        foreach ($candidates as $c) {
          if (strcasecmp($v, $c) === 0) return true;
        }
      }
      return false;
    };
    if ($matchAny($cm['delivered_values'] ?? '', $candidates)) return 'DELIVERED';
    if ($matchAny($cm['returned_values']  ?? '', $candidates)) return 'RETURNED';
  }

  // Hardcoded fallback for connectors without a column_map: the first
  // candidate is the most specific verdict.
  $top   = $candidates ? $candidates[0] : '';
  $topU  = strtoupper(trim($top));
  if (strcasecmp($topU, 'CANCELED') === 0)         return 'CANCELED';
  if (strcasecmp($topU, 'DELIVERED') === 0)        return 'DELIVERED';
  if (preg_match('/^RETURN/i', $topU))             return 'RETURNED';
  return map_status('bosta', (string)($d['state']['value'] ?? ''));
}

function bosta_normalize_row($d, $conn = null) {
  $cod = (float)($d['cod'] ?? 0);
  $fees = 0;
  if (isset($d['shipmentFees']))       $fees = (float)$d['shipmentFees'];
  elseif (isset($d['priceAfterVat']))  $fees = (float)$d['priceAfterVat'];
  elseif (isset($d['shippingFee']))    $fees = (float)$d['shippingFee'];
  elseif (isset($d['priceBeforeVat'])) $fees = (float)$d['priceBeforeVat'];

  $city = $d['dropOffAddress']['city']['name'] ?? $d['receiver']['city'] ?? '';
  $product = $d['specs']['packageDetails']['description'] ?? '';
  $orderId = $d['trackingNumber'] ?? $d['_id'] ?? '';
  return [
    'order_id' => $orderId,
    'product'  => $product,
    'city'     => $city,
    'status'   => bosta_status($d, $conn),
    'cod'      => $cod,
    'fees'     => $fees,
    'net'      => max(0, $cod - $fees),
    'date'     => bosta_parse_date($d),
    'source'   => 'Bosta',
    'employee' => null,
    'raw'      => $d
  ];
}

function bosta_make_handle($token, $since, $until, $page, $limit) {
  // IMPORTANT: Bosta's /deliveries/search uses `pageNumber` / `pageLimit`
  // (camelCase) — NOT `page` / `limit`. With the wrong names the server
  // silently returns page 1 every time, which made parallel pagination
  // look like Bosta was repeating itself when in fact our params were
  // being ignored entirely.
  $payload = ['pageLimit' => $limit, 'pageNumber' => $page];
  if ($since) $payload['createdAtStart'] = $since . 'T00:00:00.000+02:00';
  if ($until) $payload['createdAtEnd']   = $until . 'T23:59:59.999+02:00';
  $ch = curl_init('https://app.bosta.co/api/v0/deliveries/search');
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => [
      'Authorization: ' . $token,
      'Content-Type: application/json',
    ],
  ]);
  return $ch;
}

function fetch_bosta($conn, $since, $until) {
  $token = $conn['token'] ?? '';
  if (!$token) err('Bosta connector is missing the API key.');

  // Give the request as much time as it needs — Hostinger's PHP cap is
  // already covered by nginx's upstream timeout; this just stops PHP
  // from killing the loop while we wait on Bosta.
  @set_time_limit(0);
  @ignore_user_abort(true);

  $limit = 50;       // Bosta's /deliveries/search caps each page at 50.
  $batch = 12;       // Pages fetched in parallel via cURL multi.

  // Page 1 sequentially so we can detect when pagination naturally ends.
  // Same camelCase param names as bosta_make_handle — see comment there
  // for the gotcha with `page`/`limit`.
  $r = http_request('POST', 'https://app.bosta.co/api/v0/deliveries/search',
    ['Authorization: ' . $token, 'Content-Type: application/json'],
    array_filter([
      'pageLimit'       => $limit,
      'pageNumber'      => 1,
      'createdAtStart'  => $since ? $since . 'T00:00:00.000+02:00' : null,
      'createdAtEnd'    => $until ? $until . 'T23:59:59.999+02:00' : null,
    ], function($v){ return $v !== null; })
  );
  if ($r['code'] >= 400 || !$r['body']) err('Bosta API error', 502, ['detail' => substr($r['body'] ?: '', 0, 300)]);
  $j = json_decode($r['body'], true);
  $list = $j['deliveries'] ?? $j['data'] ?? [];

  // Dedup by tracking number — Bosta's /deliveries/search will happily
  // serve the LAST page over and over when you request pages past the
  // real end of the result set, instead of returning empty. Without
  // dedup we'd return tens of thousands of duplicate rows for a real
  // ~3,000-row month and the page-1 `count` field doesn't always
  // reflect the date-filtered total (observed in production).
  $rows = [];
  $seen = [];
  foreach ($list as $d) {
    $key = $d['trackingNumber'] ?? $d['_id'] ?? null;
    if ($key && isset($seen[$key])) continue;
    if ($key) $seen[$key] = 1;
    $rows[] = bosta_normalize_row($d, $conn);
  }

  // Page 1 came back short → we already have everything.
  if (count($list) < $limit) return $rows;

  $total = (int)($j['count'] ?? $j['totalCount'] ?? $j['total'] ?? 0);
  $safetyPages = 400;  // hard ceiling regardless of what Bosta reports
  $totalPages = $total > 0 ? min((int)ceil($total / $limit), $safetyPages) : $safetyPages;

  // Pages 2..N in parallel batches. cURL multi keeps a small pool of
  // HTTP requests in flight at once so a 3,000-row pull (60 pages)
  // finishes in ~5 round-trip waves instead of 60 — the difference
  // between "fits in nginx's 60s timeout" and a 504.
  $allDupBatches = 0;
  for ($start = 2; $start <= $totalPages; $start += $batch) {
    $end = min($start + $batch - 1, $totalPages);
    $multi = curl_multi_init();
    $handles = [];
    for ($p = $start; $p <= $end; $p++) {
      $ch = bosta_make_handle($token, $since, $until, $p, $limit);
      curl_multi_add_handle($multi, $ch);
      $handles[$p] = $ch;
    }
    do {
      curl_multi_exec($multi, $running);
      if ($running) curl_multi_select($multi);
    } while ($running > 0);

    $batchNew = 0;
    $batchShortPage = false;
    foreach ($handles as $p => $ch) {
      $body = curl_multi_getcontent($ch);
      curl_multi_remove_handle($multi, $ch);
      curl_close($ch);
      if (!$body) continue;
      $pj = json_decode($body, true);
      $pList = $pj['deliveries'] ?? $pj['data'] ?? [];
      if (!$pList) { $batchShortPage = true; continue; }
      if (count($pList) < $limit) $batchShortPage = true;
      foreach ($pList as $d) {
        $key = $d['trackingNumber'] ?? $d['_id'] ?? null;
        if ($key && isset($seen[$key])) continue;
        if ($key) $seen[$key] = 1;
        $rows[] = bosta_normalize_row($d, $conn);
        $batchNew++;
      }
    }
    curl_multi_close($multi);

    // Bail when Bosta starts serving us nothing new — either every
    // tracking number in the batch was already seen (the "last page
    // on repeat" behavior) or one of the pages came back short, which
    // marks the real end of the result set.
    if ($batchNew === 0) {
      $allDupBatches++;
      if ($allDupBatches >= 1) break;
    }
    if ($batchShortPage && $batchNew === 0) break;
    if ($total > 0 && count($rows) >= $total) break;
  }
  return $rows;
}

/* ─── J&T Egypt (openapi.jtjms-eg.com) ──────────────────────────────────
 *  Per the official docs:
 *    Host           : https://openapi.jtjms-eg.com (sandbox: demoopenapi.jtjms-eg.com)
 *    Content-Type   : application/x-www-form-urlencoded
 *    Body           : bizContent=<json-string>
 *    Headers        : apiAccount, digest, timestamp(ms)
 *    Digest formula : Base64( MD5_RAW(bizContent + privateKey) )
 *
 *  Note: J&T's openAPI does NOT expose a "list all orders for the customer"
 *  endpoint — it provides per-waybill query / tracking endpoints. To pull
 *  bulk data for analytics we need either (a) the customer's order-numbers
 *  list, or (b) the J&T webhook ("订单状态回传") configured to push every
 *  status update to our server.
 *
 *  Until we have the bulk-list endpoint name, this function calls
 *  /webopenplatformapi/api/order/getOrders (the most common pattern J&T uses
 *  in other regions). If J&T Egypt uses a different path we'll surface the
 *  exact API error so we can adjust.
 */
function jt_call($conn, $path, $bizPayload) {
  $apiAcc = $conn['consumer_key'] ?? '';
  $priv   = $conn['token']        ?? '';
  if (!$apiAcc || !$priv) err('J&T connector is missing API Account / Private Key.');
  $useDemo = (strpos($apiAcc, '292508') === 0); // J&T's published sandbox account starts with this
  $base = $useDemo ? 'https://demoopenapi.jtjms-eg.com' : 'https://openapi.jtjms-eg.com';
  $bizContent = json_encode($bizPayload, JSON_UNESCAPED_UNICODE);
  $digest     = base64_encode(md5($bizContent . $priv, true));
  $form       = 'bizContent=' . urlencode($bizContent);
  $r = http_request('POST', $base . $path, [
    'apiAccount: ' . $apiAcc,
    'digest: '     . $digest,
    'timestamp: '  . (int)(microtime(true) * 1000),
    'Content-Type: application/x-www-form-urlencoded'
  ], $form);
  return $r;
}

function fetch_jt($conn, $since, $until) {
  // Default biz payload — kept minimal so we can iterate when J&T gives us
  // the exact field names (J&T regional APIs differ slightly).
  $payload = [
    'customerCode' => $conn['url'] ?? '',  // Some J&T installs require customerCode
    'startTime'    => $since . ' 00:00:00',
    'endTime'      => $until . ' 23:59:59',
    'pageNo'       => 1,
    'pageSize'     => 200
  ];
  // Try the most common bulk-query endpoints in order until one returns data.
  $candidates = [
    '/webopenplatformapi/api/order/getOrders',
    '/webopenplatformapi/api/order/searchOrder',
    '/webopenplatformapi/api/order/selectOrder',
    '/webopenplatformapi/api/order/queryOrder',
  ];
  $lastErr = null;
  foreach ($candidates as $path) {
    $r = jt_call($conn, $path, $payload);
    if ($r['err']) { $lastErr = $r['err']; continue; }
    $j = json_decode($r['body'], true);
    // J&T returns code:"1" on success, anything else is an error
    if (is_array($j) && isset($j['code'])) {
      if ($j['code'] === '1' || $j['code'] === 1) {
        $list = $j['data'] ?? [];
        if (isset($list['list'])) $list = $list['list'];        // some endpoints wrap in {list:[...]}
        if (isset($list['records'])) $list = $list['records'];
        $rows = [];
        foreach ((array)$list as $d) {
          $rows[] = [
            'order_id' => $d['billCode']       ?? $d['waybillNo']       ?? $d['txlogisticId'] ?? '',
            'product'  => $d['itemName']       ?? $d['goodsType']       ?? '',
            'city'     => $d['receiverCity']   ?? $d['receiver']['city'] ?? '',
            'status'   => map_status('jt', $d['stateName'] ?? $d['waybillStatus'] ?? $d['orderStatus'] ?? ''),
            'cod'      => (float)($d['itemsValue'] ?? $d['codAmount'] ?? 0),
            'fees'     => (float)($d['sumFreight'] ?? $d['shipFee']   ?? 0),
            'net'      => (float)($d['itemsValue'] ?? 0) - (float)($d['sumFreight'] ?? 0),
            'date'     => isset($d['createOrderTime']) ? substr($d['createOrderTime'], 0, 10) : null,
            'source'   => 'J&T',
            'employee' => null,
            'raw'      => $d
          ];
        }
        return $rows;
      }
      // Real J&T error response — surface the exact code + message so the
      // user can share it back so we can pin down the right endpoint.
      $lastErr = '['.$j['code'].'] '.($j['msg'] ?? 'Unknown error').' (path: '.$path.')';
      // Not 404-style — break early; keep iterating only if path was unknown
      if (!preg_match('/path|notfound|404|not.*exist/i', (string)$j['msg'])) break;
    }
  }
  err('J&T returned no usable data. Last response: ' . ($lastErr ?: 'empty'), 502, [
    'hint' => 'J&T Egypt openAPI does not expose a generic "list orders" endpoint by default. Either share the exact bulk-query endpoint name (look for "查询订单" / Query Order in your J&T docs portal) or set up the J&T order-status webhook to push updates to our /api/jt-webhook.php receiver.'
  ]);
}

/* ─── ShipBlu ───────────────────────────────────────────────────────── */
function fetch_shipblu($conn, $since, $until) {
  $key = $conn['token'] ?? '';
  if (!$key) err('ShipBlu connector is missing the API key.');
  // Endpoint: GET https://api.shipblu.com/api/v1/orders
  $url = 'https://api.shipblu.com/api/v1/orders?limit=100';
  if ($since) $url .= '&created_after=' . urlencode($since);
  if ($until) $url .= '&created_before=' . urlencode($until);
  $r = http_request('GET', $url, ['Authorization: Token ' . $key, 'Accept: application/json']);
  if ($r['code'] >= 400) err('ShipBlu API error', 502, ['detail' => substr($r['body'] ?: '', 0, 300)]);
  $j = json_decode($r['body'], true);
  $rows = [];
  foreach (($j['results'] ?? $j['orders'] ?? []) as $d) {
    $rows[] = [
      'order_id' => $d['tracking_number'] ?? $d['id'] ?? '',
      'product'  => $d['description'] ?? '',
      'city'     => $d['customer']['city'] ?? '',
      'status'   => map_status('shipblu', $d['status'] ?? ''),
      'cod'      => (float)($d['cash_amount'] ?? 0),
      'fees'     => (float)($d['shipping_fees'] ?? 0),
      'net'      => (float)($d['cash_amount'] ?? 0) - (float)($d['shipping_fees'] ?? 0),
      'date'     => isset($d['created_at']) ? substr($d['created_at'], 0, 10) : null,
      'source'   => 'ShipBlu',
      'employee' => null,
      'raw'      => $d
    ];
  }
  return $rows;
}

/* ─── Turbo ─────────────────────────────────────────────────────────── */
function fetch_turbo($conn, $since, $until) {
  $key  = $conn['token']         ?? '';
  $cust = $conn['consumer_key']  ?? '';
  if (!$key || !$cust) err('Turbo connector is missing API Key / Customer Code.');
  $url  = 'https://app.turbo-eg.com/api/v1/orders?customer_code=' . urlencode($cust) . '&limit=100';
  if ($since) $url .= '&from=' . urlencode($since);
  if ($until) $url .= '&to='   . urlencode($until);
  $r = http_request('GET', $url, ['x-api-key: ' . $key, 'Accept: application/json']);
  if ($r['code'] >= 400) err('Turbo API error', 502, ['detail' => substr($r['body'] ?: '', 0, 300)]);
  $j = json_decode($r['body'], true);
  $rows = [];
  foreach (($j['orders'] ?? $j['data'] ?? []) as $d) {
    $rows[] = [
      'order_id' => $d['order_id'] ?? $d['awb'] ?? '',
      'product'  => $d['description'] ?? '',
      'city'     => $d['city'] ?? '',
      'status'   => map_status('turbo', $d['status'] ?? ''),
      'cod'      => (float)($d['cod'] ?? 0),
      'fees'     => (float)($d['shipping_fee'] ?? 0),
      'net'      => (float)($d['cod'] ?? 0) - (float)($d['shipping_fee'] ?? 0),
      'date'     => isset($d['created_at']) ? substr($d['created_at'], 0, 10) : null,
      'source'   => 'Turbo',
      'employee' => null,
      'raw'      => $d
    ];
  }
  return $rows;
}

/* ─── QP / QPExpress ────────────────────────────────────────────────
 *  Auth      : POST {server}/integration/token  body {username,password} → {token}
 *  List      : GET  {server}/integration/order?page_size=&from_date=&to_date=
 *  Server URL: https://api.qpxpress.com (override via connector.url)
 */
function fetch_qp($conn, $since, $until) {
  $user = $conn['consumer_key']    ?? '';
  $pass = $conn['consumer_secret'] ?? '';
  $base = rtrim($conn['url'] ?: 'https://api.qpxpress.com', '/');
  if (!$user || !$pass) err('QP connector is missing username / password.');

  $r = http_request('POST', $base . '/integration/token',
    ['Content-Type: application/json', 'Accept: application/json'],
    ['username' => $user, 'password' => $pass]);
  if ($r['code'] >= 400 || !$r['body']) err('QP login failed', 502, ['detail' => substr($r['body'] ?: '', 0, 300)]);
  $j = json_decode($r['body'], true);
  $token = $j['token'] ?? '';
  if (!$token) err('QP login returned no token', 502, ['detail' => substr($r['body'], 0, 300)]);

  $rows = []; $page = 1; $safety = 0;
  while ($safety++ < 100) {
    $qs = 'page_size=200&page=' . $page;
    if ($since) $qs .= '&from_date=' . urlencode($since);
    if ($until) $qs .= '&to_date='   . urlencode($until);
    $r = http_request('GET', $base . '/integration/order?' . $qs,
      ['Authorization: Bearer ' . $token, 'Accept: application/json']);
    if ($r['code'] >= 400 || !$r['body']) break;
    $j = json_decode($r['body'], true);
    $list = $j['results'] ?? [];
    if (!$list) break;
    foreach ($list as $d) {
      $cod  = (float)($d['total_amount'] ?? 0);
      $fees = (float)($d['total_fees']   ?? 0);
      $rows[] = [
        'order_id' => (string)($d['serial'] ?? $d['referenceID'] ?? ''),
        'product'  => $d['shipment_contents'] ?? '',
        'city'     => $d['city'] ?? '',
        'status'   => map_status('qp', $d['Order_Delivery_Status'] ?? ''),
        'cod'      => $cod,
        'fees'     => $fees,
        'net'      => max(0, $cod - $fees),
        'date'     => isset($d['order_date']) ? substr((string)$d['order_date'], 0, 10) : null,
        'source'   => 'QP',
        'employee' => null,
        'raw'      => $d
      ];
    }
    if (empty($j['next'])) break;
    $page++;
  }
  return $rows;
}

/* ─── Flextock ──────────────────────────────────────────────────────
 *  Auth: POST https://api.flextock.com/base/auth/  body {username,password,key} → {access,refresh}
 *  Flextock does NOT expose a bulk "list orders" endpoint — only
 *  order-status takes a known order_code_array. Until we add a way to
 *  feed the order codes (sheet upload / webhook), pulling for analytics
 *  is best-effort: we verify credentials and return [] so the user can
 *  still link the account and use sheet uploads.
 */
function fetch_flextock($conn, $since, $until) {
  $user = $conn['consumer_key']    ?? '';
  $pass = $conn['consumer_secret'] ?? '';
  $key  = $conn['token']           ?? '';
  if (!$user || !$pass || !$key) err('Flextock connector is missing username / password / api key.');
  $r = http_request('POST', 'https://api.flextock.com/base/auth/',
    ['Content-Type: application/json', 'Accept: application/json'],
    ['username' => $user, 'password' => $pass, 'key' => $key]);
  if ($r['code'] >= 400 || !$r['body']) err('Flextock login failed', 502, ['detail' => substr($r['body'] ?: '', 0, 300)]);
  $j = json_decode($r['body'], true);
  if (empty($j['access'])) err('Flextock login returned no access token', 502, ['detail' => substr($r['body'], 0, 300)]);
  // Credentials verified. Flextock has no bulk-list endpoint — return empty
  // and let the dashboard tell the user to upload a sheet for now.
  return [];
}

/* ─── Hashtag Express ───────────────────────────────────────────────
 *  Per-request auth: every POST body includes user + password.
 *  No bulk-list endpoint — only addShipment, getCurrentStatus(waybills[])
 *  and statusHistory(waybill). We verify by calling getAllGov (cheap)
 *  and return [] so the user can still save the connector.
 */
function fetch_hashtag($conn, $since, $until) {
  $user = $conn['consumer_key'] ?? '';
  $pass = $conn['token']        ?? '';
  if (!$user || !$pass) err('Hashtag connector is missing user / password.');
  $r = http_request('POST', 'https://hashtag-express.com/api/shipment.php?action=getAllGov',
    ['Content-Type: application/json', 'Accept: application/json'],
    ['user' => $user, 'password' => $pass]);
  if ($r['code'] >= 400) err('Hashtag verify failed', 502, ['detail' => substr($r['body'] ?: '', 0, 300)]);
  $j = json_decode($r['body'], true);
  if (!is_array($j) || (isset($j['error']) && stripos($j['error'], 'not found') !== false)) {
    err('Hashtag credentials rejected (User Not Found).', 401);
  }
  return [];
}

/* ─── Mylerz ────────────────────────────────────────────────────────
 *  Auth: POST {base}/token (form-urlencoded grant_type=password&username=&password=)
 *  No bulk list endpoint exposed — GetPackageListDetails takes AWB list.
 *  Base URL defaults to staging from the docs; override via connector.url.
 */
function fetch_mylerz($conn, $since, $until) {
  $user = $conn['consumer_key']    ?? '';
  $pass = $conn['consumer_secret'] ?? '';
  $base = rtrim($conn['url'] ?: 'http://41.33.122.61:8888/MylerzIntegrationStaging', '/');
  if (!$user || !$pass) err('Mylerz connector is missing username / password.');
  $body = http_build_query(['grant_type' => 'password', 'username' => $user, 'password' => $pass]);
  $r = http_request('POST', $base . '/token',
    ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    $body);
  if ($r['code'] >= 400 || !$r['body']) err('Mylerz login failed', 502, ['detail' => substr($r['body'] ?: '', 0, 300)]);
  $j = json_decode($r['body'], true);
  if (empty($j['access_token'])) err('Mylerz login returned no access_token', 502, ['detail' => substr($r['body'], 0, 300)]);
  // No bulk-list endpoint — credentials verified, return empty so the
  // connector can still be saved + used for label / status calls later.
  return [];
}

/* ─── Speedaf ──────────────────────────────────────────────────────
 *  Speedaf uses appCode + secretKey signature (similar to J&T).
 *  Public docs only document the webhook subscriber + per-AWB tracking
 *  endpoints. Bulk-listing requires Speedaf account-manager activation
 *  — we verify credentials are present and return [] until the
 *  per-merchant list endpoint is enabled.
 */
function fetch_speedaf($conn, $since, $until) {
  $app  = $conn['consumer_key'] ?? '';
  $sec  = $conn['token']        ?? '';
  $cust = $conn['consumer_secret'] ?? '';
  if (!$app || !$sec) err('Speedaf connector is missing App Code / Secret Key.');
  // No bulk-list call yet — keep the row in place so the connector is
  // usable from the dashboard the moment Speedaf enables the merchant
  // list endpoint on this app.
  return [];
}

function dispatch_provider($conn, $since, $until) {
  switch ($conn['provider']) {
    case 'bosta':    return fetch_bosta($conn, $since, $until);
    case 'jt':       return fetch_jt($conn, $since, $until);
    case 'shipblu':  return fetch_shipblu($conn, $since, $until);
    case 'turbo':    return fetch_turbo($conn, $since, $until);
    case 'qp':       return fetch_qp($conn, $since, $until);
    case 'flextock': return fetch_flextock($conn, $since, $until);
    case 'hashtag':  return fetch_hashtag($conn, $since, $until);
    case 'mylerz':   return fetch_mylerz($conn, $since, $until);
    case 'speedaf':  return fetch_speedaf($conn, $since, $until);
    default:         err('Unknown shipping provider: ' . $conn['provider']);
  }
}

/* ─── ROUTING ───────────────────────────────────────────────────────── */
$pdo    = db();
$action = $_GET['action'] ?? '';

// POST /shipping.php?action=enrich_fees&connector_id=N
//   body: {"ids": ["<delivery_id_1>", ...]}
//   returns: { "fees": { "<id>": <shipmentFees>, ... } }
//
// Bosta exposes shipmentFees only on the per-delivery GET endpoint, so
// we fetch them in parallel batches. Frontend chunks larger pulls into
// multiple requests so each round-trip fits in nginx's timeout window
// and Bosta's rate limit isn't tripped.
if ($action === 'enrich_fees') {
  $cid = (int)($_GET['connector_id'] ?? 0);
  if (!$cid) err('connector_id required');
  $body = json_decode(file_get_contents('php://input'), true);
  $ids  = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : [];
  if (!$ids) send_json(['fees' => new stdClass()]);
  $stmt = $pdo->prepare("SELECT * FROM connectors WHERE id = ? AND active = 1 AND type = 'shipping' LIMIT 1");
  $stmt->execute([$cid]);
  $conn = $stmt->fetch();
  if (!$conn || $conn['provider'] !== 'bosta') err('Only Bosta supports fee enrichment.', 400);
  $token = $conn['token'] ?? '';
  if (!$token) err('Bosta connector is missing the API key.');

  @set_time_limit(0);
  @ignore_user_abort(true);

  $fees = [];
  $rateLimitHits = 0;
  $batch = 10;
  $pauseMs = 100;
  for ($i = 0; $i < count($ids); $i += $batch) {
    $slice = array_slice($ids, $i, $batch);
    $multi = curl_multi_init();
    $handles = [];
    foreach ($slice as $id) {
      $id = (string)$id;
      if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) continue;
      $ch = curl_init('https://app.bosta.co/api/v0/deliveries/' . $id);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $token, 'Content-Type: application/json'],
      ]);
      curl_multi_add_handle($multi, $ch);
      $handles[$id] = $ch;
    }
    do {
      curl_multi_exec($multi, $running);
      if ($running) curl_multi_select($multi);
    } while ($running > 0);
    $batchHadRateLimit = false; $batchRetryAfter = 0;
    foreach ($handles as $id => $ch) {
      $body = curl_multi_getcontent($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_multi_remove_handle($multi, $ch);
      curl_close($ch);
      if ($code == 429) {
        $batchHadRateLimit = true;
        $j = json_decode($body ?: '{}', true);
        $ra = (int)($j['retryAfter'] ?? 0);
        if ($ra > $batchRetryAfter) $batchRetryAfter = $ra;
        continue;
      }
      if (!$body) continue;
      $j = json_decode($body, true);
      if (isset($j['shipmentFees'])) {
        $fees[$id] = (float)$j['shipmentFees'];
      } elseif (isset($j['wallet']['cashCycle']['shipping_fees'])) {
        $fees[$id] = (float)$j['wallet']['cashCycle']['shipping_fees'];
      }
    }
    curl_multi_close($multi);
    if ($batchHadRateLimit) {
      $rateLimitHits++;
      send_json(['fees' => $fees, 'rate_limited' => true, 'retry_after' => max(60, $batchRetryAfter), 'processed' => $i + count($slice)]);
    }
    if ($pauseMs > 0) usleep($pauseMs * 1000);
  }
  send_json(['fees' => $fees]);
}

if ($action === 'fetch') {
  $cid = (int)($_GET['connector_id'] ?? 0);
  if (!$cid) err('connector_id required');
  $stmt = $pdo->prepare("SELECT * FROM connectors WHERE id = ? AND active = 1 AND type = 'shipping' LIMIT 1");
  $stmt->execute([$cid]);
  $conn = $stmt->fetch();
  if (!$conn) err('Shipping connector not found', 404);
  $since = $_GET['since'] ?? '';
  $until = $_GET['until'] ?? '';
  if ($since && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) err('Invalid since (YYYY-MM-DD)');
  if ($until && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) err('Invalid until (YYYY-MM-DD)');
  try {
    $rows = dispatch_provider($conn, $since, $until);
    send_json(['count' => count($rows), 'provider' => $conn['provider'], 'connector' => ['id' => $conn['id'], 'name' => $conn['name']], 'rows' => $rows]);
  } catch (Throwable $e) {
    err('Fetch failed: ' . $e->getMessage(), 500);
  }
}

err('Unknown action');
