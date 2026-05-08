<?php
// /api/users.php — CRUD for app users (team members)
// GET                 → list users
// POST {name, email, stores[], ad_accounts[]}
// DELETE ?id=123
require_once __DIR__ . '/_db.php';
require_token();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// Lazy migration: add `shipping_companies` JSON column if it does not yet exist.
function ensure_shipping_col($pdo) {
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    $col = $pdo->query("SHOW COLUMNS FROM app_users LIKE 'shipping_companies'")->fetch();
    if (!$col) $pdo->exec("ALTER TABLE app_users ADD COLUMN shipping_companies TEXT NULL");
  } catch (PDOException $e) { /* ignore */ }
}

// Lazy migration: add `password_hash` column for login support.
function ensure_password_col($pdo) {
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    $col = $pdo->query("SHOW COLUMNS FROM app_users LIKE 'password_hash'")->fetch();
    if (!$col) $pdo->exec("ALTER TABLE app_users ADD COLUMN password_hash VARCHAR(255) NULL");
  } catch (PDOException $e) { /* ignore */ }
}

// Lazy migration: add `ai_tools` JSON column for per-user AI provider permissions.
function ensure_ai_tools_col($pdo) {
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    $col = $pdo->query("SHOW COLUMNS FROM app_users LIKE 'ai_tools'")->fetch();
    if (!$col) $pdo->exec("ALTER TABLE app_users ADD COLUMN ai_tools TEXT NULL");
  } catch (PDOException $e) { /* ignore */ }
}

if ($method === 'GET') {
  ensure_shipping_col($pdo);
  ensure_password_col($pdo);
  ensure_ai_tools_col($pdo);
  $stmt = $pdo->query("SELECT id, name, email, stores, ad_accounts, shipping_companies, ai_tools, created_at FROM app_users WHERE active = 1 ORDER BY id");
  $rows = $stmt->fetchAll();
  foreach ($rows as &$r) {
    foreach (['stores', 'ad_accounts', 'shipping_companies', 'ai_tools'] as $k) {
      if (!empty($r[$k])) {
        $d = json_decode($r[$k], true);
        if ($d !== null) $r[$k] = $d;
      } else $r[$k] = [];
    }
  }
  send_json(['count' => count($rows), 'rows' => $rows]);
}

if ($method === 'POST') {
  ensure_shipping_col($pdo);
  ensure_password_col($pdo);
  ensure_ai_tools_col($pdo);
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);

  $id     = isset($body['id']) ? (int)$body['id'] : 0;
  $name   = trim($body['name']  ?? '');
  $email  = trim($body['email'] ?? '');
  $stores = json_encode($body['stores']             ?? [], JSON_UNESCAPED_UNICODE);
  $ads    = json_encode($body['ad_accounts']        ?? [], JSON_UNESCAPED_UNICODE);
  $ship   = json_encode($body['shipping_companies'] ?? [], JSON_UNESCAPED_UNICODE);
  $ai     = json_encode($body['ai_tools']           ?? [], JSON_UNESCAPED_UNICODE);
  $password = isset($body['password']) ? (string)$body['password'] : '';

  if (!$name || !$email) send_json(['error' => 'name and email required'], 400);
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) send_json(['error' => 'invalid email'], 400);
  if ($password !== '' && strlen($password) < 6) send_json(['error' => 'password must be at least 6 characters'], 400);

  if ($id > 0) {
    if ($password !== '') {
      $hash = password_hash($password, PASSWORD_BCRYPT);
      $stmt = $pdo->prepare("UPDATE app_users SET name=?, email=?, stores=?, ad_accounts=?, shipping_companies=?, ai_tools=?, password_hash=? WHERE id=?");
      $stmt->execute([$name, $email, $stores, $ads, $ship, $ai, $hash, $id]);
    } else {
      $stmt = $pdo->prepare("UPDATE app_users SET name=?, email=?, stores=?, ad_accounts=?, shipping_companies=?, ai_tools=? WHERE id=?");
      $stmt->execute([$name, $email, $stores, $ads, $ship, $ai, $id]);
    }
    send_json(['ok' => true, 'id' => $id]);
  } else {
    if ($password === '') send_json(['error' => 'password is required for new users'], 400);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    try {
      $stmt = $pdo->prepare("INSERT INTO app_users (name, email, stores, ad_accounts, shipping_companies, ai_tools, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$name, $email, $stores, $ads, $ship, $ai, $hash]);
      send_json(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
      if (strpos($e->getMessage(), 'Duplicate') !== false) {
        send_json(['error' => 'email already exists'], 409);
      }
      throw $e;
    }
  }
}

if ($method === 'DELETE') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) send_json(['error' => 'id required'], 400);
  $stmt = $pdo->prepare("UPDATE app_users SET active = 0 WHERE id = ?");
  $stmt->execute([$id]);
  send_json(['ok' => true, 'deleted' => $id]);
}

send_json(['error' => 'Method not allowed'], 405);
