<?php
// /api/fix-bosta-status.php — Re-derive status for every existing Bosta
// shipping_orders row using the connector's column_map. Idempotent.
//
// Pulls the same logic as api/shipping.php so a connector-level edit to
// returned_values (e.g. adding "Received at warehouse", "Rejected
// Return (Archived) - On Hold", …) immediately repairs every row
// already in the DB.
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

function bs_sheet_state_candidates($d) {
  $typeRaw  = (string)($d['type']['value']  ?? '');
  $stateRaw = (string)($d['state']['value'] ?? '');
  $stateCode = isset($d['state']['code']) ? (int)$d['state']['code'] : null;
  $type  = strtolower(trim($typeRaw));
  $state = strtolower(trim($stateRaw));
  $isReturnType = (strpos($type, 'return') !== false);
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
    case 46: $codeLabel = $isReturnType ? $retLabel : 'Delivery Failed';       break;
    case 47: $codeLabel = $isReturnType ? $retLabel : 'Awaiting for Action';   break;
    case 48: $codeLabel = 'Canceled';             break;
    case 49: $codeLabel = 'Terminated';           break;
  }
  $cands = [];
  if ($codeLabel !== null) {
    $cands[] = $codeLabel;
    if ($isReturnType) {
      if ($codeLabel !== 'Returned')                                            $cands[] = 'Returned';
      if ($codeLabel !== 'Returned to Origin' && $type === 'return to origin')  $cands[] = 'Returned to Origin';
    }
  } else {
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

function bs_bosta_status($d, $cm = null) {
  $candidates = bs_sheet_state_candidates($d);
  if ($cm) {
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
  $top   = $candidates ? $candidates[0] : '';
  $topU  = strtoupper(trim($top));
  if (strcasecmp($topU, 'CANCELED') === 0)  return 'CANCELED';
  if (strcasecmp($topU, 'DELIVERED') === 0) return 'DELIVERED';
  if (preg_match('/^RETURN/i', $topU))      return 'RETURNED';
  return bs_map_status((string)($d['state']['value'] ?? ''));
}

$pdo = db();

// Load the Bosta connector's column_map. shipping_orders doesn't store
// which connector each row came from, so use the same column_map for
// every Bosta row — pick the most recently updated active Bosta
// connector to avoid picking up stale / wrong-provider rows.
$globalCm = null;
$debug = ['stage' => 'init', 'connectors_seen' => []];
$cmStmt = $pdo->prepare("SELECT id, name, provider, meta, active FROM connectors WHERE provider = 'bosta' AND active = 1 ORDER BY updated_at DESC, id DESC");
$cmStmt->execute();
foreach ($cmStmt as $c) {
  $m = json_decode($c['meta'] ?? '{}', true) ?: [];
  $cm = $m['column_map'] ?? null;
  $debug['connectors_seen'][] = ['id' => (int)$c['id'], 'name' => $c['name'], 'provider' => $c['provider'], 'has_cm' => !empty($cm)];
  if ($cm && !$globalCm) $globalCm = $cm;
}
$debug['final_provider_in_cm'] = $globalCm['status'] ?? '(none)';

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
    $new = bs_bosta_status($d, $globalCm);
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

send_json(['ok' => true, 'updated' => $updated, 'skipped' => $skipped, 'transitions' => $transitions, 'used_column_map' => $globalCm, 'debug' => $debug]);
