<?php
// /api/clients.php — REST CRUD for the Clients hub.
// Each Client = one folder under the connector's allowed Drive root.
//   GET    ?id=N            → single client
//   GET                      → list all clients (with brand/product counts)
//   POST   { name }          → create folder in Drive + insert row
//   PATCH  { id, name }      → rename (DB only — Drive folder rename is manual)
//   DELETE ?id=N             → soft delete (removes DB rows; folder stays in Drive)
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/google-config.php';
require_token();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

// Helper: fetch first active Google Drive connector
function gd_connector(PDO $pdo): ?array {
    $st = $pdo->prepare("SELECT id, name, token, meta FROM connectors
                         WHERE type='storage' AND provider='google_drive' AND active=1
                         ORDER BY id LIMIT 1");
    $st->execute();
    $r = $st->fetch();
    if (!$r) return null;
    $r['meta_decoded'] = $r['meta'] ? json_decode($r['meta'], true) : [];
    return $r;
}

// Helper: refresh token + return access_token (mirror of google-drive.php logic)
function gd_token(PDO $pdo, array $conn): string {
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
    if ($code !== 200 || empty($tok['access_token'])) send_json(['error' => 'Drive token refresh failed'], 401);
    $meta['token_expires_at'] = date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600));
    $upd = $pdo->prepare("UPDATE connectors SET token=?, meta=? WHERE id=?");
    $upd->execute([$tok['access_token'], json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$conn['id']]);
    return $tok['access_token'];
}

function gd_create_folder(string $accessToken, string $parentId, string $name): ?string {
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
    if ($code !== 200) return null;
    return json_decode($r, true)['id'] ?? null;
}

// ── GET ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare("SELECT * FROM clients WHERE id=?");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch();
        if (!$row) send_json(['error' => 'Not found'], 404);
        if ($row['meta']) $row['meta'] = json_decode($row['meta'], true);
        send_json($row);
    }
    // List with counts
    $sql = "SELECT c.*,
              (SELECT COUNT(*) FROM brands b WHERE b.client_id = c.id) AS brand_count,
              (SELECT COUNT(*) FROM products p JOIN brands b2 ON b2.id = p.brand_id WHERE b2.client_id = c.id) AS product_count
            FROM clients c ORDER BY c.name";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) if ($r['meta']) $r['meta'] = json_decode($r['meta'], true);
    send_json(['rows' => $rows, 'count' => count($rows)]);
}

// ── POST ────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($body['name'] ?? '');
    if (!$name) send_json(['error' => 'name required'], 400);

    // The caller MUST provide a Drive folder id picked from Drive — we
    // never auto-create a new folder. The Drive folder is the user's
    // tag/marker for grouping brands; it must already exist in Drive.
    $folderId = trim($body['drive_folder_id'] ?? '');
    if ($folderId === '') {
        send_json(['error' => 'drive_folder_id required — pick an existing Drive folder for the client'], 400);
    }

    $st = $pdo->prepare("INSERT INTO clients (name, drive_folder_id) VALUES (?, ?)");
    $st->execute([$name, $folderId]);
    send_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'drive_folder_id' => $folderId]);
}

// ── PATCH ───────────────────────────────────────────────────────────
// Accepts partial updates: name and/or drive_folder_id. Both optional;
// at least one must be provided.
if ($method === 'PATCH' || ($method === 'POST' && !empty($_GET['_method']) && $_GET['_method'] === 'PATCH')) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) send_json(['error' => 'id required'], 400);
    $sets = []; $args = [];
    if (array_key_exists('name', $body)) {
        $name = trim($body['name'] ?? '');
        if (!$name) send_json(['error' => 'name cannot be empty'], 400);
        $sets[] = 'name=?'; $args[] = $name;
    }
    if (array_key_exists('drive_folder_id', $body)) {
        $fid = trim($body['drive_folder_id'] ?? '');
        $sets[] = 'drive_folder_id=?'; $args[] = $fid !== '' ? $fid : null;
    }
    if (!$sets) send_json(['error' => 'nothing to update'], 400);
    $args[] = $id;
    $sql = "UPDATE clients SET " . implode(', ', $sets) . " WHERE id=?";
    $pdo->prepare($sql)->execute($args);
    send_json(['ok' => true]);
}

// ── DELETE ──────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) send_json(['error' => 'id required'], 400);
    // Cascade: delete products → brands → client (Drive folders untouched)
    $pdo->prepare("DELETE p FROM products p JOIN brands b ON b.id=p.brand_id WHERE b.client_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM brands WHERE client_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM clients WHERE id=?")->execute([$id]);
    send_json(['ok' => true]);
}

send_json(['error' => 'Method not allowed'], 405);
