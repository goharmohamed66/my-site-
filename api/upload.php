<?php
// POST /api/upload.php — bulk insert shipping orders
// Body: JSON array of orders, each like:
//   { order_id, product, city, status, cod, fees, net, date, source, employee, raw }
require_once __DIR__ . '/_db.php';
require_token();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  send_json(['error' => 'Use POST'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
  send_json(['error' => 'Body must be a JSON array of orders'], 400);
}

$pdo = db();

// Hard-scope every uploaded row to the brand the user was on when they
// hit Upload. The frontend passes ?brand_id=N (active brand from topbar).
// Falls back to per-row $r['brand_id'] for clients that prefer to set it
// per record. NULL means "global / legacy upload" — kept for compatibility.
$brandIdQuery = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;

// Build a prefix → brand_id lookup ONCE so even uploads done from the
// "All brands" view (where $brandIdQuery === 0) auto-attribute each row
// to the right brand by parsing the "(prefix-mkt)" pattern from the
// product text. Same pipeline as the one-shot
// migrate-shipping-tag-by-prefix endpoint, applied per row at insert
// time so future uploads never need a follow-up migration.
$prefMap = [];
$brands = $pdo->query("SELECT id, meta FROM brands")->fetchAll();
foreach ($brands as $b) {
    $meta = $b['meta'] ? (json_decode($b['meta'], true) ?: []) : [];
    $pre  = isset($meta['sku_prefixes']) && is_array($meta['sku_prefixes']) ? $meta['sku_prefixes'] : [];
    foreach ($pre as $p) {
        $k = strtolower(trim((string)$p));
        if ($k !== '') $prefMap[$k] = (int)$b['id'];
    }
}
$prefRe = '/\(\s*([a-z0-9]{1,4})\s*-\s*[a-z0-9]{1,4}\s*\)/i';

// Product-name → brand_id map. Lets confirmation rows (Zomeza/Wesell
// product names DON'T carry a (prefix-mkt) marker) get auto-attributed
// by matching the row's product against each brand's product list.
// Normalization: same pipeline shipping.html / FM Updater use, so the
// keys line up with how the front-end matches sheet rows to brands.
function up_normalize_ar($s){
    $s = (string)$s;
    $s = preg_replace('/[ةه]/u', 'ه', $s);
    $s = preg_replace('/[أإآا]/u', 'ا', $s);
    $s = preg_replace('/ى/u', 'ي', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower(trim($s), 'UTF-8');
}
function up_strip_qty($s){
    // Strip trailing "(prefix-mkt)" marker and any "x N" quantity suffix.
    $s = preg_replace('/\s*\([a-z0-9]{1,4}-[a-z0-9]{1,4}\)\s*$/iu', '', (string)$s);
    $s = preg_replace('/\s*[xX×]\s*\d+\s*$/u', '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
function up_nkey($s){ return up_normalize_ar(up_strip_qty($s)); }

$nameMap = [];
$pStmt = $pdo->query("SELECT name, brand_id FROM products WHERE brand_id IS NOT NULL");
foreach ($pStmt as $p) {
    $k = up_nkey($p['name']);
    if ($k !== '' && !isset($nameMap[$k])) $nameMap[$k] = (int)$p['brand_id'];
}

// Per-row idempotency: skip rows we've already stored. Two layers:
//   1. Waybill (raw.Waybill) — historical J&T sheet path. Pre-loaded in
//      bulk so we don't pay 18k JSON_EXTRACTs per upload.
//   2. (source, order_id) — covers every carrier API pull. The Shipping
//      Analytics page POSTs the API rows here after every pull, so
//      refetching the same date range must not duplicate rows.
$existingWb = [];
$wbStmt = $pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(raw, '$.Waybill')) AS w
                        FROM shipping_orders
                        WHERE JSON_EXTRACT(raw, '$.Waybill') IS NOT NULL");
foreach ($wbStmt as $r) {
    if (!empty($r['w'])) $existingWb[$r['w']] = 1;
}

$existingKey = [];
$keyStmt = $pdo->query("SELECT source, order_id FROM shipping_orders
                         WHERE order_id IS NOT NULL AND order_id <> ''");
foreach ($keyStmt as $r) {
    $existingKey[strtolower((string)$r['source']) . '|' . (string)$r['order_id']] = 1;
}

$pdo->beginTransaction();

$stmt = $pdo->prepare("INSERT INTO shipping_orders
  (order_id, product, city, status, cod, fees, net, date, source, brand_id, employee, raw)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

// update_fees=1 flips this from "skip duplicates" to "if the row
// already exists, refresh its status (and fees/net when the incoming
// row carries a non-zero fee) from the incoming data". Used by:
//   - Bosta's background fee enrichment, so the DB catches up with
//     per-delivery shipmentFees values fetched after the initial pull.
//   - Every carrier sync, so statuses (Delivered / Returned / etc.)
//     track Bosta/JT/etc. as orders progress instead of being frozen
//     at first-seen status.
// Smart fee update: status is overwritten unconditionally; fees+net are
// only overwritten when the incoming fees > 0, so a status refresh
// (initial pull, fees not yet enriched) doesn't wipe values already
// populated by the enrichment background job.
$updateMode = !empty($_GET['update_fees']);
$updateStmt = $pdo->prepare(
  "UPDATE shipping_orders SET
     fees   = CASE WHEN ? > 0 THEN ? ELSE fees END,
     net    = CASE WHEN ? > 0 THEN ? ELSE net  END,
     status = ?
   WHERE source = ? AND order_id = ?"
);

$inserted = 0; $updated = 0; $errors = []; $skippedDup = 0;
foreach ($body as $i => $r) {
  try {
    // Skip if this Waybill is already in the DB — keeps Upload idempotent
    // (clicking the button twice or the frontend re-firing won't double the data).
    $wb = '';
    if (isset($r['raw']) && is_array($r['raw']) && !empty($r['raw']['Waybill'])) {
        $wb = (string)$r['raw']['Waybill'];
    }
    $oid = isset($r['order_id']) ? trim((string)$r['order_id']) : '';
    $src = isset($r['source'])   ? trim((string)$r['source'])   : '';
    if ($wb !== '' && isset($existingWb[$wb])) {
      if ($updateMode && $oid !== '' && $src !== '') {
        $fee = isset($r['fees']) ? (float)$r['fees'] : 0;
        $net = isset($r['net'])  ? (float)$r['net']  : 0;
        $updateStmt->execute([
          $fee, $fee,
          $net, $net,
          isset($r['status']) ? (string)$r['status'] : '',
          $src, $oid,
        ]);
        $updated++;
      } else { $skippedDup++; }
      continue;
    }
    if ($wb !== '') $existingWb[$wb] = 1;
    // (source, order_id) dedup — covers carrier API pulls where raw has
    // no .Waybill field but order_id is the per-shipment unique id.
    if ($oid !== '' && $src !== '') {
      $k = strtolower($src) . '|' . $oid;
      if (isset($existingKey[$k])) {
        if ($updateMode) {
          $fee = isset($r['fees']) ? (float)$r['fees'] : 0;
          $net = isset($r['net'])  ? (float)$r['net']  : 0;
          $updateStmt->execute([
            $fee, $fee,
            $net, $net,
            isset($r['status']) ? (string)$r['status'] : '',
            $src, $oid,
          ]);
          $updated++;
        } else { $skippedDup++; }
        continue;
      }
      $existingKey[$k] = 1;
    }
    // Resolution priority — strongest signal first. Carrier export sheets
    // contain orders from ALL brands mixed (the upload's "active brand"
    // doesn't override each row's actual owner). Order:
    //   1. (prefix-mkt) in product text → that brand
    //   2. Product name MATCHES a known DB product → that product's brand
    //      (lets confirmation sheets — Zomeza / Wesell — auto-attribute
    //      since their product names don't carry the prefix marker)
    //   3. Row-level brand_id (rare; explicit per-row override)
    //   4. ?brand_id= query (the active topbar brand at upload time)
    //   5. NULL — kept for compatibility (untagged "All brands" upload).
    $bid = null;
    if (!empty($r['product']) && preg_match($prefRe, (string)$r['product'], $m)) {
        $key = strtolower(trim($m[1]));
        if (isset($prefMap[$key])) $bid = $prefMap[$key];
    }
    if ($bid === null && !empty($r['product'])) {
        $nk = up_nkey($r['product']);
        if ($nk !== '' && isset($nameMap[$nk])) $bid = $nameMap[$nk];
    }
    if ($bid === null && isset($r['brand_id']) && (int)$r['brand_id'] > 0) {
        $bid = (int)$r['brand_id'];
    }
    if ($bid === null && $brandIdQuery > 0) {
        $bid = $brandIdQuery;
    }
    $stmt->execute([
      isset($r['order_id']) ? (string)$r['order_id'] : null,
      isset($r['product']) ? (string)$r['product'] : '',
      isset($r['city']) ? (string)$r['city'] : '',
      isset($r['status']) ? (string)$r['status'] : '',
      isset($r['cod']) ? (float)$r['cod'] : 0,
      isset($r['fees']) ? (float)$r['fees'] : 0,
      isset($r['net']) ? (float)$r['net'] : 0,
      !empty($r['date']) ? substr((string)$r['date'], 0, 10) : null,
      isset($r['source']) ? (string)$r['source'] : '',
      $bid,
      isset($r['employee']) ? (string)$r['employee'] : null,
      isset($r['raw']) ? json_encode($r['raw'], JSON_UNESCAPED_UNICODE) : null
    ]);
    $inserted++;
  } catch (Exception $e) {
    $errors[] = ['index' => $i, 'error' => $e->getMessage()];
  }
}

$pdo->commit();

send_json(['inserted' => $inserted, 'updated' => $updated, 'skipped_duplicates' => $skippedDup, 'errors' => $errors, 'brand_id' => $brandIdQuery ?: null]);
