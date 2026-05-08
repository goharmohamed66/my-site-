<?php
// /api/migrate-shipping-brand.php — One-shot migration: add `brand_id` to
// shipping_orders so each uploaded shipping row is hard-scoped to a brand.
// Safe to call repeatedly: detects the column first and skips if present.
//
//   GET ?token=…           → status (does the column exist? how many rows
//                            have brand_id NULL?)
//   GET ?token=…&apply=1   → ALTER TABLE to add the column + index if missing
//
// Without this column the dashboard fell back to text-pattern filtering
// (extractCodes(prod).store === sku_prefix), which silently failed for
// historical uploads where the product name didn't carry the (prefix-mkt)
// pattern — so a brand looked empty even though rows existed for it.
require_once __DIR__ . '/_db.php';
require_token();

$pdo   = db();
$apply = !empty($_GET['apply']) && $_GET['apply'] !== '0';

// 1. Detect existing column
$col = $pdo->query("SHOW COLUMNS FROM shipping_orders LIKE 'brand_id'")->fetch();
$alreadyExists = !!$col;

$status = [
    'column_exists' => $alreadyExists,
    'apply'         => $apply,
];

if ($alreadyExists) {
    // Already migrated — just report counts to confirm health.
    $row = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(brand_id IS NULL) AS without_brand,
        SUM(brand_id IS NOT NULL) AS with_brand
        FROM shipping_orders")->fetch();
    $status['rows'] = $row;
    send_json($status + ['note' => 'Column already exists. No migration needed.']);
}

if (!$apply) {
    send_json($status + [
        'note' => 'Dry run — column missing. Call again with &apply=1 to add it.',
        'sql'  => "ALTER TABLE shipping_orders ADD COLUMN brand_id INT NULL AFTER source, ADD INDEX idx_brand (brand_id)",
    ]);
}

// 2. Apply
try {
    $pdo->exec("ALTER TABLE shipping_orders ADD COLUMN brand_id INT NULL AFTER source, ADD INDEX idx_brand (brand_id)");
    send_json($status + ['ok' => true, 'note' => 'brand_id column + index added.']);
} catch (Exception $e) {
    send_json($status + ['error' => $e->getMessage()], 500);
}
