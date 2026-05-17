<?php
// /api/_cleanup-connector.php — One-shot admin script to delete a Google
// Drive connector by email. Delete this file after running it.
//
//   GET ?email=foo@bar.com               → preview (no delete)
//   GET ?email=foo@bar.com&confirm=1     → actually delete
require_once __DIR__ . '/_db.php';
require_token();

$pdo = db();
$email = trim($_GET['email'] ?? '');

$rows = $pdo->query("SELECT id, name, JSON_EXTRACT(meta,'$.google_email') AS email, active
                     FROM connectors
                     WHERE type='storage' AND provider='google_drive'
                     ORDER BY id")->fetchAll();

if ($email === '' || ($_GET['confirm'] ?? '') !== '1') {
    send_json([
        'preview' => true,
        'rows'    => $rows,
        'next'    => 'Add ?email=foo@bar.com&confirm=1 to delete the matching connector.',
    ]);
}

$st = $pdo->prepare("DELETE FROM connectors
                     WHERE type='storage' AND provider='google_drive'
                       AND JSON_EXTRACT(meta,'$.google_email') = ?");
$st->execute([$email]);

$after = $pdo->query("SELECT id, name, JSON_EXTRACT(meta,'$.google_email') AS email, active
                      FROM connectors
                      WHERE type='storage' AND provider='google_drive'
                      ORDER BY id")->fetchAll();

send_json([
    'deleted_rows' => $st->rowCount(),
    'deleted_email' => $email,
    'remaining'    => $after,
    'note'         => 'Now delete /api/_cleanup-connector.php from the server.',
]);
