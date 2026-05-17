<?php
// /api/brands.php — Brands inside a client. Each Brand has its own Drive
// folder + a `Sheets` subfolder containing copies of the templates:
//   - Financial Modeling (Monetization)
//   - S5 Benchmarking & Forecasting
//   - Executed Solutions & Outcomes
//   - Copywriting easyT
//   - Content Plan Strategy
//   - Content Plan Hit & Run
//
//   GET    ?id=N            → single brand (with sheets + product count)
//   GET    ?client_id=N     → all brands for a client
//   GET                      → all brands across all clients (for switcher)
//   POST   { client_id, name, sheets? }  → create folder + copy templates
//   PATCH  { id, name?, sheets? }
//   DELETE ?id=N
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/google-config.php';
require_once __DIR__ . '/_gd_share.php';
// gd_* helpers are inlined here so this file is self-contained.
function gd_connector_b(PDO $pdo): ?array {
    $st = $pdo->prepare("SELECT id, name, token, meta FROM connectors
                         WHERE type='storage' AND provider='google_drive' AND active=1
                         ORDER BY id LIMIT 1");
    $st->execute(); $r = $st->fetch();
    if (!$r) return null;
    $r['meta_decoded'] = $r['meta'] ? json_decode($r['meta'], true) : [];
    return $r;
}
function gd_token_b(PDO $pdo, array $conn): string {
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
// Soft-delete (Trash) a Drive file. Only ever called from the explicit
// DELETE endpoint with ?drive=1 — never from cascade paths.
// Rename a Drive file/folder. Used to keep the Drive folder name in sync
// when the user renames a brand from the dashboard.
function gd_rename_b(string $accessToken, string $fileId, string $newName): bool {
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
function gd_trash_b(string $accessToken, string $fileId): array {
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId) . '?fields=id,trashed');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['trashed' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) return ['ok' => true, 'code' => $code, 'error' => ''];
    $j = json_decode($r, true);
    $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
    return ['ok' => false, 'code' => $code, 'error' => $msg];
}
function gd_list_b(string $accessToken, string $folderId): array {
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
function gd_mkdir_b(string $accessToken, string $parentId, string $name): ?string {
    $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code === 200 ? (json_decode($r, true)['id'] ?? null) : null;
}
function gd_copy_b(string $accessToken, string $srcId, string $destParent, string $newName): ?string {
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

require_token();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare("SELECT b.*, c.name AS client_name FROM brands b LEFT JOIN clients c ON c.id=b.client_id WHERE b.id=?");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch();
        if (!$row) send_json(['error' => 'Not found'], 404);
        if ($row['sheets']) $row['sheets'] = json_decode($row['sheets'], true);
        if ($row['meta'])   $row['meta']   = json_decode($row['meta'], true);
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM products WHERE brand_id=?");
        $st2->execute([(int)$row['id']]);
        $row['product_count'] = (int)$st2->fetchColumn();
        send_json($row);
    }
    $where = []; $args = [];
    if (!empty($_GET['client_id'])) { $where[] = 'b.client_id = ?'; $args[] = (int)$_GET['client_id']; }
    $sql = "SELECT b.*, c.name AS client_name,
              (SELECT COUNT(*) FROM products p WHERE p.brand_id=b.id) AS product_count
            FROM brands b LEFT JOIN clients c ON c.id=b.client_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY b.name';
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        if ($r['sheets']) $r['sheets'] = json_decode($r['sheets'], true);
        if ($r['meta'])   $r['meta']   = json_decode($r['meta'], true);
    }
    send_json(['rows' => $rows, 'count' => count($rows)]);
}

