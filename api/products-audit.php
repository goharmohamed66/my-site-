<?php
// /api/products-audit.php — One-shot audit: find products whose Drive folder
// does NOT live inside their brand's Drive folder (or its PRODUCTS subfolder)
// and report / clean them up. This fixes cross-brand pollution that
// happened before POST /products.php enforced the parent check.
//
//   GET   ?token=…              → dry run, returns the orphan list (no writes)
//   GET   ?token=…&apply=1      → also DELETE orphans from DB only
//                                  (Drive folders are NEVER touched)
//   GET   ?token=…&brand_id=N   → restrict to one brand
//
// Output: { rows: [...], removed: N, kept: N, errors: [...] }
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/google-config.php';

// Self-contained Drive helpers (audit_ prefix to avoid clash with the
// gd_*_p ones in products.php should both ever be loaded together).
function audit_gd_connector(PDO $pdo): ?array {
    $st = $pdo->prepare("SELECT id, name, token, meta FROM connectors
                         WHERE type='storage' AND provider='google_drive' AND active=1
                         ORDER BY id LIMIT 1");
    $st->execute(); $r = $st->fetch();
    if (!$r) return null;
    $r['meta_decoded'] = $r['meta'] ? json_decode($r['meta'], true) : [];
    return $r;
}
function audit_gd_token(PDO $pdo, array $conn): string {
    $meta = $conn['meta_decoded'];
    $exp = isset($meta['token_expires_at']) ? strtotime($meta['token_expires_at']) : 0;
    if ($exp - 60 > time() && !empty($conn['token'])) return $conn['token'];
    if (empty($meta['refresh_token'])) send_json(['error' => 'Drive refresh token missing'], 401);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $meta['refresh_token'], 'grant_type' => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $tok = json_decode($resp, true);
    if ($code !== 200 || empty($tok['access_token'])) {
      $detail = isset($tok['error']) ? ($tok['error'] . ': ' . ($tok['error_description'] ?? '')) : substr((string)$resp, 0, 200);
      send_json([
        'error'  => 'Drive token expired — open https://indigo-dog-836598.hostingersite.com/api/google-oauth-start.php to re-connect Google Drive, then try again. (' . trim($detail) . ')',
        'reauth_url' => '/api/google-oauth-start.php',
      ], 401);
    }
    $meta['token_expires_at'] = date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600));
    $upd = $pdo->prepare("UPDATE connectors SET token=?, meta=? WHERE id=?");
    $upd->execute([$tok['access_token'], json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$conn['id']]);
    return $tok['access_token'];
}
// Read parents[] for a Drive file. Returns [] on any error (treated as orphan).
function audit_gd_parents(string $accessToken, string $fileId): array {
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId)
                  . '?fields=id,parents,name,trashed');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return ['__http' => $code, '__resp' => $r];
    return json_decode($r, true) ?: [];
}
// List immediate children of a folder (id+mimeType+name), used to discover
// each brand's PRODUCTS / Products subfolder so legitimate products living
// one level deeper aren't flagged as orphans.
function audit_gd_list(string $accessToken, string $folderId): array {
    $url = 'https://www.googleapis.com/drive/v3/files?'
         . http_build_query([
             'q' => "'$folderId' in parents and trashed=false and mimeType='application/vnd.google-apps.folder'",
             'fields' => 'files(id,name,mimeType)',
             'pageSize' => 200,
         ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code === 200 ? (json_decode($r, true)['files'] ?? []) : [];
}

require_token();
$pdo = db();
$apply   = !empty($_GET['apply']) && $_GET['apply'] !== '0';
$brandId = (int)($_GET['brand_id'] ?? 0);

$conn = audit_gd_connector($pdo);
if (!$conn) send_json(['error' => 'No Drive connector'], 400);
$tok = audit_gd_token($pdo, $conn);

// Load brands once (id → drive_folder_id + name + cached allowed parents)
$bSql = "SELECT id, name, drive_folder_id FROM brands"
      . ($brandId ? " WHERE id=" . $brandId : "");
$brandRows = $pdo->query($bSql)->fetchAll();
$brands = [];
foreach ($brandRows as $b) {
    $allowed = [];
    if (!empty($b['drive_folder_id'])) {
        $allowed[] = $b['drive_folder_id'];
        // Also accept any direct subfolder (e.g. "PRODUCTS", "Products") —
        // legacy product folders may live one level deep.
        foreach (audit_gd_list($tok, $b['drive_folder_id']) as $sub) {
            if (in_array(strtolower($sub['name']), ['products', 'منتجات'], true)) {
                $allowed[] = $sub['id'];
            }
        }
    }
    $brands[(int)$b['id']] = [
        'name' => $b['name'],
        'brand_folder' => $b['drive_folder_id'],
        'allowed_parents' => $allowed,
    ];
}

// Walk products
$pSql = "SELECT id, brand_id, name, drive_folder_id FROM products"
      . ($brandId ? " WHERE brand_id=" . $brandId : "")
      . " ORDER BY id";
$prods = $pdo->query($pSql)->fetchAll();

$report = [];
$removed = 0; $kept = 0; $skipped = 0;

foreach ($prods as $p) {
    $bid = (int)$p['brand_id'];
    $brand = $brands[$bid] ?? null;
    if (!$brand) {
        // Brand row missing — skip entirely (orphaned product, separate issue).
        $report[] = [
            'product_id' => (int)$p['id'], 'product_name' => $p['name'],
            'brand_id' => $bid, 'brand_name' => '(missing)',
            'verdict' => 'skip', 'reason' => 'brand row not found',
        ];
        $skipped++; continue;
    }
    $folderId = $p['drive_folder_id'] ?? '';
    if ($folderId === '') {
        // No Drive link — leave alone, the user might still want it.
        $report[] = [
            'product_id' => (int)$p['id'], 'product_name' => $p['name'],
            'brand_id' => $bid, 'brand_name' => $brand['name'],
            'verdict' => 'skip', 'reason' => 'no drive_folder_id',
        ];
        $skipped++; continue;
    }
    if (empty($brand['brand_folder'])) {
        // Brand has no Drive folder configured → cannot validate, leave alone.
        $report[] = [
            'product_id' => (int)$p['id'], 'product_name' => $p['name'],
            'brand_id' => $bid, 'brand_name' => $brand['name'],
            'verdict' => 'skip', 'reason' => 'brand has no drive_folder_id',
        ];
        $skipped++; continue;
    }
    $info = audit_gd_parents($tok, $folderId);
    // Product folder vanished from Drive entirely → leave alone (separate
    // sync concern, not a cross-brand pollution issue).
    if (isset($info['__http']) && (int)$info['__http'] === 404) {
        $report[] = [
            'product_id' => (int)$p['id'], 'product_name' => $p['name'],
            'brand_id' => $bid, 'brand_name' => $brand['name'],
            'drive_folder_id' => $folderId,
            'verdict' => 'skip', 'reason' => 'drive folder not found (404)',
        ];
        $skipped++; continue;
    }
    if (isset($info['__http'])) {
        $report[] = [
            'product_id' => (int)$p['id'], 'product_name' => $p['name'],
            'brand_id' => $bid, 'brand_name' => $brand['name'],
            'drive_folder_id' => $folderId,
            'verdict' => 'skip', 'reason' => 'drive lookup HTTP ' . $info['__http'],
        ];
        $skipped++; continue;
    }
    if (!empty($info['trashed'])) {
        // Already trashed in Drive — also remove the row (apply mode only).
        $report[] = [
            'product_id' => (int)$p['id'], 'product_name' => $p['name'],
            'brand_id' => $bid, 'brand_name' => $brand['name'],
            'drive_folder_id' => $folderId,
            'drive_folder_name' => $info['name'] ?? '',
            'verdict' => 'remove', 'reason' => 'drive folder is trashed',
        ];
        if ($apply) $pdo->prepare("DELETE FROM products WHERE id=?")->execute([(int)$p['id']]);
        $removed++; continue;
    }
    $parents = $info['parents'] ?? [];
    $matchedAllowed = false;
    foreach ($parents as $pp) {
        if (in_array($pp, $brand['allowed_parents'], true)) { $matchedAllowed = true; break; }
    }
    if ($matchedAllowed) {
        $kept++;
        continue; // healthy — don't include in report unless verbose mode added later
    }
    // ORPHAN: product folder lives somewhere outside this brand. Most
    // likely belongs to another brand. We DO NOT touch Drive — only
    // remove the DB row so the dashboard stops misattributing it.
    $report[] = [
        'product_id' => (int)$p['id'], 'product_name' => $p['name'],
        'brand_id' => $bid, 'brand_name' => $brand['name'],
        'drive_folder_id' => $folderId,
        'drive_folder_name' => $info['name'] ?? '',
        'drive_parents' => $parents,
        'brand_folder' => $brand['brand_folder'],
        'verdict' => 'remove', 'reason' => 'parent does not match brand folder',
    ];
    if ($apply) $pdo->prepare("DELETE FROM products WHERE id=?")->execute([(int)$p['id']]);
    $removed++;
}

send_json([
    'apply'    => $apply,
    'brand_id' => $brandId ?: null,
    'kept'     => $kept,
    'removed'  => $removed,
    'skipped'  => $skipped,
    'rows'     => $report,
    'note'     => $apply
        ? 'DB rows for orphans were deleted. Drive folders were NOT touched.'
        : 'Dry run — call again with &apply=1 to remove the orphan DB rows.',
]);
