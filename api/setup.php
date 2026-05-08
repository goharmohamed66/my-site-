<?php
// One-time setup. Visit this URL ONCE in browser to create the table.
// Safe to call multiple times (idempotent).
require_once __DIR__ . '/_db.php';

$pdo = db();

$pdo->exec("
CREATE TABLE IF NOT EXISTS shipping_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(100) DEFAULT NULL,
  product VARCHAR(500) DEFAULT '',
  city VARCHAR(150) DEFAULT '',
  status VARCHAR(50) DEFAULT '',
  cod DECIMAL(12,2) DEFAULT 0,
  fees DECIMAL(12,2) DEFAULT 0,
  net DECIMAL(12,2) DEFAULT 0,
  date DATE DEFAULT NULL,
  source VARCHAR(50) DEFAULT '',
  brand_id INT NULL,
  employee VARCHAR(150) DEFAULT NULL,
  raw JSON DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_source (source),
  INDEX idx_date (date),
  INDEX idx_status (status),
  INDEX idx_order (order_id),
  INDEX idx_brand (brand_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS connectors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL,
  provider VARCHAR(50) NOT NULL,
  name VARCHAR(200) NOT NULL,
  url VARCHAR(500) DEFAULT NULL,
  consumer_key VARCHAR(255) DEFAULT NULL,
  consumer_secret VARCHAR(255) DEFAULT NULL,
  token TEXT DEFAULT NULL,
  meta JSON DEFAULT NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_type (type),
  INDEX idx_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS app_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  email VARCHAR(200) NOT NULL UNIQUE,
  stores JSON DEFAULT NULL,
  ad_accounts JSON DEFAULT NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS memories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  skill_slug VARCHAR(100) NOT NULL,
  tool VARCHAR(50) DEFAULT NULL,
  input_summary TEXT DEFAULT NULL,
  output LONGTEXT DEFAULT NULL,
  rating TINYINT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_skill (skill_slug),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  instructions LONGTEXT DEFAULT NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Clients / Brands / Products hub — hybrid model: Drive holds the files,
// these tables index them so navigation + linking with Builds is fast.
$pdo->exec("
CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  drive_folder_id VARCHAR(120) NOT NULL,
  meta JSON DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// brands.sheets is a JSON object mapping a logical key to a Drive file id:
// { "financial_modeling": "...", "benchmarking": "...", "outcomes": "...",
//   "copywriting": "...", "content_plan_strategy": "...", "content_plan_hit_run": "..." }
$pdo->exec("
CREATE TABLE IF NOT EXISTS brands (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  name VARCHAR(200) NOT NULL,
  drive_folder_id VARCHAR(120) NOT NULL,
  sheets JSON DEFAULT NULL,
  meta JSON DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  brand_id INT NOT NULL,
  name VARCHAR(300) NOT NULL,
  drive_folder_id VARCHAR(120) NOT NULL,
  status ENUM('draft','ready','sent') DEFAULT 'draft',
  build_key VARCHAR(80) DEFAULT NULL,
  meta JSON DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_brand (brand_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

send_json(['ok' => true, 'message' => 'Tables created (shipping_orders, connectors, app_users, skills, clients, brands, products).']);
