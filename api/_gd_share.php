<?php
// /api/_gd_share.php — Shared Drive permission helpers.
// Used by users.php (grant/revoke on user CRUD), brands.php (grant on brand
// create), and google-drive.php (manual backfill action).
//
// Pattern mirrors the existing gd_*_b helpers in brands.php — single
// connector, refresh-on-demand, idempotent calls.
require_once __DIR__ . '/google-config.php';

function gd_share_connector(PDO $pdo): ?array {
    $st = $pdo->prepare("SELECT id, name, token, meta FROM connectors
                         WHERE type='storage' AND provider='google_drive' AND active=1
                         ORDER BY id LIMIT 1");
    $st->execute();
    $r = $st->fetch();
    if (!$r) return null;
    $r['meta_decoded'] = $r['meta'] ? json_decode($r['meta'], true) : [];
    return $r;
}

function gd_share_token(PDO $pdo, array $conn): ?string {
    $meta = $conn['meta_decoded'];
    $exp = isset($meta['token_expires_at']) ? strtotime($meta['token_expires_at']) : 0;
    if ($exp - 60 > time() && !empty($conn['token'])) return $conn['token'];
    if (empty($meta['refresh_token'])) return null;
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
    if ($code !== 200 || empty($tok['access_token'])) return null;
    $meta['token_expires_at'] = date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600));
    $upd = $pdo->prepare("UPDATE connectors SET token=?, meta=? WHERE id=?");
    $upd->execute([$tok['access_token'], json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$conn['id']]);
    return $tok['access_token'];
}

function gd_share_owner_email(array $conn): string {
    return strtolower(trim($conn['meta_decoded']['google_email'] ?? ''));
}

// Grant `email` `role` access to a file/folder. Idempotent — treats
// "already shared" / "cannot share with owner" as success.
function gd_share_email(string $accessToken, string $fileId, string $email, string $role = 'writer'): array {
    $payload = json_encode([
        'role' => $role,
        'type' => 'user',
        'emailAddress' => $email,
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId)
        . '/permissions?sendNotificationEmail=false&fields=id');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) return ['ok' => true, 'code' => $code];
    $j = json_decode($r, true) ?: [];
    $msg = $j['error']['message'] ?? ('HTTP ' . $code);
    $lowered = strtolower($msg);
    if (strpos($lowered, 'already') !== false
        || strpos($lowered, 'owner') !== false
        || strpos($lowered, 'duplicate') !== false) {
        return ['ok' => true, 'noop' => true, 'code' => $code, 'note' => $msg];
    }
    return ['ok' => false, 'code' => $code, 'error' => $msg];
}

// Find the permission id for `email` on a file, then DELETE it.
function gd_share_unshare_email(string $accessToken, string $fileId, string $email): array {
    $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileId)
         . '/permissions?fields=permissions(id,emailAddress,role)&pageSize=100';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return ['ok' => false, 'code' => $code, 'error' => 'list failed'];
    $perms = (json_decode($r, true)['permissions'] ?? []);
    $permId = null;
    foreach ($perms as $p) {
        if (isset($p['emailAddress']) && strcasecmp($p['emailAddress'], $email) === 0) {
            $permId = $p['id']; break;
        }
    }
    if (!$permId) return ['ok' => true, 'noop' => true];
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId)
        . '/permissions/' . urlencode($permId));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300 ? ['ok' => true] : ['ok' => false, 'code' => $code, 'error' => $r];
}

function gd_share_list_brand_folders(PDO $pdo): array {
    $rows = $pdo->query("SELECT DISTINCT drive_folder_id FROM brands
                         WHERE drive_folder_id IS NOT NULL AND drive_folder_id <> ''")
                ->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

function gd_share_list_user_emails(PDO $pdo): array {
    $rows = $pdo->query("SELECT email FROM app_users
                         WHERE active=1 AND email IS NOT NULL AND email <> ''")
                ->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter($rows ?: []));
}

// Share every brand folder with `email`. Skips connector owner.
function gd_share_user_with_all_brands(PDO $pdo, string $email, string $role = 'writer'): array {
    $conn = gd_share_connector($pdo);
    if (!$conn) return ['ok' => false, 'error' => 'no connector'];
    if (strcasecmp(trim($email), gd_share_owner_email($conn)) === 0) {
        return ['ok' => true, 'noop' => true, 'note' => 'email is connector owner'];
    }
    $tok = gd_share_token($pdo, $conn);
    if (!$tok) return ['ok' => false, 'error' => 'token refresh failed'];
    $folders = gd_share_list_brand_folders($pdo);
    $results = [];
    foreach ($folders as $folder) {
        $results[$folder] = gd_share_email($tok, $folder, $email, $role);
    }
    return ['ok' => true, 'shared' => count($folders), 'results' => $results];
}

// Share `folderId` with every active app_user email. Skips connector owner.
function gd_share_folder_with_all_users(PDO $pdo, string $folderId, string $role = 'writer'): array {
    $conn = gd_share_connector($pdo);
    if (!$conn) return ['ok' => false, 'error' => 'no connector'];
    $tok = gd_share_token($pdo, $conn);
    if (!$tok) return ['ok' => false, 'error' => 'token refresh failed'];
    $owner = gd_share_owner_email($conn);
    $emails = gd_share_list_user_emails($pdo);
    $results = [];
    foreach ($emails as $email) {
        if (strcasecmp(trim($email), $owner) === 0) { $results[$email] = ['ok' => true, 'noop' => true]; continue; }
        $results[$email] = gd_share_email($tok, $folderId, $email, $role);
    }
    return ['ok' => true, 'shared' => count($emails), 'results' => $results];
}

function gd_share_revoke_user(PDO $pdo, string $email): array {
    $conn = gd_share_connector($pdo);
    if (!$conn) return ['ok' => false, 'error' => 'no connector'];
    $tok = gd_share_token($pdo, $conn);
    if (!$tok) return ['ok' => false, 'error' => 'token refresh failed'];
    $folders = gd_share_list_brand_folders($pdo);
    $results = [];
    foreach ($folders as $folder) {
        $results[$folder] = gd_share_unshare_email($tok, $folder, $email);
    }
    return ['ok' => true, 'revoked' => count($folders), 'results' => $results];
}

// Full backfill: every user × every brand.
function gd_share_sync_all(PDO $pdo, string $role = 'writer'): array {
    $conn = gd_share_connector($pdo);
    if (!$conn) return ['ok' => false, 'error' => 'no connector'];
    $tok = gd_share_token($pdo, $conn);
    if (!$tok) return ['ok' => false, 'error' => 'token refresh failed'];
    $owner = gd_share_owner_email($conn);
    $folders = gd_share_list_brand_folders($pdo);
    $emails  = gd_share_list_user_emails($pdo);
    $summary = ['folders' => count($folders), 'users' => count($emails), 'grants' => 0, 'errors' => []];
    foreach ($folders as $folder) {
        foreach ($emails as $email) {
            if (strcasecmp(trim($email), $owner) === 0) continue;
            $res = gd_share_email($tok, $folder, $email, $role);
            if (!empty($res['ok'])) $summary['grants']++;
            else $summary['errors'][] = ['folder' => $folder, 'email' => $email, 'error' => $res['error'] ?? ''];
        }
    }
    return $summary;
}
