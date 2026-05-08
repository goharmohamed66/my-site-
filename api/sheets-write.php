<?php
// /api/sheets-write.php — Write specific cells into a Google Sheet via
// Sheets API v4 values:batchUpdate. Used by the Financial Modeling
// updater (automation-fm-update.html) to overwrite Return % + tROAS
// cells without touching the cell format ("add text only").
//
//   POST { connector_id, file_id, updates: [ {a1, value}, ... ] }
//     a1   — single cell (e.g. "F12") — RANGES ARE REJECTED so a buggy
//            client can never wipe a region.
//     value — string value sent as-is to Sheets with USER_ENTERED so
//             "32.5%" parses into 0.325 with the existing percent format
//             preserved, "2.43" into the number 2.43, etc.
//
// Returns: { ok, updated_cells, errors:[] }
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/google-config.php';
// Re-implementing the connector + token-refresh helpers locally instead
// of including google-drive.php — that file is a request handler that
// would short-circuit with "Unknown action" before this endpoint runs.
function sw_fetch_connector(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT id, name, token, meta FROM connectors
                         WHERE id=? AND type='storage' AND provider='google_drive' AND active=1 LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['meta_decoded'] = $row['meta'] ? json_decode($row['meta'], true) : [];
    return $row;
}
function sw_ensure_fresh_token(PDO $pdo, array $conn): string {
    $meta = $conn['meta_decoded'];
    $exp = isset($meta['token_expires_at']) ? strtotime($meta['token_expires_at']) : 0;
    if ($exp - 60 > time() && !empty($conn['token'])) return $conn['token'];
    if (empty($meta['refresh_token'])) send_json(['error' => 'Refresh token missing — reconnect Google Drive in Settings.'], 401);
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
    if ($code !== 200 || empty($tok['access_token'])) send_json(['error' => 'Token refresh failed: ' . ($tok['error_description'] ?? $resp)], 401);
    $newAccess = $tok['access_token'];
    $expiresIn = (int)($tok['expires_in'] ?? 3600);
    $meta['token_expires_at'] = date('Y-m-d H:i:s', time() + $expiresIn);
    $upd = $pdo->prepare("UPDATE connectors SET token=?, meta=? WHERE id=?");
    $upd->execute([$newAccess, json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$conn['id']]);
    return $newAccess;
}

require_token();

