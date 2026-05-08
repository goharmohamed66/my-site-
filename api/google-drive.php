<?php
// /api/google-drive.php — Server-side helpers for the Drive integration.
// Front-end calls these via the standard Bearer-token API auth.
//
//   GET  ?action=ping&connector_id=X
//        → Refreshes the access token if needed, returns { ok, email, expires_at }
//   GET  ?action=token&connector_id=X
//        → Returns a fresh access_token (refreshes via refresh_token if expired).
//        → Lets the browser launch Google Picker without exposing the secret.
//   GET  ?action=download&connector_id=X&file_id=…
//        → Streams a file's bytes (used for images / text the user picked).
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/google-config.php';
require_token();

$action = $_GET['action'] ?? '';
$pdo = db();

function fetch_google_connector(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT id, name, token, meta FROM connectors
                         WHERE id=? AND type='storage' AND provider='google_drive' AND active=1 LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['meta_decoded'] = $row['meta'] ? json_decode($row['meta'], true) : [];
    return $row;
}

// Refresh access token if it has expired (or will in <60s). Persists the new
// token back into the row.
function ensure_fresh_token(PDO $pdo, array $conn): array {
    $meta = $conn['meta_decoded'];
    $expires_at = isset($meta['token_expires_at']) ? strtotime($meta['token_expires_at']) : 0;
    if ($expires_at - 60 > time() && !empty($conn['token'])) {
        return ['access_token' => $conn['token'], 'meta' => $meta];
    }
    if (empty($meta['refresh_token'])) {
        send_json(['error' => 'Refresh token missing — please reconnect Google Drive in Settings.'], 401);
    }
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $meta['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tok = json_decode($resp, true);
    if ($code !== 200 || empty($tok['access_token'])) {
        send_json(['error' => 'Token refresh failed: ' . ($tok['error_description'] ?? $resp)], 401);
    }
    $new_access = $tok['access_token'];
    $expires_in = (int)($tok['expires_in'] ?? 3600);
    $meta['token_expires_at'] = date('Y-m-d H:i:s', time() + $expires_in);
    $upd = $pdo->prepare("UPDATE connectors SET token=?, meta=? WHERE id=?");
    $upd->execute([$new_access, json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$conn['id']]);
    return ['access_token' => $new_access, 'meta' => $meta];
}

// ── GET / SET allowed root folder per connector ──────────────────────
// The "root folder" is the only folder the front-end is allowed to browse
// inside (gives the user permission scoping without having to share Drive
// folders). Falls back to GOOGLE_DEFAULT_ROOT_FOLDER if not set.
if ($action === 'root') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $root = $conn['meta_decoded']['root_folder_id'] ?? GOOGLE_DEFAULT_ROOT_FOLDER;
    send_json(['root_folder_id' => $root]);
}
if ($action === 'set_root') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $folder = $_GET['folder_id'] ?? '';
    if (!$folder) send_json(['error' => 'folder_id required'], 400);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $meta = $conn['meta_decoded'];
    $meta['root_folder_id'] = $folder;
    $upd = $pdo->prepare("UPDATE connectors SET meta=? WHERE id=?");
    $upd->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
    send_json(['ok' => true, 'root_folder_id' => $folder]);
}

// ── CREATE FOLDER ────────────────────────────────────────────────────
//   POST ?action=create_folder
//   Body: { connector_id, parent_id, name }
//   Returns: { id, name }
if ($action === 'create_folder') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($body['connector_id'] ?? $_GET['connector_id'] ?? 0);
    $parent = trim($body['parent_id'] ?? '');
    $name = trim($body['name'] ?? '');
    if (!$parent || !$name) send_json(['error' => 'parent_id and name required'], 400);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);

    $payload = json_encode([
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parent],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id,name');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $fresh['access_token'],
            'Content-Type: application/json',
        ],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) send_json(['error' => 'Drive create_folder failed', 'detail' => $r], $code);
    send_json(json_decode($r, true));
}

// ── FILE INFO (single-shot metadata) ─────────────────────────────────
//   GET ?action=file_info&connector_id=X&file_id=…
//   Returns: { id, name, mimeType, iconLink? }
// Used by the Sheets page to enrich pasted Drive URLs with their real
// title + type so we can render proper icons / labels.
if ($action === 'file_info') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $fileId = trim($_GET['file_id'] ?? '');
    if (!$fileId) send_json(['error' => 'file_id required'], 400);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);
    $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileId)
         . '?fields=id,name,mimeType,iconLink';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $fresh['access_token']],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) send_json(['error' => 'file_info failed', 'detail' => $r], $code);
    send_json(json_decode($r, true));
}

