<?php
// /api/connectors.php — CRUD for store / shipping / ad / confirmation connectors
// GET    ?type=cms             → list connectors of type
// POST   {type, provider, name, url, consumer_key, consumer_secret, token, meta}
// DELETE ?id=123
require_once __DIR__ . '/_db.php';
require_token();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// ---- GET (list) ----
if ($method === 'GET') {
  $where = []; $args = [];
  if (!empty($_GET['type']))     { $where[] = 'type = ?';     $args[] = $_GET['type']; }
  if (!empty($_GET['provider'])) { $where[] = 'provider = ?'; $args[] = $_GET['provider']; }
  $where[] = 'active = 1';
  $sql = "SELECT id, type, provider, name, url, consumer_key, consumer_secret, token, meta, created_at, updated_at
          FROM connectors WHERE " . implode(' AND ', $where) . " ORDER BY id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($args);
  $rows = $stmt->fetchAll();
  foreach ($rows as &$r) {
    if (!empty($r['meta'])) {
      $d = json_decode($r['meta'], true);
      if ($d !== null) $r['meta'] = $d;
    }
  }
  send_json(['count' => count($rows), 'rows' => $rows]);
}

// ---- POST (insert / update) ----
if ($method === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);

  $id        = isset($body['id']) ? (int)$body['id'] : 0;
  $type      = $body['type']     ?? '';
  $provider  = $body['provider'] ?? '';
  $name      = $body['name']     ?? '';
  $url       = $body['url']      ?? null;
  $ck        = $body['consumer_key']    ?? null;
  $cs        = $body['consumer_secret'] ?? null;
  $token     = $body['token']    ?? null;
  $meta      = isset($body['meta']) ? json_encode($body['meta'], JSON_UNESCAPED_UNICODE) : null;

  if (!$type || !$provider || !$name) {
    send_json(['error' => 'type, provider and name are required'], 400);
  }

  if ($id > 0) {
    // Partial update: only overwrite columns the frontend actually sent.
    // This protects the OAuth token + existing meta when the user edits a
    // connector form that doesn't expose those fields (e.g. the Google
    // Drive edit modal exposes name + meta only — without this guard the
    // token + url get nulled out on every save).
    $sets = []; $args = [];
    $sets[] = 'type=?';     $args[] = $type;
    $sets[] = 'provider=?'; $args[] = $provider;
    $sets[] = 'name=?';     $args[] = $name;
    if (array_key_exists('url',             $body)) { $sets[] = 'url=?';             $args[] = $url; }
    if (array_key_exists('consumer_key',    $body)) { $sets[] = 'consumer_key=?';    $args[] = $ck; }
    if (array_key_exists('consumer_secret', $body)) { $sets[] = 'consumer_secret=?'; $args[] = $cs; }
    if (array_key_exists('token',           $body) && $token !== null && $token !== '') {
      $sets[] = 'token=?'; $args[] = $token;
    }
    if (array_key_exists('meta', $body)) {
      // Merge with existing meta so unrelated keys (refresh_token, token_expires_at, allowed_accounts…) survive.
      $st = $pdo->prepare("SELECT meta FROM connectors WHERE id=?");
      $st->execute([$id]);
      $existingMeta = $st->fetchColumn();
      $existing = $existingMeta ? (json_decode($existingMeta, true) ?: []) : [];
      $merged = array_merge($existing, $body['meta']);
      $sets[] = 'meta=?'; $args[] = json_encode($merged, JSON_UNESCAPED_UNICODE);
    }
    $args[] = $id;
    $stmt = $pdo->prepare("UPDATE connectors SET " . implode(',', $sets) . " WHERE id=?");
    $stmt->execute($args);
    send_json(['ok' => true, 'id' => $id, 'mode' => 'updated']);
  } else {
    $stmt = $pdo->prepare("INSERT INTO connectors
      (type, provider, name, url, consumer_key, consumer_secret, token, meta)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$type, $provider, $name, $url, $ck, $cs, $token, $meta]);
    send_json(['ok' => true, 'id' => $pdo->lastInsertId(), 'mode' => 'created']);
  }
}

// ---- DELETE ----
if ($method === 'DELETE') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) send_json(['error' => 'id required'], 400);
  $stmt = $pdo->prepare("UPDATE connectors SET active = 0 WHERE id = ?");
  $stmt->execute([$id]);
  send_json(['ok' => true, 'deleted' => $id]);
}

send_json(['error' => 'Method not allowed'], 405);