// ── Companion read endpoint via Sheets API v4 ─────────────────────────
// CSV export (the old read_sheet) splits cells on every newline inside a
// cell, which corrupts row indices for any sheet using multi-line
// headers like "Return %\nFrom Total". The Sheets API preserves the
// real cell layout. Returns `{ rows: [[...]], sheets: [...tab metadata] }`.
//
//   GET ?action=read&connector_id=N&file_id=ID[&tab_name=Name]
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'read') {
    $cid = (int)($_GET['connector_id'] ?? 0);
    $fid = trim((string)($_GET['file_id'] ?? ''));
    $tab = isset($_GET['tab_name']) ? trim((string)$_GET['tab_name']) : '';
    if (!$cid || !$fid) send_json(['error' => 'connector_id and file_id required'], 400);
    $pdo  = db();
    $conn = sw_fetch_connector($pdo, $cid);
    if (!$conn) send_json(['error' => 'Drive connector not found'], 404);
    $tok  = sw_ensure_fresh_token($pdo, $conn);
    // 1. Discover tabs (so caller can know which one we read by default)
    $metaUrl = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($fid)
             . '?fields=' . urlencode('sheets(properties(title,sheetId,index))');
    $ch = curl_init($metaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300) send_json(['error' => 'Failed to load sheet metadata: HTTP ' . $code, 'detail' => $r], 500);
    $metaJson = json_decode($r, true) ?: [];
    $tabs = [];
    foreach ($metaJson['sheets'] ?? [] as $s) {
        $p = $s['properties'] ?? [];
        $tabs[] = ['title' => $p['title'] ?? '', 'sheetId' => $p['sheetId'] ?? 0, 'index' => $p['index'] ?? 0];
    }
    $useTab = $tab !== '' ? $tab : ($tabs[0]['title'] ?? '');
    if ($useTab === '') send_json(['error' => 'No tabs in sheet'], 500);
    // 2. Pull values from the chosen tab — A1:ZZ200 covers everything
    //    realistic. valueRenderOption=FORMATTED_VALUE so percentages / EGP
    //    text comes back as the user sees it (matches the matching logic).
    $rangeA1 = "'" . str_replace("'", "''", $useTab) . "'!A1:ZZ200";
    $valUrl = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($fid)
            . '/values/' . rawurlencode($rangeA1)
            . '?majorDimension=ROWS&valueRenderOption=FORMATTED_VALUE';
    $ch = curl_init($valUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $j = json_decode($r, true);
        $msg = $j['error']['message'] ?? ('HTTP ' . $code);
        send_json(['error' => 'Sheets read failed: ' . $msg, 'http_code' => $code], 500);
    }
    $valJson = json_decode($r, true) ?: [];
    send_json([
        'rows'    => $valJson['values'] ?? [],
        'tab'     => $useTab,
        'tabs'    => $tabs,
        'range'   => $valJson['range'] ?? $rangeA1,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$connectorId = (int)($body['connector_id'] ?? 0);
$fileId      = trim((string)($body['file_id'] ?? ''));
$updates     = $body['updates'] ?? [];

if (!$connectorId || !$fileId || !is_array($updates) || !count($updates)) {
    send_json(['error' => 'connector_id, file_id and non-empty updates[] required'], 400);
}

// Per-cell allowlist: every a1 must be a single cell (e.g. "F12", "AB200").
// No ranges, no sheet-name prefixes (caller picks the sheet via a separate
// `sheet_name` param if needed in the future). Reject anything weird.
$cleanUpdates = [];
foreach ($updates as $u) {
    $a1  = isset($u['a1']) ? trim((string)$u['a1']) : '';
    $val = isset($u['value']) ? (string)$u['value'] : '';
    if (!preg_match('/^[A-Z]+\d+$/', $a1)) {
        send_json(['error' => 'Invalid a1 cell ref: ' . $a1 . ' — single cells only (no ranges).'], 400);
    }
    $cleanUpdates[] = ['a1' => $a1, 'value' => $val];
}

$sheetName = isset($body['sheet_name']) ? trim((string)$body['sheet_name']) : '';
$prefixA1 = function ($a1) use ($sheetName) {
    if ($sheetName === '') return $a1;
    // Sheet names can contain spaces/special chars — wrap in single quotes.
    $safe = str_replace("'", "''", $sheetName);
    return "'" . $safe . "'!" . $a1;
};

$pdo = db();
$conn = sw_fetch_connector($pdo, $connectorId);
if (!$conn) send_json(['error' => 'Drive connector not found'], 404);
$accessToken = sw_ensure_fresh_token($pdo, $conn);

$payload = [
    'valueInputOption' => 'USER_ENTERED', // preserves cell format; "32.5%" → 0.325 displayed as 32.5%
    'data' => array_map(function ($u) use ($prefixA1) {
        return [
            'range'  => $prefixA1($u['a1']),
            'values' => [[$u['value']]],
        ];
    }, $cleanUpdates),
];

$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($fileId) . '/values:batchUpdate';
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = json_decode($resp, true);
if ($code < 200 || $code >= 300) {
    $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : ('HTTP ' . $code);
    send_json([
        'error'         => 'Sheets API rejected the write: ' . $msg,
        'http_code'     => $code,
        'sheets_error'  => $decoded['error'] ?? null,
        'attempted'     => count($cleanUpdates),
    ], 500);
}

send_json([
    'ok'             => true,
    'updated_cells'  => $decoded['totalUpdatedCells'] ?? 0,
    'updated_ranges' => $decoded['responses'] ?? [],
    'errors'         => [],
]);