// ── COPY FILE ────────────────────────────────────────────────────────
//   POST ?action=copy_file
//   Body: { connector_id, source_id, dest_parent_id, new_name }
//   Returns: { id, name }
if ($action === 'copy_file') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($body['connector_id'] ?? $_GET['connector_id'] ?? 0);
    $sourceId = trim($body['source_id'] ?? '');
    $destParent = trim($body['dest_parent_id'] ?? '');
    $newName = trim($body['new_name'] ?? '');
    if (!$sourceId || !$destParent) send_json(['error' => 'source_id and dest_parent_id required'], 400);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);

    $payload = ['parents' => [$destParent]];
    if ($newName !== '') $payload['name'] = $newName;
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($sourceId) . '/copy?fields=id,name,mimeType');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $fresh['access_token'],
            'Content-Type: application/json',
        ],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) send_json(['error' => 'Drive copy_file failed', 'detail' => $r], $code);
    send_json(json_decode($r, true));
}

// ── UPLOAD FILE ──────────────────────────────────────────────────────
//   POST ?action=upload_file (multipart/form-data)
//   Form fields: connector_id, parent_id, [name], file
//   Returns: { id, name, mimeType }
//
// Used by the New Product modal so the user can drop local files (or files
// downloaded from Drive via the picker) into the freshly-created product
// folder. Single file per request; the front-end loops for batches.
if ($action === 'upload_file') {
    $id = (int)($_POST['connector_id'] ?? $_GET['connector_id'] ?? 0);
    $parentId = trim($_POST['parent_id'] ?? '');
    if (!$parentId) send_json(['error' => 'parent_id required'], 400);
    if (empty($_FILES['file'])) send_json(['error' => 'file required'], 400);
    $f = $_FILES['file'];
    if (!empty($f['error'])) send_json(['error' => 'upload error code ' . $f['error']], 400);
    $name = trim($_POST['name'] ?? $f['name'] ?? 'untitled');
    $mime = $f['type'] ?: 'application/octet-stream';
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);

    // Use Drive's multipart upload: one POST containing JSON metadata + body.
    $boundary = '----driveuploadboundary' . bin2hex(random_bytes(8));
    $bytes = file_get_contents($f['tmp_name']);
    $meta = json_encode(['name' => $name, 'parents' => [$parentId]], JSON_UNESCAPED_UNICODE);
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $meta . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= $bytes . "\r\n";
    $body .= "--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $fresh['access_token'],
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) send_json(['error' => 'Drive upload_file failed', 'detail' => $r], $code);
    send_json(json_decode($r, true));
}

// ── COPY FOLDER (recursive) ──────────────────────────────────────────
//   POST ?action=copy_folder_recursive
//   Body: { connector_id, source_id, dest_parent_id, new_name }
//   Returns: { id, name, copied: { folders, files } }
//
// Walks the source tree, recreates the folder hierarchy under dest_parent_id,
// and copies every file (Drive's /copy endpoint works for binaries AND for
// Google-native types — Docs/Sheets/Slides — without losing fidelity).
if ($action === 'copy_folder_recursive') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($body['connector_id'] ?? $_GET['connector_id'] ?? 0);
    $sourceId = trim($body['source_id'] ?? '');
    $destParent = trim($body['dest_parent_id'] ?? '');
    $newName = trim($body['new_name'] ?? '');
    if (!$sourceId || !$destParent || !$newName) send_json(['error' => 'source_id, dest_parent_id, new_name required'], 400);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);
    $accessToken = $fresh['access_token'];

    $stats = ['folders' => 0, 'files' => 0];

    // Helper closures using shared $accessToken
    $createFolder = function ($parent, $name) use ($accessToken, &$stats) {
        $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$parent],
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return null;
        $stats['folders']++;
        return (json_decode($r, true)['id'] ?? null);
    };

    $copyFile = function ($srcId, $destParent, $name) use ($accessToken, &$stats) {
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($srcId) . '/copy?fields=id');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'parents' => [$destParent],
                'name' => $name,
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) $stats['files']++;
        return $code === 200;
    };

    $listChildren = function ($folderId) use ($accessToken) {
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
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return [];
        return (json_decode($r, true)['files'] ?? []);
    };

    // Recursive walker — clone src into freshly-created folder under destParent
    $walk = function ($srcFolderId, $destFolderId, $depth = 0) use (&$walk, $createFolder, $copyFile, $listChildren) {
        if ($depth > 8) return;
        foreach ($listChildren($srcFolderId) as $entry) {
            if ($entry['mimeType'] === 'application/vnd.google-apps.folder') {
                $sub = $createFolder($destFolderId, $entry['name']);
                if ($sub) $walk($entry['id'], $sub, $depth + 1);
            } else {
                $copyFile($entry['id'], $destFolderId, $entry['name']);
            }
        }
    };

    $rootNew = $createFolder($destParent, $newName);
    if (!$rootNew) send_json(['error' => 'Failed to create root copy folder'], 500);
    $walk($sourceId, $rootNew);
    send_json(['id' => $rootNew, 'name' => $newName, 'copied' => $stats]);
}