// ── POST ────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $clientId       = (int)($body['client_id'] ?? 0);
    $name           = trim($body['name'] ?? '');
    $brandFolderPicked = trim($body['drive_folder_id'] ?? '');
    $assets         = isset($body['assets']) && is_array($body['assets']) ? $body['assets'] : null;
    $adAccountIds   = isset($body['ad_account_ids']) && is_array($body['ad_account_ids']) ? array_values(array_filter(array_map('trim', $body['ad_account_ids']))) : [];
    $skuPrefixes    = isset($body['sku_prefixes']) && is_array($body['sku_prefixes']) ? array_values(array_filter(array_map('strtolower', array_map('trim', $body['sku_prefixes'])))) : [];
    if (!$name) send_json(['error' => 'name required'], 400);
    if (!$brandFolderPicked) send_json(['error' => 'drive_folder_id required — pick a Drive folder for the brand'], 400);

    $conn = gd_connector_b($pdo);
    if (!$conn) send_json(['error' => 'No Google Drive connector'], 400);
    $accessToken = gd_token_b($pdo, $conn);

    // Auto-create a placeholder client row (clients table is now an internal
    // detail — the user only sees brands). Re-use the first existing one if
    // any to avoid creating Workspace folders we no longer use.
    if (!$clientId) {
        $st = $pdo->prepare("SELECT id FROM clients ORDER BY id LIMIT 1");
        $st->execute(); $existing = $st->fetch();
        if ($existing) {
            $clientId = (int)$existing['id'];
        } else {
            // Lightweight client row — no Drive folder needed since the brand
            // already has its own picked folder.
            $st = $pdo->prepare("INSERT INTO clients (name, drive_folder_id) VALUES (?, ?)");
            $st->execute(['Workspace', $brandFolderPicked]);
            $clientId = (int)$pdo->lastInsertId();
        }
    }

    // 1. Brand folder = the one the user already picked from Drive in the wizard.
    $brandFolder = $brandFolderPicked;

    // 2. Discover existing sheets in the brand folder (and any "Sheets"
    // subfolder beneath it). The user already maintains the 7 brand sheets
    // manually in Drive — we just match them by name and store the IDs.
    $sheetsFolder = null;
    foreach (gd_list_b($accessToken, $brandFolder) as $f) {
        if (($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder' && strcasecmp($f['name'] ?? '', 'Sheets') === 0) {
            $sheetsFolder = $f['id']; break;
        }
    }
    // Combine top-level files and the Sheets/ folder so we don't miss any.
    $candidates = gd_list_b($accessToken, $brandFolder);
    if ($sheetsFolder) {
        foreach (gd_list_b($accessToken, $sheetsFolder) as $f) $candidates[] = $f;
    }

    // Substring-match each file name against the 7 brand sheet keywords.
    $matchers = [
        'financial_modeling'    => ['financial'],
        'benchmarking'          => ['benchmark'],
        'competitive_analysis'  => ['competitive', 'hit & run', 'hit and run'],
        'outcomes'              => ['outcomes', 'executed'],
        'media_plan'            => ['media plan'],
        'content_plan_strategy' => ['content plan'],
        'copywriting'           => ['copywriting', 'easyt'],
    ];
    $googleNative = [
        'application/vnd.google-apps.spreadsheet',
        'application/vnd.google-apps.document',
        'application/vnd.google-apps.presentation',
    ];
    $sheets = [];
    $customs = [];
    foreach ($candidates as $f) {
        $mime = $f['mimeType'] ?? '';
        if (!in_array($mime, $googleNative, true)) continue;
        $lname = mb_strtolower($f['name'] ?? '');
        $matched = false;
        foreach ($matchers as $key => $keywords) {
            if (isset($sheets[$key])) continue; // already linked
            foreach ($keywords as $kw) {
                if ($lname !== '' && mb_strpos($lname, $kw) !== false) {
                    $sheets[$key] = $f['id'];
                    $matched = true; break;
                }
            }
            if ($matched) break;
        }
        if (!$matched) {
            $customs[] = ['id' => $f['id'], 'name' => $f['name'] ?? '', 'mimeType' => $mime];
        }
    }

    // 4. Create the "Products" subfolder (used by api/products.php later)
    gd_mkdir_b($accessToken, $brandFolder, 'Products');

    // 5. Insert DB row — bake assets + sku_prefixes into meta from the start
    // so the brand is fully wired in one POST (no separate PATCH needed).
    $meta = [];
    if ($assets) {
        $meta['assets'] = [
            'ads'      => array_values(array_map('intval', $assets['ads']      ?? [])),
            'shipping' => array_values(array_map('intval', $assets['shipping'] ?? [])),
            'cms'      => array_values(array_map('intval', $assets['cms']      ?? [])),
        ];
    }
    if ($adAccountIds) $meta['ad_account_ids'] = $adAccountIds;
    if ($skuPrefixes)  $meta['sku_prefixes']   = $skuPrefixes;
    $st = $pdo->prepare("INSERT INTO brands (client_id, name, drive_folder_id, sheets, meta)
                         VALUES (?,?,?,?,?)");
    $st->execute([
        $clientId, $name, $brandFolder,
        json_encode($sheets, JSON_UNESCAPED_UNICODE),
        $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
    ]);
    $newBrandId = (int)$pdo->lastInsertId();

    // Share the new brand folder with every active app_user so they can
    // open its sheets without hitting "You need access" in the embedded
    // viewer. Failures are swallowed — the manual sync action can backfill.
    $drive = ['skipped' => true];
    try { $drive = gd_share_folder_with_all_users($pdo, $brandFolder, 'writer'); }
    catch (Throwable $e) { $drive = ['ok' => false, 'error' => $e->getMessage()]; }

    send_json([
        'ok' => true,
        'id' => $newBrandId,
        'drive_folder_id' => $brandFolder,
        'sheets_folder_id' => $sheetsFolder,
        'sheets' => $sheets,
        'meta' => $meta,
        'drive_share' => $drive,
    ]);
}

// ── PATCH ───────────────────────────────────────────────────────────
// Renames also propagate to Drive: when the user changes the brand
// name, the Drive folder is renamed to match. Only fires when the new
// name actually differs from the current one.
if ($method === 'PATCH' || ($method === 'POST' && ($_GET['_method'] ?? '') === 'PATCH')) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) send_json(['error' => 'id required'], 400);
    $sets = []; $args = [];
    if (isset($body['name']))             { $sets[] = 'name=?';            $args[] = trim($body['name']); }
    if (isset($body['sheets']))           { $sets[] = 'sheets=?';          $args[] = json_encode($body['sheets'], JSON_UNESCAPED_UNICODE); }
    // Meta MERGES by default — caller passes only the keys they want to
    // change and existing keys are preserved. Pass `meta_replace: true`
    // alongside `meta` to fully replace (rare; e.g. resetting a brand).
    // Without merge, every PATCH would silently wipe assets / logo /
    // sku_prefixes / linked_sheets / fm_sheet_map etc.
    if (isset($body['meta']) && is_array($body['meta'])) {
        $cur = $pdo->prepare("SELECT meta FROM brands WHERE id=?");
        $cur->execute([$id]);
        $existing = $cur->fetchColumn();
        $existingArr = $existing ? (json_decode((string)$existing, true) ?: []) : [];
        $merged = !empty($body['meta_replace']) ? $body['meta'] : array_merge($existingArr, $body['meta']);
        $sets[] = 'meta=?'; $args[] = json_encode($merged, JSON_UNESCAPED_UNICODE);
    }
    if (isset($body['drive_folder_id'])) { $sets[] = 'drive_folder_id=?'; $args[] = trim($body['drive_folder_id']); }
    if (isset($body['client_id']))        { $sets[] = 'client_id=?';       $args[] = (int)$body['client_id']; }
    if (!$sets) send_json(['error' => 'nothing to update'], 400);
    // Sync rename to Drive when name changed.
    $driveStatus = 'skipped';
    if (isset($body['name'])) {
        $newName = trim($body['name']);
        $st = $pdo->prepare("SELECT name, drive_folder_id FROM brands WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && $row['drive_folder_id'] && $newName !== '' && $newName !== $row['name']) {
            $conn = gd_connector_b($pdo);
            if ($conn) {
                $tok = gd_token_b($pdo, $conn);
                $driveStatus = gd_rename_b($tok, $row['drive_folder_id'], $newName) ? 'renamed' : 'failed';
            } else {
                $driveStatus = 'no-connector';
            }
        }
    }
    $args[] = $id;
    $pdo->prepare("UPDATE brands SET " . implode(',', $sets) . " WHERE id=?")->execute($args);
    send_json(['ok' => true, 'drive' => $driveStatus]);
}

