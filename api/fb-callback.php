<?php
// /api/fb-callback.php — Facebook OAuth callback.
// User clicks "Sign in with Facebook" → Facebook redirects here with `?code=...`
// We exchange code → short-lived token → long-lived (60-day) token, then store
// it in the connectors table as a Meta connector. Finally we render a tiny
// HTML page that tells the opener window we're done and closes the popup.
require_once __DIR__ . '/_db.php';

global $FB_APP_ID, $FB_APP_SECRET, $FB_REDIRECT_URI;

// ── Helpers ──────────────────────────────────────────────────────────
function html_response($title, $body, $payload = null) {
    header('Content-Type: text/html; charset=utf-8');
    $payload_js = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : 'null';
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>$title</title>"
       . "<style>body{font-family:-apple-system,Segoe UI,Inter,Tahoma,Arial,sans-serif;background:#F1F1F1;color:#1A1A1A;padding:30px;text-align:center;}"
       . ".box{max-width:480px;margin:60px auto;background:#fff;padding:28px;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,0.06);}"
       . "h1{font-size:18px;margin-bottom:10px;}p{font-size:13px;color:#6D7175;line-height:1.6;}</style></head>"
       . "<body><div class=\"box\">$body</div>"
       . "<script>try { if (window.opener && !window.opener.closed) {"
       . "  window.opener.postMessage({ type: 'fb-oauth', payload: $payload_js }, '*');"
       . "  setTimeout(() => window.close(), 1500);"
       . "} } catch (e) {}</script></body></html>";
    exit;
}

function fb_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $resp];
}

// ── 1. Validate `code` ───────────────────────────────────────────────
$code = $_GET['code'] ?? '';
if (!$code) {
    $err = $_GET['error_description'] ?? ($_GET['error'] ?? 'No code returned by Facebook.');
    html_response('Login failed', "<h1>❌ Login failed</h1><p>" . htmlspecialchars($err) . "</p>");
}

// Optional state param (CSRF protection — could be matched against a cookie/session if hardened)
$state = $_GET['state'] ?? '';

// ── 2. Exchange code → short-lived access_token ──────────────────────
$ex_url = 'https://graph.facebook.com/v21.0/oauth/access_token'
        . '?client_id='     . urlencode($FB_APP_ID)
        . '&client_secret=' . urlencode($FB_APP_SECRET)
        . '&redirect_uri='  . urlencode($FB_REDIRECT_URI)
        . '&code='          . urlencode($code);
$r = fb_get($ex_url);
if (!$r['ok']) html_response('Login failed', "<h1>❌ Token exchange failed</h1><p>HTTP {$r['code']}: " . htmlspecialchars(substr($r['body'], 0, 300)) . "</p>");

$tok = json_decode($r['body'], true);
$short_token = $tok['access_token'] ?? '';
if (!$short_token) html_response('Login failed', "<h1>❌ No access_token in response</h1><pre>" . htmlspecialchars(substr($r['body'], 0, 400)) . "</pre>");

// ── 3. Exchange short-lived → long-lived (~60 days) ──────────────────
$ll_url = 'https://graph.facebook.com/v21.0/oauth/access_token'
        . '?grant_type=fb_exchange_token'
        . '&client_id='        . urlencode($FB_APP_ID)
        . '&client_secret='    . urlencode($FB_APP_SECRET)
        . '&fb_exchange_token=' . urlencode($short_token);
$r = fb_get($ll_url);
$ll = $r['ok'] ? json_decode($r['body'], true) : null;
$long_token = $ll['access_token'] ?? $short_token; // fall back to short if exchange fails
$expires_in = $ll['expires_in']  ?? 0;             // seconds (~5184000 = 60 days)

// ── 4. Fetch user info ───────────────────────────────────────────────
$me = fb_get('https://graph.facebook.com/v21.0/me?fields=id,name,email&access_token=' . urlencode($long_token));
$user = $me['ok'] ? json_decode($me['body'], true) : ['name' => 'Facebook user'];

// ── 5. Fetch ad accounts (so the UI can show what they connected to) ─
$accts = fb_get('https://graph.facebook.com/v21.0/me/adaccounts?fields=id,account_id,name,currency,account_status&limit=100&access_token=' . urlencode($long_token));
$ad_accounts = [];
if ($accts['ok']) {
    $j = json_decode($accts['body'], true);
    foreach (($j['data'] ?? []) as $a) {
        $ad_accounts[] = [
            'id'             => $a['id']             ?? '',
            'account_id'     => $a['account_id']     ?? '',
            'name'           => $a['name']           ?? '',
            'currency'       => $a['currency']       ?? '',
            'account_status' => $a['account_status'] ?? null,
        ];
    }
}

// ── 6. Save / upsert into connectors table (provider=meta) ───────────
$pdo = db();
// One Meta connector per user — find existing by user_id stored in meta.
$user_id = $user['id'] ?? '';
$existing = null;
if ($user_id) {
    $stmt = $pdo->prepare("SELECT id, meta FROM connectors
                           WHERE type='ads' AND provider='meta'
                             AND JSON_EXTRACT(meta,'$.fb_user_id') = ?
                             AND active=1 LIMIT 1");
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch();
}

$meta = [
    'fb_user_id'         => $user_id,
    'fb_user_name'       => $user['name'] ?? '',
    'fb_user_email'      => $user['email'] ?? '',
    'auth_method'        => 'oauth',
    'token_expires_at'   => $expires_in ? gmdate('Y-m-d H:i:s', time() + $expires_in) : null,
    'available_accounts' => $ad_accounts,
    // preserve allowed_accounts whitelist if it already existed
    'allowed_accounts'   => null,
];
if ($existing) {
    $prev = json_decode($existing['meta'] ?? '', true);
    if (is_array($prev) && !empty($prev['allowed_accounts'])) {
        $meta['allowed_accounts'] = $prev['allowed_accounts'];
    }
}

$row_name = 'Meta — ' . ($user['name'] ?? 'OAuth');
$meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE);

if ($existing) {
    $stmt = $pdo->prepare("UPDATE connectors
                           SET name=?, token=?, meta=?, active=1
                           WHERE id=?");
    $stmt->execute([$row_name, $long_token, $meta_json, (int)$existing['id']]);
    $connector_id = (int) $existing['id'];
} else {
    $stmt = $pdo->prepare("INSERT INTO connectors (type, provider, name, token, meta)
                           VALUES ('ads', 'meta', ?, ?, ?)");
    $stmt->execute([$row_name, $long_token, $meta_json]);
    $connector_id = (int) $pdo->lastInsertId();
}

// ── 7. Render success page (closes popup automatically) ──────────────
$expires_human = $expires_in ? round($expires_in / 86400) . ' days' : 'unknown';
html_response(
    'Connected to Facebook',
    "<h1>✓ Connected as " . htmlspecialchars($user['name'] ?? '') . "</h1>"
        . "<p>Stored long-lived token (valid ~$expires_human).<br>Found " . count($ad_accounts) . " ad account(s).</p>"
        . "<p style=\"font-size:12px;\">You can close this window.</p>",
    [
        'ok'              => true,
        'connector_id'    => $connector_id,
        'user_name'       => $user['name'] ?? '',
        'ad_account_count' => count($ad_accounts),
    ]
);
