<?php
// /api/products.php — Products inside a brand. Each Product = one folder
// under {brand}/Products/{Product Name}/, copied from the connector's
// PRODUCT TEMPLATE (saved on connector.meta.product_template_folder_id).
//
//   GET    ?id=N            → single product
//   GET    ?brand_id=N      → all products in a brand
//   GET                      → all products (filterable)
//   POST   { brand_id, name }   → copy PRODUCT TEMPLATE → row
//   PATCH  { id, name?, status?, build_key? }
//   DELETE ?id=N
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/google-config.php';

// gd_* helpers (inlined for isolation)
function gd_connector_p(PDO $pdo): ?array {
    $st = $pdo->prepare("SELECT id, name, token, meta FROM connectors
                         WHERE type='storage' AND provider='google_drive' AND active=1
                         ORDER BY id LIMIT 1");
    $st->execute(); $r = $st->fetch();
    if (!$r) return null;
    $r['meta_decoded'] = $r['meta'] ? json_decode($r['meta'], true) : [];
    return $r;
}
function gd_token_p(PDO $pdo, array $conn): string {
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
      // Refresh tokens from a Google Cloud project in "Testing" mode
      // expire after 7 days. Tell the user EXACTLY where to re-authorize
      // (the frontend just displays `error` verbatim, so embed the URL
      // in the message itself).
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
function gd_list_p(string $accessToken, string $folderId): array {
    $url = 'https://www.googleapis.com/drive/v3/files?'
         . http_build_query([
             'q' => "'$folderId' in parents and trashed=false",
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
function gd_mkdir_p(string $accessToken, string $parentId, string $name): ?string {
    $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id,name');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) {
        // Surface the Drive error so the caller can show a useful message.
        error_log('gd_mkdir_p failed parent=' . $parentId . ' name=' . $name . ' code=' . $code . ' resp=' . $r);
        return null;
    }
    return json_decode($r, true)['id'] ?? null;
}
// Rich error variant — returns ['id' => ..., 'error' => null] or ['id' => null, 'error' => '...']
function gd_mkdir_p_diag(string $accessToken, string $parentId, string $name): array {
    $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id,name');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) {
        $detail = json_decode($r, true)['error']['message'] ?? $r ?? ('HTTP ' . $code);
        return ['id' => null, 'error' => $detail];
    }
    return ['id' => json_decode($r, true)['id'] ?? null, 'error' => null];
}
// Soft-delete a Drive file/folder (moves to Trash). The user explicitly
// confirmed via the UI; we never call this from auto-cascade paths.
// Returns ['ok' => bool, 'code' => int, 'error' => string]
//
// Strategy when the connected Google account doesn't own the folder
// (common for Shared Drive / "shared with me" content):
//   1. PATCH trashed=true with supportsAllDrives=true (handles Shared Drives).
//   2. On 403 "insufficient permissions": try to detach the folder from
//      the brand's parent (PATCH removeParents=BRAND_FOLDER) so it
//      disappears from the dashboard's view of the brand without needing
//      ownership of the file.
//   3. On any other failure: return the message verbatim so the UI can
//      surface it to the user — they might need to grant the connector
//      account Editor access in Drive.
function gd_trash_p(string $accessToken, string $fileId, ?string $brandFolderId = null): array {
    // Attempt #1 — proper trash, with shared-drive support
    $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileId)
         . '?fields=id,trashed&supportsAllDrives=true';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['trashed' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) return ['ok' => true, 'code' => $code, 'error' => '', 'mode' => 'trashed'];
    $j = json_decode($r, true);
    $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
    // Attempt #2 — folder isn't owned by the connector account.
    // Detach it from the brand's folder so the dashboard's brand view
    // stops listing it, leaving the actual content where its owner can
    // still find it (we never destroy other people's data).
    if ($code === 403 && $brandFolderId) {
        $url2 = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileId)
              . '?removeParents=' . urlencode($brandFolderId)
              . '&fields=id,parents&supportsAllDrives=true';
        $ch2 = curl_init($url2);
        curl_setopt_array($ch2, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(new stdClass()),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        ]);
        $r2 = curl_exec($ch2); $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE); curl_close($ch2);
        if ($code2 >= 200 && $code2 < 300) {
            return ['ok' => true, 'code' => $code2, 'error' => '', 'mode' => 'unlinked'];
        }
        // Even unlink failed → surface the original 403 message verbatim.
    }
    return ['ok' => false, 'code' => $code, 'error' => $msg, 'mode' => 'failed'];
}
// Read parents[] for a file. Used to verify that an imported product
// folder really lives inside the active brand's Drive folder — defends
// against accidentally linking a folder owned by another brand.
function gd_parents_p(string $accessToken, string $fileId): array {
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId) . '?fields=id,parents,name');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return [];
    $j = json_decode($r, true);
    return $j['parents'] ?? [];
}
// Find a named subfolder under a parent. Returns its id or null.
function gd_find_subfolder_p(string $accessToken, string $parentId, string $name): ?string {
    foreach (gd_list_p($accessToken, $parentId) as $f) {
        if ($f['mimeType'] === 'application/vnd.google-apps.folder' && strcasecmp((string)$f['name'], $name) === 0) {
            return $f['id'];
        }
    }
    return null;
}
// Rename a Drive file/folder. Used to keep the Drive folder name in sync
// when the user renames a product/brand from the dashboard.
function gd_rename_p(string $accessToken, string $fileId, string $newName): bool {
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId) . '?fields=id,name');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['name' => $newName], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}
function gd_copy_p(string $accessToken, string $srcId, string $destParent, string $newName): ?string {
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($srcId) . '/copy?fields=id');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['parents' => [$destParent], 'name' => $newName], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code === 200 ? (json_decode($r, true)['id'] ?? null) : null;
}
// Recursive clone: src folder → freshly created folder under destParent named $newName.
// Template placeholders inside file/folder names are replaced with the product
// name on every copy. Matches ALL of these:
//   "Landing Page Content ()"     → "Landing Page Content (Rehla Pool)"
//   "Reviews & Details (X)"       → "Reviews & Details (Rehla Pool)"
//   "Content Plan ( )"            → "Content Plan (Rehla Pool)"
//   "S5_Benchmarking & Forecasting - (X) .xlsx" → "S5_Benchmarking & Forecasting - (Rehla Pool) .xlsx"
function gd_clone_folder(string $accessToken, string $srcId, string $destParent, string $newName, int $depth = 0): ?string {
    if ($depth > 8) return null;
    $rootNew = gd_mkdir_p($accessToken, $destParent, $newName);
    if (!$rootNew) return null;
    // Word-boundary regex: (), ( ), (X), (x), ( X ) — all become (newName)
    $rename = function (string $orig) use ($newName): string {
        return preg_replace('/\(\s*[xX]?\s*\)/u', '(' . $newName . ')', $orig);
    };
    $walk = function ($srcFolderId, $destFolderId, $d) use (&$walk, $accessToken, $rename) {
        if ($d > 8) return;
        foreach (gd_list_p($accessToken, $srcFolderId) as $entry) {
            if ($entry['mimeType'] === 'application/vnd.google-apps.folder') {
                $sub = gd_mkdir_p($accessToken, $destFolderId, $rename($entry['name']));
                if ($sub) $walk($entry['id'], $sub, $d + 1);
            } else {
                gd_copy_p($accessToken, $entry['id'], $destFolderId, $rename($entry['name']));
            }
        }
    };
    $walk($srcId, $rootNew, $depth + 1);
    return $rootNew;
}