// ── READ SHEET (Google Sheet → JSON) ─────────────────────────────────
//   GET ?action=read_sheet&connector_id=X&file_id=…
//   Returns: { rows: [[...], [...]] } parsed from CSV export.
if ($action === 'read_sheet') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $fileId = $_GET['file_id'] ?? '';
    if (!$fileId) send_json(['error' => 'file_id required'], 400);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);

    // Try Google Sheet export to CSV first; if file is xlsx, fall back to direct download.
    $url = "https://www.googleapis.com/drive/v3/files/$fileId/export?mimeType=text/csv";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $fresh['access_token']],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $body === false) {
        send_json(['error' => 'Sheet read failed', 'detail' => $body], $code ?: 500);
    }
    // Parse CSV
    $rows = [];
    $lines = preg_split("/\r\n|\n|\r/", trim($body));
    foreach ($lines as $line) {
        if ($line === '') continue;
        $rows[] = str_getcsv($line);
    }
    send_json(['rows' => $rows]);
}

// ── SEARCH for folders by name (anywhere inside the allowed root) ────
//   GET ?action=search&connector_id=X&q=keyword
//   Returns folders whose name contains `q` AND whose ancestor chain
//   eventually reaches the connector's allowed root folder. So the user
//   can find products by name from anywhere — even if they're nested 4-5
//   levels deep — without having to drill into the folders manually.
if ($action === 'search') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $q  = trim($_GET['q'] ?? '');
    if ($q === '' || mb_strlen($q) < 2) send_json(['files' => []]);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);
    $accessToken = $fresh['access_token'];
    $allowedRoot = $conn['meta_decoded']['root_folder_id'] ?? GOOGLE_DEFAULT_ROOT_FOLDER;

    // Drive search query — folders only, name contains $q, not trashed
    $safeQ = str_replace("'", "\\'", $q);
    $url = 'https://www.googleapis.com/drive/v3/files?'
         . http_build_query([
             'q' => "name contains '$safeQ' and mimeType='application/vnd.google-apps.folder' and trashed=false",
             'fields' => 'files(id,name,parents)',
             'pageSize' => 50,
         ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) send_json(['error' => 'Drive search failed', 'detail' => $r], $code);
    $hits = (json_decode($r, true)['files'] ?? []);

    // Verify each hit lives inside the allowed root by walking parents.
    // Cached so we don't refetch the same folder twice in a single search.
    $parentCache = [];
    $insideRoot = function ($folderId, $depth = 0) use (&$insideRoot, &$parentCache, $allowedRoot, $accessToken) {
        if ($folderId === $allowedRoot) return true;
        if ($depth > 8) return false;
        if (!isset($parentCache[$folderId])) {
            $u = "https://www.googleapis.com/drive/v3/files/" . urlencode($folderId) . "?fields=parents";
            $c = curl_init($u);
            curl_setopt_array($c, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            ]);
            $resp = curl_exec($c);
            curl_close($c);
            $parentCache[$folderId] = (json_decode($resp, true)['parents'] ?? []);
        }
        foreach ($parentCache[$folderId] as $p) {
            if ($insideRoot($p, $depth + 1)) return true;
        }
        return false;
    };

    $matches = [];
    foreach ($hits as $h) {
        if ($insideRoot($h['id'])) {
            $matches[] = ['id' => $h['id'], 'name' => $h['name'], 'mimeType' => 'application/vnd.google-apps.folder'];
        }
    }
    send_json(['files' => $matches]);
}

