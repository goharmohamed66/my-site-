<?php
// GET    /api/orders.php — list shipping orders (filters: source, from, to, limit)
// DELETE /api/orders.php?all=1   → wipe every shipping order (used by Clear All)
require_once __DIR__ . '/_db.php';
require_token();

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── DELETE — wipe orders. Scoped or full depending on caller:
//   ?all=1                   → nuclear: every shipping_orders row
//   ?brand_id=N              → only rows tagged for brand N (NULL rows untouched)
//   ?brand_id=N&legacy=1     → rows for brand N PLUS NULL-brand legacy rows
// Used by Shipping Analytics → Clear All. Frontend picks the right form
// based on the active topbar brand: "All brands" → all=1, specific brand
// → brand_id=N so we don't nuke other brands' data while clearing one.
if ($method === 'DELETE') {
  if (!empty($_GET['brand_id'])) {
    $bid = (int)$_GET['brand_id'];
    if ($bid <= 0) send_json(['error' => 'invalid brand_id'], 400);
    if (!empty($_GET['legacy'])) {
      $st = $pdo->prepare("DELETE FROM shipping_orders WHERE brand_id = ? OR brand_id IS NULL");
      $st->execute([$bid]);
    } else {
      $st = $pdo->prepare("DELETE FROM shipping_orders WHERE brand_id = ?");
      $st->execute([$bid]);
    }
    send_json(['ok' => true, 'deleted' => 'brand', 'brand_id' => $bid, 'rows' => $st->rowCount()]);
  }
  if (empty($_GET['all'])) send_json(['error' => 'pass ?all=1 (nuclear) or ?brand_id=N (scoped) to confirm'], 400);
  $pdo->exec("DELETE FROM shipping_orders");
  send_json(['ok' => true, 'deleted' => 'all']);
}

$where = [];
$args = [];

if (!empty($_GET['source'])) {
  $where[] = "source = ?";
  $args[] = $_GET['source'];
}
if (!empty($_GET['from'])) {
  $where[] = "date >= ?";
  $args[] = $_GET['from'];
}
if (!empty($_GET['to'])) {
  $where[] = "date <= ?";
  $args[] = $_GET['to'];
}
// Brand scope. Two modes:
//   ?brand_id=N         → only rows tagged for brand N (legacy NULL rows excluded)
//   ?brand_id=N&legacy=1 → brand N rows PLUS rows with brand_id IS NULL
//                          (so old uploads still flow through prefix matching
//                          on the client; safer default once we trust the data)
if (!empty($_GET['brand_id'])) {
  $bid = (int)$_GET['brand_id'];
  if (!empty($_GET['legacy'])) { $where[] = "(brand_id = ? OR brand_id IS NULL)"; $args[] = $bid; }
  else                          { $where[] = "brand_id = ?"; $args[] = $bid; }
}

$limit = isset($_GET['limit']) ? max(1, min(50000, (int)$_GET['limit'])) : 20000;

// Detect whether the brand_id column has been migrated; older deployments
// may still be on the legacy schema. If missing, drop it from the SELECT
// so the query doesn't blow up.
$hasBrand = (bool)$pdo->query("SHOW COLUMNS FROM shipping_orders LIKE 'brand_id'")->fetch();
$cols = "id, order_id, product, city, status, cod, fees, net, date, source"
      . ($hasBrand ? ", brand_id" : "")
      . ", employee, raw, created_at";

$sql = "SELECT $cols FROM shipping_orders";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY date DESC, id DESC LIMIT $limit";

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// Decode raw JSON column for convenience
foreach ($rows as &$r) {
  if (!empty($r['raw'])) {
    $decoded = json_decode($r['raw'], true);
    if ($decoded !== null) $r['raw'] = $decoded;
  }
}

send_json(['count' => count($rows), 'rows' => $rows]);