// Build the products.meta JSON column from a request body merged on top of
// any existing meta. Today only `campaign_name` is meaningful (the English
// name used in Meta ad campaigns — bridges Arabic product names in
// shipping/sheet to English ones in ads). Returns null when the result is
// empty so the column stays NULL instead of "{}". Centralized so POST and
// PATCH stay consistent.
function build_product_meta_json(array $body, ?array $existing): ?string {
    $meta = is_array($existing) ? $existing : [];
    if (array_key_exists('campaign_name', $body)) {
        $cn = trim((string)$body['campaign_name']);
        if ($cn !== '') $meta['campaign_name'] = $cn;
        else            unset($meta['campaign_name']); // explicit clear
    }
    if (array_key_exists('meta', $body) && is_array($body['meta'])) {
        $meta = array_merge($meta, $body['meta']);
    }
    if (!count($meta)) return null;
    return json_encode($meta, JSON_UNESCAPED_UNICODE);
}

require_token();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare("SELECT p.*, b.name AS brand_name, b.client_id, c.name AS client_name
                             FROM products p
                             LEFT JOIN brands b ON b.id=p.brand_id
                             LEFT JOIN clients c ON c.id=b.client_id
                             WHERE p.id=?");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch();
        if (!$row) send_json(['error' => 'Not found'], 404);
        if ($row['meta']) $row['meta'] = json_decode($row['meta'], true);
        send_json($row);
    }
    $where = []; $args = [];
    if (!empty($_GET['brand_id'])) { $where[] = 'p.brand_id = ?'; $args[] = (int)$_GET['brand_id']; }
    $sql = "SELECT p.*, b.name AS brand_name, c.name AS client_name
            FROM products p
            LEFT JOIN brands b ON b.id=p.brand_id
            LEFT JOIN clients c ON c.id=b.client_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY p.updated_at DESC';
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) if ($r['meta']) $r['meta'] = json_decode($r['meta'], true);
    send_json(['rows' => $rows, 'count' => count($rows)]);
}

