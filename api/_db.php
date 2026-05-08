<?php
require_once __DIR__ . '/config.php';

function db() {
  global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
  static $pdo = null;
  if ($pdo === null) {
    try {
      $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
      );
    } catch (PDOException $e) {
      http_response_code(500);
      header('Content-Type: application/json');
      echo json_encode(['error' => 'DB connection failed', 'detail' => $e->getMessage()]);
      exit;
    }
  }
  return $pdo;
}

function require_token() {
  global $ACCESS_TOKEN;
  // Accept the bearer token via either the Authorization header (default
  // for all JS fetch calls) OR a ?token=… query string. The query-string
  // form is needed for plain <img>/<a download> requests that can't carry
  // custom headers (thumbnail proxy, in-app file downloads).
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  $q = $_GET['token'] ?? '';
  if ($h === "Bearer $ACCESS_TOKEN") return;
  if ($q !== '' && hash_equals((string)$ACCESS_TOKEN, (string)$q)) return;
  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

function send_json($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Authorization, Content-Type');
  header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// Handle CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Authorization, Content-Type');
  header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
  http_response_code(204);
  exit;
}
