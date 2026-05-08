<?php
// /api/migrate-shipping-tag-by-prefix.php — Backfill brand_id on legacy
// shipping_orders rows by extracting the (prefix-mkt) pattern from each
// row's product text and matching the prefix against each brand's
// meta.sku_prefixes. Legacy rows uploaded before /api/upload.php learned
// to save brand_id are otherwise invisible to brand-scoped views.
//
//   GET ?token=…              → dry-run report (counts per prefix → brand)
//   GET ?token=…&apply=1      → run the UPDATE
//   GET ?token=…&brand_id=N   → only attribute rows for brand N (test mode)
//
// Strategy: a row's product text usually contains "(prefix-mkt)" e.g.
// "حمام سباحة (ol-gm)". Pull the prefix; look it up in a Map<prefix,brand_id>
// built from brands.meta.sku_prefixes. Rows with no pattern stay NULL.
require_once __DIR__ . '/_db.php';
require_token();

$pdo     = db();
$apply   = !empty($_GET['apply']) && $_GET['apply'] !== '0';
$onlyBrand = (int)($_GET['brand_id'] ?? 0);
// `force=1` re-attributes EVERY row (not just brand_id IS NULL). Useful
// when a Clear-All + re-upload tagged everything under one brand by
// accident — running force=1 walks every row and re-derives brand_id
// from the (prefix-mkt) pattern in the product text. Rows with no
// pattern are left untouched (NOT cleared to NULL).
$force = !empty($_GET['force']) && $_GET['force'] !== '0';

// 1. Build prefix → brand_id map
$prefMap = [];
$brands  = $pdo->query("SELECT id, name, meta FROM brands")->fetchAll();
foreach ($brands as $b) {
    if ($onlyBrand && (int)$b['id'] !== $onlyBrand) continue;
    $meta = $b['meta'] ? (json_decode($b['meta'], true) ?: []) : [];
    $pre  = isset($meta['sku_prefixes']) && is_array($meta['sku_prefixes']) ? $meta['sku_prefixes'] : [];
    foreach ($pre as $p) {
        $k = strtolower(trim((string)$p));
        if ($k !== '') $prefMap[$k] = (int)$b['id'];
    }
}
if (!count($prefMap)) {
    send_json(['error' => 'No prefixes configured on any brand. Add sku_prefixes in Settings → Configure brand first.'], 400);
}

// 2. Walk legacy rows (brand_id IS NULL) and extract prefix
$pageSize = 5000;
$lastId   = 0;
$counts   = []; // brand_id => number of rows attributed
$noMatch  = 0;
$total    = 0;
$reTokenized = '/\(\s*([a-z0-9]{1,4})\s*-\s*[a-z0-9]{1,4}\s*\)/i';

if ($apply) $pdo->beginTransaction();
$upd = $apply ? $pdo->prepare("UPDATE shipping_orders SET brand_id=? WHERE id=?") : null;

$where = $force ? "1=1" : "brand_id IS NULL";
while (true) {
    $st = $pdo->prepare("SELECT id, product, brand_id FROM shipping_orders
                         WHERE $where AND id > ?
                         ORDER BY id ASC LIMIT $pageSize");
    $st->execute([$lastId]);
    $rows = $st->fetchAll();
    if (!$rows) break;
    foreach ($rows as $r) {
        $lastId = (int)$r['id'];
        $total++;
        if (!preg_match($reTokenized, (string)$r['product'], $m)) { $noMatch++; continue; }
        $key = strtolower(trim($m[1]));
        if (!isset($prefMap[$key])) { $noMatch++; continue; }
        $bid = $prefMap[$key];
        // In force mode skip rows already correctly tagged (perf).
        if ($force && (int)$r['brand_id'] === $bid) continue;
        if (!isset($counts[$bid])) $counts[$bid] = 0;
        $counts[$bid]++;
        if ($apply) $upd->execute([$bid, $r['id']]);
    }
    if (count($rows) < $pageSize) break;
}
if ($apply) $pdo->commit();

// 3. Build a friendlier summary
$brandsById = [];
foreach ($brands as $b) $brandsById[(int)$b['id']] = $b['name'];
$summary = [];
foreach ($counts as $bid => $n) {
    $summary[] = ['brand_id' => $bid, 'brand_name' => $brandsById[$bid] ?? '?', 'rows' => $n];
}

send_json([
    'apply'        => $apply,
    'prefix_map'   => $prefMap,
    'total_legacy' => $total,
    'attributed'   => array_sum($counts),
    'no_match'     => $noMatch,
    'per_brand'    => $summary,
    'note'         => $apply
        ? 'brand_id backfilled. Re-run with apply=0 to confirm only no_match rows remain.'
        : 'Dry run — call again with &apply=1 to commit.',
]);