// Get-or-create the workspace's default client+brand. Used when the
// front-end calls POST /products.php without specifying a brand_id —
// keeps the user from having to manage clients/brands explicitly.
function ensure_default_brand(PDO $pdo, array $conn, string $accessToken): int {
    $st = $pdo->prepare("SELECT id, drive_folder_id FROM brands ORDER BY id LIMIT 1");
    $st->execute(); $row = $st->fetch();
    if ($row) return (int)$row['id'];
    // Need a client first.
    $st = $pdo->prepare("SELECT id, drive_folder_id FROM clients ORDER BY id LIMIT 1");
    $st->execute(); $client = $st->fetch();
    if (!$client) {
        // Use the connector's allowed root as the parent for the default workspace.
        $rootId = $conn['meta_decoded']['allowed_root_folder_id']
                ?? $conn['meta_decoded']['root_folder_id']
                ?? null;
        if (!$rootId) send_json(['error' => 'No allowed root folder set on Drive connector — open Settings → Drive and set one.'], 400);
        $clientFolder = gd_mkdir_p($accessToken, $rootId, 'Workspace');
        if (!$clientFolder) send_json(['error' => 'Failed to create default workspace folder'], 500);
        $st = $pdo->prepare("INSERT INTO clients (name, drive_folder_id) VALUES (?, ?)");
        $st->execute(['Workspace', $clientFolder]);
        $client = ['id' => (int)$pdo->lastInsertId(), 'drive_folder_id' => $clientFolder];
    }
    $brandFolder = gd_mkdir_p($accessToken, $client['drive_folder_id'], 'Default');
    if (!$brandFolder) send_json(['error' => 'Failed to create default brand folder'], 500);
    gd_mkdir_p($accessToken, $brandFolder, 'Products');
    $st = $pdo->prepare("INSERT INTO brands (client_id, name, drive_folder_id, sheets) VALUES (?, ?, ?, '{}')");
    $st->execute([$client['id'], 'Default', $brandFolder]);
    return (int)$pdo->lastInsertId();
}

