<?php
// /api/memories.php — short-term memory for AI tools.
// Each generation saves a row (skill_slug, tool, input_summary, output).
// Each new generation can fetch the most recent N rows for the same skill so
// the AI keeps building on past examples (your voice, your patterns).
//
// GET    ?skill=egyptian-copywriter&limit=5  → most recent rows
// POST   {skill, tool, input_summary, output, rating?}
// PATCH  ?id=N {rating}                       → set thumbs-up / down
// DELETE ?id=N
require_once __DIR__ . '/_db.php';
require_token();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
  $skill = trim($_GET['skill'] ?? '');
  $limit = max(1, min(20, (int)($_GET['limit'] ?? 5)));
  if (!$skill) send_json(['error' => 'skill required'], 400);
  $stmt = $pdo->prepare("SELECT id, skill_slug, tool, input_summary, output, rating, created_at
                         FROM memories WHERE skill_slug = ?
                         ORDER BY (rating = 1) DESC, id DESC LIMIT $limit");
  $stmt->execute([$skill]);
  send_json(['rows' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);
  $skill = trim($body['skill'] ?? '');
  if (!$skill) send_json(['error' => 'skill required'], 400);
  $stmt = $pdo->prepare("INSERT INTO memories (skill_slug, tool, input_summary, output, rating)
                         VALUES (?, ?, ?, ?, ?)");
  $stmt->execute([
    $skill,
    $body['tool']          ?? null,
    $body['input_summary'] ?? null,
    $body['output']        ?? null,
    isset($body['rating']) ? (int)$body['rating'] : null,
  ]);
  send_json(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($method === 'PATCH') {
  $id   = (int)($_GET['id'] ?? 0);
  $body = json_decode(file_get_contents('php://input'), true);
  if (!$id || !is_array($body)) send_json(['error' => 'id + body required'], 400);
  $stmt = $pdo->prepare("UPDATE memories SET rating = ? WHERE id = ?");
  $stmt->execute([isset($body['rating']) ? (int)$body['rating'] : null, $id]);
  send_json(['ok' => true]);
}

if ($method === 'DELETE') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) send_json(['error' => 'id required'], 400);
  $stmt = $pdo->prepare("DELETE FROM memories WHERE id = ?");
  $stmt->execute([$id]);
  send_json(['ok' => true]);
}

send_json(['error' => 'Method not allowed'], 405);
