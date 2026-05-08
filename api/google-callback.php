<?php
// /api/google-callback.php — Handles the redirect from Google after the
// user consents. Exchanges `code` for tokens, fetches the user's profile,
// and stores the connector in the `connectors` table.
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/google-config.php';

session_start();

function html_close($title, $body, $color = '#1A1A1A') {
    $body = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>"
       . "<title>$title</title>"
       . "<style>body{font-family:'Inter',system-ui,sans-serif;background:#F1F1F1;padding:48px 24px;margin:0;color:#1A1A1A;}"
       . ".box{max-width:520px;margin:0 auto;background:#FFFFFF;border:1px solid #E1E3E5;border-radius:12px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,0.05);}"
       . "h1{font-size:18px;margin:0 0 12px;color:$color;}"
       . "p{font-size:14px;line-height:1.5;color:#303030;margin:0;}"
       . "a{display:inline-block;margin-top:18px;padding:9px 16px;background:#1A1A1A;color:#FFFFFF;border-radius:8px;text-decoration:none;font-weight:600;}"
       . "</style></head><body><div class='box'>"
       . "<h1>$title</h1><p>$body</p>"
       . "<a href='/settings.html'>Back to Settings</a>"
       . "</div><script>setTimeout(()=>{try{window.opener && window.opener.postMessage('google-connected', '*');window.close();}catch(e){}}, 1500);</script></body></html>";
}

if (!empty($_GET['error'])) {
    html_close('Connection cancelled', 'Google reported: ' . ($_GET['error'] ?? 'unknown'), '#D72C0D');
    exit;
}
if (empty($_GET['code']) || empty($_GET['state'])) {
    html_close('Missing parameters', 'No authorization code received.', '#D72C0D');
    exit;
}
if (!isset($_SESSION['google_oauth_state']) || $_SESSION['google_oauth_state'] !== $_GET['state']) {
    html_close('State mismatch', 'OAuth state cookie does not match. Try again from Settings → Connectors.', '#D72C0D');
    exit;
}
unset($_SESSION['google_oauth_state']);

// 1) Exchange code for tokens
$tokenReq = [
    'code'          => $_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
];
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($tokenReq),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tokens = json_decode($resp, true);
if ($code !== 200 || empty($tokens['access_token'])) {
    html_close('Token exchange failed', 'Google returned: ' . ($tokens['error_description'] ?? $resp), '#D72C0D');
    exit;
}

$access_token  = $tokens['access_token'];
$refresh_token = $tokens['refresh_token'] ?? null;
$expires_in    = (int)($tokens['expires_in'] ?? 3600);

// 2) Fetch the user's profile (email, name, picture)
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
]);
$profileResp = curl_exec($ch);
curl_close($ch);
$profile = json_decode($profileResp, true) ?: [];
$google_user_id   = $profile['id'] ?? '';
$google_email     = $profile['email'] ?? '';
$google_name      = $profile['name'] ?? ($profile['email'] ?? 'Google account');

// 3) Save / upsert connector. Mirrors the Meta connector pattern.
$pdo = db();
$meta = [
    'google_user_id'   => $google_user_id,
    'google_email'     => $google_email,
    'google_name'      => $google_name,
    'auth_method'      => 'oauth',
    'refresh_token'    => $refresh_token,
    'token_expires_at' => date('Y-m-d H:i:s', time() + $expires_in),
    'scopes'           => GOOGLE_SCOPES,
];
$meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE);

$row_name = $google_name . ($google_email ? ' (' . $google_email . ')' : '');

// If a connector for this Google account already exists, update it.
$stmt = $pdo->prepare("SELECT id FROM connectors
    WHERE type='storage' AND provider='google_drive'
      AND JSON_EXTRACT(meta,'$.google_user_id') = ?
      AND active=1 LIMIT 1");
$stmt->execute([$google_user_id]);
$existing = $stmt->fetch();

if ($existing) {
    $upd = $pdo->prepare("UPDATE connectors SET name=?, token=?, meta=? WHERE id=?");
    $upd->execute([$row_name, $access_token, $meta_json, (int)$existing['id']]);
    $connector_id = (int)$existing['id'];
} else {
    $ins = $pdo->prepare("INSERT INTO connectors (type, provider, name, token, meta)
                          VALUES ('storage','google_drive',?,?,?)");
    $ins->execute([$row_name, $access_token, $meta_json]);
    $connector_id = (int)$pdo->lastInsertId();
}

html_close(
    'Google Drive connected',
    'Connected as ' . ($google_email ?: $google_name) . '. You can now pick folders from your Drive in any tool.',
    '#0E7C2A'
);