// ── POST ────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $brandId = (int)($body['brand_id'] ?? 0);
    $name    = trim($body['name'] ?? '');
    // Optional: link an existing Drive folder instead of cloning the
    // product template. Used by the "Import products" feature where the
    // user already has product folders inside Drive.
    $existingFolderId = trim($body['drive_folder_id'] ?? '');
    if (!$name) send_json(['error' => 'name required'], 400);

    $conn = gd_connector_p($pdo);
    if (!$conn) send_json(['error' => 'No Google Drive connector'], 400);
    $accessToken = gd_token_p($pdo, $conn);

    // No brand specified → fall back to the workspace's default brand
    // (auto-created on first POST so the user never sees clients/brands UI).
    if (!$brandId) $brandId = ensure_default_brand($pdo, $conn, $accessToken);

    $st = $pdo->prepare("SELECT drive_folder_id FROM brands WHERE id=?");
    $st->execute([$brandId]);
    $brand = $st->fetch();
    if (!$brand) send_json(['error' => 'Brand not found'], 404);

    // Fast path: caller supplied an existing folder id → skip cloning,
    // but VERIFY the folder really lives under the brand's Drive folder
    // (or its PRODUCTS subfolder). Prevents accidentally linking another
    // brand's product when the topbar selection is misread by the UI.
    if ($existingFolderId !== '') {
        if (empty($brand['drive_folder_id'])) {
            send_json(['error' => 'Brand has no Drive folder; cannot validate import.'], 400);
        }
        $parents = gd_parents_p($accessToken, $existingFolderId);
        $allowed = [$brand['drive_folder_id']];
        $productsSub = gd_find_subfolder_p($accessToken, $brand['drive_folder_id'], 'PRODUCTS');
        if ($productsSub) $allowed[] = $productsSub;
        $okParent = false;
        foreach ($parents as $p) {
            if (in_array($p, $allowed, true)) { $okParent = true; break; }
        }
        if (!$okParent) {
            send_json([
                'error' => 'Folder is not inside this brand\'s Drive folder. Refusing to link to avoid cross-brand pollution.',
                'folder_id' => $existingFolderId,
                'folder_parents' => $parents,
                'brand_folder' => $brand['drive_folder_id'],
            ], 400);
        }
        $st = $pdo->prepare("INSERT INTO products (brand_id, name, drive_folder_id, status, meta) VALUES (?, ?, ?, 'draft', ?)");
        $st->execute([$brandId, $name, $existingFolderId, build_product_meta_json($body, null)]);
        send_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'drive_folder_id' => $existingFolderId, 'linked' => true]);
    }

    // Resolve where the new Product folder should live, in order:
    //   1. Explicit `products_folder_id` on the Drive connector (global override)
    //   2. Existing "Products" subfolder under the brand (legacy/optional)
    //   3. The brand folder itself (flat structure — most users want this)
    $productsParent = $conn['meta_decoded']['products_folder_id'] ?? null;
    if (!$productsParent) {
        foreach (gd_list_p($accessToken, $brand['drive_folder_id']) as $f) {
            if ($f['mimeType'] === 'application/vnd.google-apps.folder' && $f['name'] === 'Products') {
                $productsParent = $f['id']; break;
            }
        }
    }
    if (!$productsParent) {
        // Default: drop the new product folder directly inside the brand folder.
        // No more "Products" subfolder — keeps Rehla/{ProductName}/ clean.
        $productsParent = $brand['drive_folder_id'];
    }
    if (!$productsParent) send_json(['error' => 'Brand folder is not accessible. Re-pick a Drive folder for this brand.'], 500);

    // Copy from PRODUCT TEMPLATE (recursive)
    $tplId = $conn['meta_decoded']['product_template_folder_id'] ?? null;
    $folderId = null;
    if ($tplId) {
        $folderId = gd_clone_folder($accessToken, $tplId, $productsParent, $name);
    } else {
        $folderId = gd_mkdir_p($accessToken, $productsParent, $name);
    }
    if (!$folderId) {
        // Try a plain mkdir at the brand root and report the actual Drive error.
        $diag = gd_mkdir_p_diag($accessToken, $productsParent, $name);
        send_json(['error' => 'Drive mkdir failed: ' . ($diag['error'] ?: 'unknown'), 'parent' => $productsParent, 'template' => $tplId], 500);
    }

    $st = $pdo->prepare("INSERT INTO products (brand_id, name, drive_folder_id, status, meta) VALUES (?, ?, ?, 'draft', ?)");
    $st->execute([$brandId, $name, $folderId, build_product_meta_json($body, null)]);
    send_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'drive_folder_id' => $folderId]);
}