// ── DELETE ──────────────────────────────────────────────────────────
// ?drive=1  → also Trash the brand's Drive folder (does NOT touch the
// client's parent folder; the folder is only a tag/marker).
// We never auto-trash — the flag must be explicit so cascading from a
// stale UI cannot accidentally wipe Drive content.
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) send_json(['error' => 'id required'], 400);
    $alsoDrive = !empty($_GET['drive']) && $_GET['drive'] !== '0';
    $folderId = null;
    if ($alsoDrive) {
        $st = $pdo->prepare("SELECT drive_folder_id FROM brands WHERE id=?");
        $st->execute([$id]);
        $folderId = $st->fetchColumn() ?: null;
    }
    $pdo->prepare("DELETE FROM products WHERE brand_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM brands WHERE id=?")->execute([$id]);
    $driveStatus = 'skipped';
    if ($alsoDrive && $folderId) {
        $conn = gd_connector_b($pdo);
        if ($conn) {
            $tok = gd_token_b($pdo, $conn);
            $res = gd_trash_b($tok, $folderId);
            if ($res['ok']) { $driveStatus = 'trashed'; $driveError = ''; }
            else            { $driveStatus = 'failed';  $driveError = $res['error']; }
        } else {
            $driveStatus = 'no-connector';
            $driveError = 'No active Drive connector.';
        }
    }
    if (!isset($driveError)) $driveError = '';
    send_json(['ok' => true, 'drive' => $driveStatus, 'drive_error' => $driveError]);
}

send_json(['error' => 'Method not allowed'], 405);
