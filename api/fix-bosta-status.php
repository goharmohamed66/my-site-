<?php
// /api/fix-bosta-status.php — Re-derive status for every existing Bosta
// shipping_orders row using the type + state combined logic. Use after
// deploying the Bosta status mapping fix to repair rows pulled with the
// old single-field mapping that treated "Return to Origin || Delivered"
// as DELIVERED instead of RETURNED.
//
// Idempotent — running it twice changes nothing.
require_once __DIR__ . '/_db.php';
require_token();

function bs_map_status($s) {
  $s = strtoupper(trim((string)$s));
  if (preg_match('/(UN[ \-]?DELIVER(E)?D|FAILED|EXCEPTION)/iu', $s)) return 'UNDELIVERED';
  if (preg_match('/(DELIVER(E)?D|تم التسليم|سُلِّم)/iu', $s)) return 'DELIVERED';
  if (preg_match('/(RETURN|RTO|REJECT|REFUSE|راجع|مرتجع|إرجاع|رفض)/iu', $s)) return 'RETURNED';
  if (preg_match('/(OUT[ \-]?FOR[ \-]?DELIVERY|IN[ \-]?TRANSIT|في الطريق|للتوصيل)/iu', $s)) return 'OUT_FOR_DELIVERY';
  if (preg_match('/(CANCEL|ملغي)/iu', $s)) return 'CANCELED';
  if (preg_match('/(PENDING|HOLD|SCHEDULED|قيد الانتظار|جاهز)/iu', $s)) return 'PENDING';
  return $s ?: 'UNKNOWN';
}

function bs_bosta_status($d) {
  $type  = (string)($d['type']['value']  ?? '');
  $state = (string)($d['state']['value'] ?? '');
  $sU = strtoupper(trim($state));
  $tU = strtoupper(trim($type));
  if (preg_match('/^(CANCEL|TERMINAT)/i', $sU)) return 'CANCELED';
  $isReturnType = (stripos($tU, 'RETURN') !== false);
  if (strcasecmp($sU, 'Delivered') === 0) {
    return $isReturnType ? 'RETURNED' : 'DELIVERED';
  }
  $generic = bs_map_status($state);
  if ($isReturnType && in_array($generic, ['UNDELIVERED','OUT_FOR_DELIVERY','PENDING','UNKNOWN'])) {
    return 'RETURN_IN_TRANSIT';
  }
  return $generic;
}

$pdo = db();

$updated = 0;
$skipped = 0;
$transitions = [];

$pdo->beginTransaction();
try {
  $upd = $pdo->prepare("UPDATE shipping_orders SET status = ? WHERE id = ?");
  $st  = $pdo->query("SELECT id, status, raw FROM shipping_orders WHERE source = 'Bosta' AND raw IS NOT NULL");
  while ($r = $st->fetch()) {
    if (empty($r['raw'])) { $skipped++; continue; }
    $d = json_decode($r['raw'], true);
    if (!is_array($d)) { $skipped++; continue; }
    $new = bs_bosta_status($d);
    if ($new === $r['status']) { $skipped++; continue; }
    $upd->execute([$new, $r['id']]);
    $updated++;
    $k = (string)$r['status'] . ' → ' . $new;
    $transitions[$k] = ($transitions[$k] ?? 0) + 1;
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  send_json(['error' => $e->getMessage()], 500);
}

send_json(['ok' => true, 'updated' => $updated, 'skipped' => $skipped, 'transitions' => $transitions]);