// ── PATCH ───────────────────────────────────────────────────────────
// Renames also propagate to Drive: when the user changes the product
// name, the Drive folder is renamed to match. Same explicit-action
// philosophy as DELETE — only happens because the user typed a new name.
if ($method === 'PATCH' || ($method === 'POST' && ($_GET['_method'] ?? '') === 'PATCH')) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) send_json(['error' => 'id required'], 400);
    $sets = []; $args = [];
    foreach (['name','status','build_key'] as $col) {
        if (isset($body[$col])) { $sets[] = "$col=?"; $args[] = $body[$col]; }
    }
    // Meta merge — handles `campaign_name` and any other future keys passed
    // via either body.campaign_name or body.meta. Only touches the column
    // when one of those keys is present in the body.
    if (array_key_exists('campaign_name', $body) || array_key_exists('meta', $body)) {
        $cur = $pdo->prepare("SELECT meta FROM products WHERE id=?");
        $cur->execute([$id]);
        $existing = $cur->fetchColumn();
        $existingArr = $existing ? json_decode((string)$existing, true) : null;
        $sets[] = 'meta=?';
        $args[] = build_product_meta_json($body, is_array($existingArr) ? $existingArr : null);
    }
    if (!$sets) send_json(['error' => 'nothing to update'], 400);
    // If the name changed, sync the Drive folder name first so the DB
    // and Drive stay consistent.
    $driveStatus = 'skipped';
    if (isset($body['name'])) {
        $newName = trim($body['name']);
        $st = $pdo->prepare("SELECT name, drive_folder_id FROM products WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && $row['drive_folder_id'] && $newName !== '' && $newName !== $row['name']) {
            $conn = gd_connector_p($pdo);
            if ($conn) {
                $tok = gd_token_p($pdo, $conn);
                $driveStatus = gd_rename_p($tok, $row['drive_folder_id'], $newName) ? 'renamed' : 'failed';
            } else {
                $driveStatus = 'no-connector';
            }
        }
    }
    $args[] = $id;
    $pdo->prepare("UPDATE products SET " . implode(',', $sets) . " WHERE id=?")->execute($args);
    send_json(['ok' => true, 'drive' => $driveStatus]);
}

// ── DELETE ──────────────────────────────────────────────────────────
// Pass ?drive=1 to ALSO move the product's Drive folder to Trash. We
// require the flag explicitly so no auto-cascade can ever silently
// touch Drive — the user must opt in from the UI confirmation dialog.
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) send_json(['error' => 'id required'], 400);
    $alsoDrive = !empty($_GET['drive']) && $_GET['drive'] !== '0';
    $folderId = null;
    $brandFolderId = null;
    if ($alsoDrive) {
        // Fetch product + its brand's drive_folder_id so the trash helper
        // can fall back to "unlink from brand folder" if the connector
        // account doesn't own the file.
        $st = $pdo->prepare("SELECT p.drive_folder_id AS pfid, b.drive_folder_id AS bfid
                             FROM products p LEFT JOIN brands b ON b.id = p.brand_id
                             WHERE p.id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $folderId      = $row['pfid'] ?: null;
            $brandFolderId = $row['bfid'] ?: null;
        }
    }
    $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
    $driveStatus = 'skipped';
    $driveError = '';
    if ($alsoDrive && $folderId) {
        $conn = gd_connector_p($pdo);
        if ($conn) {
            $tok = gd_token_p($pdo, $conn);
            $res = gd_trash_p($tok, $folderId, $brandFolderId);
            if ($res['ok']) {
                // mode is either 'trashed' (full delete) or 'unlinked'
                // (folder owned by someone else; we removed it from the
                // brand parent so it disappears from the dashboard view
                // without destroying the original).
                $driveStatus = ($res['mode'] ?? 'trashed') === 'unlinked' ? 'unlinked' : 'trashed';
            } else {
                $driveStatus = 'failed'; $driveError = $res['error'];
            }
        } else {
            $driveStatus = 'no-connector';
            $driveError = 'No active Drive connector.';
        }
    } elseif ($alsoDrive && !$folderId) {
        $driveStatus = 'no-folder';
    }
    send_json(['ok' => true, 'drive' => $driveStatus, 'drive_error' => $driveError]);
}

send_json(['error' => 'Method not allowed'], 405);