// ── THUMBNAIL (proxy) ─────────────────────────────────────────────────
// GET ?action=thumb&file_id=X — returns the JPEG/PNG preview Drive
// generates for any file (Sheet/Doc/Slide/PDF/image/video). Going
// through our backend means we can use the OAuth token (so it works
// for files the browser session isn't logged into) and bypasses CORS.
if ($action === 'thumb') {
    $fileId = trim($_GET['file_id'] ?? '');
    if ($fileId === '') send_json(['error' => 'file_id required'], 400);
    $size = (int)($_GET['sz'] ?? 400);
    if ($size < 64) $size = 64;
    if ($size > 1600) $size = 1600;
    // Pick the first available Drive connector unless the caller specifies one.
    $cid = (int)($_GET['connector_id'] ?? 0);
    if ($cid) {
        $conn = fetch_google_connector($pdo, $cid);
    } else {
        $row = $pdo->query("SELECT * FROM connectors WHERE type='storage' AND provider='google_drive' LIMIT 1")->fetch();
        $conn = $row ?: null;
        if ($conn && !empty($conn['meta'])) $conn['meta'] = json_decode($conn['meta'], true);
    }
    if (!$conn) send_json(['error' => 'No Google Drive connector available'], 400);
    $fresh = ensure_fresh_token($pdo, $conn);
    $tok = $fresh['access_token'];
    // Step 1: ask Drive for the file's thumbnailLink (it's an
    // authenticated, time-limited URL pointing at Google's CDN).
    $metaCh = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId) . '?fields=thumbnailLink,mimeType');
    curl_setopt_array($metaCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok],
    ]);
    $metaResp = curl_exec($metaCh);
    $metaCode = curl_getinfo($metaCh, CURLINFO_HTTP_CODE);
    curl_close($metaCh);
    if ($metaCode !== 200) {
        http_response_code(404);
        header('Content-Type: text/plain'); echo 'no thumb'; exit;
    }
    $metaInfo = json_decode($metaResp, true) ?: [];
    $thumbUrl = $metaInfo['thumbnailLink'] ?? '';
    if (!$thumbUrl) {
        http_response_code(404);
        header('Content-Type: text/plain'); echo 'no thumb'; exit;
    }
    // Bump the size — Drive's thumbnailLink defaults to ~220px (?sz=s220).
    $thumbUrl = preg_replace('/[?&]sz=[^&]+/', '', $thumbUrl);
    $thumbUrl .= (strpos($thumbUrl, '?') === false ? '?' : '&') . 'sz=w' . $size;
    // Step 2: fetch the image bytes (often a googleusercontent.com URL
    // that doesn't need the OAuth header but accepts it harmlessly).
    $ch = curl_init($thumbUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
    curl_close($ch);
    if ($code !== 200 || $body === false) {
        http_response_code(502);
        header('Content-Type: text/plain'); echo 'thumb fetch failed (HTTP ' . $code . ')'; exit;
    }
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=3600');
    echo $body;
    exit;
}

// ── TRASH (soft-delete) ───────────────────────────────────────────────
// POST ?action=trash&file_id=X — moves a Drive file/folder to Trash.
// Used by the dashboard when the user explicitly confirms deletion.
// We never auto-call this; it's only invoked from explicit UI confirms.
if ($action === 'trash') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') send_json(['error' => 'POST required'], 405);
    $fileId = trim($_GET['file_id'] ?? '');
    if ($fileId === '') send_json(['error' => 'file_id required'], 400);
    // Use the first available Google Drive connector unless a specific
    // one is requested — keeps the front-end call site simple.
    $cid = (int)($_GET['connector_id'] ?? 0);
    if ($cid) {
        $conn = fetch_google_connector($pdo, $cid);
    } else {
        $row = $pdo->query("SELECT * FROM connectors WHERE type='storage' AND provider='google_drive' LIMIT 1")->fetch();
        $conn = $row ?: null;
        if ($conn && !empty($conn['meta'])) $conn['meta'] = json_decode($conn['meta'], true);
    }
    if (!$conn) send_json(['error' => 'No Google Drive connector available'], 400);
    $fresh = ensure_fresh_token($pdo, $conn);
    $tok = $fresh['access_token'];
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId) . '?fields=id,trashed');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['trashed' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $j = json_decode($r, true);
        $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
        send_json(['error' => 'Drive trash failed: ' . $msg, 'detail' => $r, 'code' => $code], 502);
    }
    send_json(['ok' => true, 'file_id' => $fileId, 'trashed' => true]);
}

