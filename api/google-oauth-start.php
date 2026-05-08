<?php
// /api/google-oauth-start.php — Kicks off the Google OAuth flow.
// Hit this URL from the browser; it 302-redirects to Google's consent screen.
// Google will then call back to /api/google-callback.php with ?code=…
require_once __DIR__ . '/google-config.php';

// CSRF state so the callback can confirm the response belongs to this user
$state = bin2hex(random_bytes(16));
session_start();
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => GOOGLE_SCOPES,
    'access_type'   => 'offline',          // returns a refresh_token
    'prompt'        => 'consent',          // force re-consent so refresh_token is included every time
    'include_granted_scopes' => 'true',
    'state'         => $state,
];

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
