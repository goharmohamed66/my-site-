<?php
// /api/fb-config.php — exposes only the public bits of the Facebook App
// (App ID + redirect URI) so the frontend can build the OAuth URL without
// hardcoding them. App Secret stays server-side.
require_once __DIR__ . '/_db.php';

global $FB_APP_ID, $FB_REDIRECT_URI;

send_json([
    'app_id'       => $FB_APP_ID,
    'redirect_uri' => $FB_REDIRECT_URI,
    // Permissions requested. ads_read for analytics, ads_management for
    // create/edit campaigns, business_management for multi-account access.
    'scope'        => 'ads_read,ads_management,business_management,pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement',
]);
