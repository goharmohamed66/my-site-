<?php
// /api/skills.php — CRUD for AI Skills (system-prompt blocks injected into
// every AI tool call so the model sticks to the user's voice/style).
//
// GET    ?slug=xxx        → fetch one skill
// GET    (no params)      → list all active skills
// POST   {slug, name, description, instructions[, id]}
//          - if `id` is given OR a row with the same slug exists → update
//          - otherwise insert
// DELETE ?id=123          → soft-delete (active = 0)
// DELETE ?slug=xxx        → soft-delete by slug
require_once __DIR__ . '/_db.php';
require_token();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ---- GET ----
if ($method === 'GET') {
  if (!empty($_GET['slug'])) {
    $stmt = $pdo->prepare("SELECT id, slug, name, description, instructions, active, created_at, updated_at
                           FROM skills WHERE slug = ? AND active = 1 LIMIT 1");
    $stmt->execute([$_GET['slug']]);
    $row = $stmt->fetch();
    if (!$row) send_json(['error' => 'Skill not found'], 404);
    send_json($row);
  }
  $stmt = $pdo->prepare("SELECT id, slug, name, description, instructions, active, created_at, updated_at
                         FROM skills WHERE active = 1 ORDER BY name ASC");
  $stmt->execute();
  $rows = $stmt->fetchAll();
  send_json(['count' => count($rows), 'rows' => $rows]);
}

// ---- POST (insert / update) ----
if ($method === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) send_json(['error' => 'JSON body required'], 400);

  $id           = isset($body['id']) ? (int)$body['id'] : 0;
  $slug         = trim($body['slug']         ?? '');
  $name         = trim($body['name']         ?? '');
  $description  = trim($body['description']  ?? '');
  $instructions = (string)($body['instructions'] ?? '');

  if (!$slug || !$name) send_json(['error' => 'slug and name are required'], 400);
  // Slugify defensively (lowercase, hyphens only)
  $slug = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $slug));
  $slug = trim($slug, '-');
  if ($slug === '') send_json(['error' => 'slug invalid after sanitization'], 400);

  // If an `id` was passed, update directly; otherwise upsert by slug.
  if ($id > 0) {
    $stmt = $pdo->prepare("UPDATE skills SET slug=?, name=?, description=?, instructions=?, active=1
                           WHERE id=?");
    $stmt->execute([$slug, $name, $description, $instructions, $id]);
    send_json(['ok' => true, 'id' => $id, 'mode' => 'updated']);
  }

  $stmt = $pdo->prepare("SELECT id FROM skills WHERE slug=? LIMIT 1");
  $stmt->execute([$slug]);
  $existing = $stmt->fetchColumn();

  if ($existing) {
    $stmt = $pdo->prepare("UPDATE skills SET name=?, description=?, instructions=?, active=1
                           WHERE id=?");
    $stmt->execute([$name, $description, $instructions, (int)$existing]);
    send_json(['ok' => true, 'id' => (int)$existing, 'mode' => 'updated_by_slug']);
  } else {
    $stmt = $pdo->prepare("INSERT INTO skills (slug, name, description, instructions)
                           VALUES (?, ?, ?, ?)");
    $stmt->execute([$slug, $name, $description, $instructions]);
    send_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'mode' => 'created']);
  }
}

// ---- DELETE ----
if ($method === 'DELETE') {
  if (!empty($_GET['id'])) {
    $stmt = $pdo->prepare("UPDATE skills SET active=0 WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    send_json(['ok' => true, 'deleted' => (int)$_GET['id']]);
  }
  if (!empty($_GET['slug'])) {
    $stmt = $pdo->prepare("UPDATE skills SET active=0 WHERE slug=?");
    $stmt->execute([$_GET['slug']]);
    send_json(['ok' => true, 'deleted_slug' => $_GET['slug']]);
  }
  send_json(['error' => 'id or slug required'], 400);
}

send_json(['error' => 'Method not allowed'], 405);
