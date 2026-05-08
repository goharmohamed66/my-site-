<?php
// /api/truncate-shipping.php?token=…&confirm=YES — TRUNCATE the
// shipping_orders table. Instant. Use only when DELETE is too slow due to
// runaway duplicates and a fresh re-upload is acceptable.
require_once __DIR__ . '/_db.php';
require_token();
if (($_GET['confirm'] ?? '') !== 'YES') {
    send_json(['error' => 'Pass &confirm=YES to actually truncate.'], 400);
}
$pdo = db();
$pdo->exec("TRUNCATE TABLE shipping_orders");
send_json(['ok' => true, 'truncated' => true]);
