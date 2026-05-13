<?php
// /api/fix-status.php — One-shot migration: re-map mistyped / unmapped
// shipping_orders.status values to the canonical bucket the dashboard
// uses (DELIVERED / RETURNED / PENDING / OUT_FOR_DELIVERY / CANCELED /
// UNDELIVERED). Re-runnable; rows already in canonical form are no-ops.
//
// Triggered the typo case where QP returns "Deliverd" / "Undeliverd"
// (missing E) and earlier shipping.php passed those through, so every
// such row sat in the DB invisible to the Delivered / Returned cards.
//
// Hit it via:  GET /api/fix-status.php
// (Token-protected — same as every other /api endpoint.)
require_once __DIR__ . '/_db.php';
require_token();

$pdo = db();

// Per-source mapping rules. Keys are the literal stored values (UPPER-cased
// at compare time). Add to this list when a new carrier surfaces a status
// the canonical regex doesn't already catch.
//
// QP's API misspells "Delivered" / "Undelivered" — and per QP semantics
// "Undelivered" means the shipment returned to the merchant, so it lands
// in the RETURNED bucket (matches what parseQP does for sheet uploads).
$rules = [
    'QP' => [
        'DELIVERD'   => 'DELIVERED',
        'UNDELIVERD' => 'RETURNED',
        'UNDELIVERED'=> 'RETURNED',  // in case some rows got the correct spelling
    ],
    // Generic fallback applied to every source if no per-source rule matches:
    '*' => [
        'DELIVERD'   => 'DELIVERED',  // QP typo seen elsewhere too
        'SIGNED'     => 'DELIVERED',  // J&T's "delivered" string
        'RETURNING'  => 'RETURNED',
    ],
];

$updated = 0;
$details = [];

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE shipping_orders SET status = ? WHERE id = ?");

    $rowsStmt = $pdo->query("SELECT id, source, status FROM shipping_orders WHERE status IS NOT NULL AND status <> ''");
    while ($r = $rowsStmt->fetch()) {
        $cur = strtoupper(trim((string)$r['status']));
        $src = strtoupper(trim((string)$r['source']));
        $new = null;

        if (isset($rules[$src][$cur])) $new = $rules[$src][$cur];
        elseif (isset($rules['*'][$cur])) $new = $rules['*'][$cur];

        if ($new !== null && $new !== $r['status']) {
            $upd->execute([$new, $r['id']]);
            $updated++;
            $k = $src . ': ' . $cur . ' → ' . $new;
            $details[$k] = ($details[$k] ?? 0) + 1;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    send_json(['error' => $e->getMessage()], 500);
}

send_json(['ok' => true, 'updated' => $updated, 'details' => $details]);
