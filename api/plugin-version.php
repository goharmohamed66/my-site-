<?php
// /api/plugin-version.php — exposes the live Auto Land Wp Funnels plugin version
// (parsed from its readme.txt) + last-modified timestamp. Used by the Settings UI
// to label the download with the right filename + cache-bust the URL.
//
// Returns: { version: "1.0.6", last_modified: "Fri, 01 May 2026 04:07:01 GMT" }
require_once __DIR__ . '/_db.php';

$readme_path = __DIR__ . '/../wp-plugin/auto-land/readme.txt';
$zip_path    = __DIR__ . '/../wp-plugin/auto-land.zip';

$version = '';
if (is_readable($readme_path)) {
    $txt = file_get_contents($readme_path);
    if (preg_match('/Stable tag:\s*([\d.]+)/i', $txt, $m)) $version = $m[1];
}

$last_modified = '';
if (is_readable($zip_path)) {
    $last_modified = gmdate('D, d M Y H:i:s', filemtime($zip_path)) . ' GMT';
}

send_json([
    'version'       => $version,
    'last_modified' => $last_modified,
    'zip_url'       => '/wp-plugin/auto-land.zip',
]);