// ── PING ──────────────────────────────────────────────────────────────
if ($action === 'ping') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);
    send_json([
        'ok'         => true,
        'email'      => $fresh['meta']['google_email'] ?? '',
        'name'       => $fresh['meta']['google_name'] ?? '',
        'expires_at' => $fresh['meta']['token_expires_at'] ?? '',
    ]);
}

// ── TOKEN (used by Picker on the front-end) ───────────────────────────
if ($action === 'token') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);
    send_json([
        'access_token' => $fresh['access_token'],
        'api_key'      => GOOGLE_API_KEY,
        'project_number' => GOOGLE_PROJECT_NUMBER,
        'client_id'    => GOOGLE_CLIENT_ID,
    ]);
}

// ── LIST FOLDER CONTENTS ─────────────────────────────────────────────
//   GET ?action=list&connector_id=X&folder_id=…
//   Returns: { files: [{id, name, mimeType, size, thumbnailLink}] }
if ($action === 'list') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $folder = $_GET['folder_id'] ?? '';
    if (!$folder) send_json(['error' => 'folder_id required'], 400);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);
    $url = 'https://www.googleapis.com/drive/v3/files?'
         . http_build_query([
             'q' => "'$folder' in parents and trashed=false",
             'fields' => 'files(id,name,mimeType,size,thumbnailLink,webContentLink,modifiedTime)',
             'pageSize' => 200,
         ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $fresh['access_token']],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) send_json(['error' => 'Drive list failed', 'detail' => $r], $code);
    send_json(json_decode($r, true) ?: ['files' => []]);
}

// ── DOWNLOAD FILE ────────────────────────────────────────────────────
//   GET ?action=download&connector_id=X&file_id=…
if ($action === 'download') {
    $id = (int)($_GET['connector_id'] ?? 0);
    $file_id = $_GET['file_id'] ?? '';
    if (!$file_id) send_json(['error' => 'file_id required'], 400);
    $conn = fetch_google_connector($pdo, $id);
    if (!$conn) send_json(['error' => 'Connector not found'], 404);
    $fresh = ensure_fresh_token($pdo, $conn);
    // First fetch metadata so we can echo correct headers
    $metaCh = curl_init("https://www.googleapis.com/drive/v3/files/$file_id?fields=name,mimeType");
    curl_setopt_array($metaCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $fresh['access_token']],
    ]);
    $metaResp = curl_exec($metaCh);
    curl_close($metaCh);
    $metaInfo = json_decode($metaResp, true) ?: [];
    $name = $metaInfo['name'] ?? 'file';
    $mime = $metaInfo['mimeType'] ?? 'application/octet-stream';
    // Google native types (Docs, Sheets, Slides) need /export instead of
    // ?alt=media. Convert them to text/csv so AI tools can ingest them.
    $exportMap = [
        'application/vnd.google-apps.document'     => ['mime' => 'text/plain',                                     'ext' => '.txt'],
        'application/vnd.google-apps.spreadsheet'  => ['mime' => 'text/csv',                                       'ext' => '.csv'],
        'application/vnd.google-apps.presentation' => ['mime' => 'text/plain',                                     'ext' => '.txt'],
    ];
    if (isset($exportMap[$mime])) {
        $target = $exportMap[$mime];
        $exportUrl = "https://www.googleapis.com/drive/v3/files/$file_id/export?mimeType=" . urlencode($target['mime']);
        $name = $name . $target['ext'];
        $mime = $target['mime'];
    } else {
        $exportUrl = "https://www.googleapis.com/drive/v3/files/$file_id?alt=media";
    }
    // Buffer-then-echo (curl streaming with RETURNTRANSFER=false was
    // dumping HTTP headers into the body and breaking blobs in the browser).
    $ch = curl_init($exportUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $fresh['access_token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $body === false) send_json(['error' => 'Drive download failed', 'code' => $code], 500);
    // Re-assert CORS headers explicitly — Hostinger CDN strips them off
    // image responses otherwise, breaking fetch() from any other origin.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Cache-Control: no-store');
    header('Content-Type: ' . $mime);
    // ?attachment=1 → force "Save As" download instead of inline preview.
    $disposition = !empty($_GET['attachment']) ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($name) . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

send_json(['error' => 'Unknown action: ' . $action], 400);
