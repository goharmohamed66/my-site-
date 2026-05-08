<?php
// /api/dedupe-shipping.php — Remove duplicate shipping_orders rows.
// "Duplicate" = same (order_id, product, date, source) tuple. Keeps the
// row with the lowest id, deletes the rest.
//
//   GET ?token=…              → dry-run (returns groups + how many to delete)
//   GET ?token=…&apply=1      → actually DELETE the dups
//   GET ?token=…&strict=1     → also dedupe rows where order_id IS NULL
//                                by hashing (product + date + source +
//                                cod + net) — careful, can over-delete
//                                if your carrier export has legitimate
//                                same-row entries.
require_once __DIR__ . '/_db.php';
require_token();

$pdo    = db();
$apply  = !empty($_GET['apply']) && $_GET['apply'] !== '0';
$strict = !empty($_GET['strict']) && $_GET['strict'] !== '0';

// 1a. Carrier-Waybill dedupe (best signal — every J&T / Bosta / etc.
//     row has a unique waybill in raw JSON). Same waybill twice = a
//     re-upload of the same shipment, safe to drop.
// Count first (cheap aggregate) to report.
$wbCount = $pdo->query("
    SELECT COUNT(*) AS groups, SUM(n - 1) AS deletions
    FROM (
      SELECT JSON_UNQUOTE(JSON_EXTRACT(raw, '$.Waybill')) AS wb, COUNT(*) AS n
      FROM shipping_orders
      WHERE JSON_EXTRACT(raw, '$.Waybill') IS NOT NULL
        AND JSON_UNQUOTE(JSON_EXTRACT(raw, '$.Waybill')) <> ''
      GROUP BY wb
      HAVING COUNT(*) > 1
    ) sub
")->fetch();
$wbGroups  = (int)($wbCount['groups'] ?? 0);
$wbDeleted = (int)($wbCount['deletions'] ?? 0);
// Single bulk DELETE — per-group statements would 504 on 18k+ groups.
if ($apply && $wbGroups > 0) {
    $sql = "DELETE t FROM shipping_orders t
            JOIN (
              SELECT MIN(id) AS keep_id, JSON_UNQUOTE(JSON_EXTRACT(raw, '$.Waybill')) AS wb
              FROM shipping_orders
              WHERE JSON_EXTRACT(raw, '$.Waybill') IS NOT NULL
                AND JSON_UNQUOTE(JSON_EXTRACT(raw, '$.Waybill')) <> ''
              GROUP BY wb
            ) k
            ON JSON_UNQUOTE(JSON_EXTRACT(t.raw, '$.Waybill')) = k.wb
            WHERE t.id <> k.keep_id";
    $pdo->exec($sql);
}

// 1b. order_id dedupe (for non-J&T sources that fill order_id)
$dup1 = $pdo->query("
    SELECT order_id, product, date, source, COUNT(*) AS n, MIN(id) AS keep_id
    FROM shipping_orders
    WHERE order_id IS NOT NULL AND order_id <> ''
    GROUP BY order_id, product, date, source
    HAVING COUNT(*) > 1
")->fetchAll();

$toDelete = $wbDeleted;
$groups   = $wbGroups;
foreach ($dup1 as $g) {
    $groups++;
    $toDelete += ((int)$g['n'] - 1);
    if ($apply) {
        $del = $pdo->prepare("DELETE FROM shipping_orders
                              WHERE order_id = ? AND product = ? AND date <=> ? AND source = ?
                              AND id <> ?");
        $del->execute([$g['order_id'], $g['product'], $g['date'], $g['source'], (int)$g['keep_id']]);
    }
}

// 2. Optional: rows with NULL order_id — dedupe by tuple including amounts
$strictDeleted = 0; $strictGroups = 0;
if ($strict) {
    $dup2 = $pdo->query("
        SELECT product, date, source, cod, fees, net, COUNT(*) AS n, MIN(id) AS keep_id
        FROM shipping_orders
        WHERE order_id IS NULL OR order_id = ''
        GROUP BY product, date, source, cod, fees, net
        HAVING COUNT(*) > 1
    ")->fetchAll();
    foreach ($dup2 as $g) {
        $strictGroups++;
        $strictDeleted += ((int)$g['n'] - 1);
        if ($apply) {
            $del = $pdo->prepare("DELETE FROM shipping_orders
                                  WHERE (order_id IS NULL OR order_id = '')
                                  AND product = ? AND date <=> ? AND source = ?
                                  AND cod = ? AND fees = ? AND net = ?
                                  AND id <> ?");
            $del->execute([$g['product'], $g['date'], $g['source'], $g['cod'], $g['fees'], $g['net'], (int)$g['keep_id']]);
        }
    }
}

// 3. Counts after
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM shipping_orders")->fetchColumn();

send_json([
    'apply'           => $apply,
    'strict'          => $strict,
    'duplicate_groups'=> $groups,
    'rows_to_delete'  => $toDelete,
    'strict_groups'   => $strictGroups,
    'strict_to_delete'=> $strictDeleted,
    'rows_remaining'  => $remaining,
    'note'            => $apply
        ? 'Duplicates removed. Re-run with apply=0 to confirm zero remain.'
        : 'Dry run — call again with &apply=1 to commit.',
]);
