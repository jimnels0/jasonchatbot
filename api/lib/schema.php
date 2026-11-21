<?php
// api/lib/schema.php -- every table. Run by api/migrate.php (CLI only) on each deploy; CREATE ... IF NOT EXISTS, so re-running is safe.
// Every customer-owned row carries tenant_id; every dashboard query filters on the caller's tenant (lib/auth.php).
const SCHEMA = [
"CREATE TABLE IF NOT EXISTS tenants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  public_key CHAR(24) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  website VARCHAR(255) NOT NULL DEFAULT '',
  color CHAR(7) NOT NULL DEFAULT '#4f46e5',
  greeting_en VARCHAR(300) NOT NULL DEFAULT '',
  greeting_es VARCHAR(300) NOT NULL DEFAULT '',
  tone VARCHAR(600) NOT NULL DEFAULT '',
  departments VARCHAR(500) NOT NULL DEFAULT 'Sales,Support,Billing',
  allowed_domains VARCHAR(1000) NOT NULL DEFAULT '',
  ask_email TINYINT NOT NULL DEFAULT 1,
  plan VARCHAR(20) NOT NULL DEFAULT 'trial',
  msg_limit INT NOT NULL DEFAULT 500,
  max_pages INT NOT NULL DEFAULT 50,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','agent') NOT NULL DEFAULT 'agent',
  is_platform_admin TINYINT NOT NULL DEFAULT 0,
  lang CHAR(2) NOT NULL DEFAULT 'en',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  INDEX (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  INDEX (user_id)
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  kind ENUM('site','url','text','pdf','doc') NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  url VARCHAR(1000) NOT NULL DEFAULT '',
  file VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('queued','working','ready','error') NOT NULL DEFAULT 'queued',
  error VARCHAR(300) NOT NULL DEFAULT '',
  pages INT NOT NULL DEFAULT 0,
  chars INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (tenant_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS chunks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  source_id INT NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  url VARCHAR(1000) NOT NULL DEFAULT '',
  content TEXT NOT NULL,
  INDEX (tenant_id), INDEX (source_id),
  FULLTEXT KEY ft (title, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS qa (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  question VARCHAR(500) NOT NULL,
  answer TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (tenant_id),
  FULLTEXT KEY ft (question, answer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  token CHAR(32) NOT NULL,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  department VARCHAR(40) NOT NULL DEFAULT '',
  topic VARCHAR(40) NOT NULL DEFAULT '',
  city VARCHAR(100) NOT NULL DEFAULT '',
  region VARCHAR(100) NOT NULL DEFAULT '',
  country VARCHAR(100) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  page VARCHAR(500) NOT NULL DEFAULT '',
  lang CHAR(2) NOT NULL DEFAULT 'en',
  mode ENUM('bot','human') NOT NULL DEFAULT 'bot',
  handoff TINYINT NOT NULL DEFAULT 0,
  unread TINYINT NOT NULL DEFAULT 0,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  agent_id INT NULL,
  created_at DATETIME NOT NULL,
  last_at DATETIME NOT NULL,
  INDEX (tenant_id, last_at), INDEX (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conv_id INT NOT NULL,
  tenant_id INT NOT NULL,
  role ENUM('visitor','bot','agent','system') NOT NULL,
  body TEXT NOT NULL,
  sources TEXT NULL,
  answered TINYINT NOT NULL DEFAULT 1,
  via VARCHAR(80) NOT NULL DEFAULT '',
  agent_name VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX (conv_id), INDEX (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS usage_monthly (
  tenant_id INT NOT NULL,
  ym CHAR(7) NOT NULL,
  answers INT NOT NULL DEFAULT 0,
  tokens_in BIGINT NOT NULL DEFAULT 0,
  tokens_out BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, ym)
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS geo_cache (
  ip VARCHAR(45) PRIMARY KEY,
  city VARCHAR(100) NOT NULL DEFAULT '',
  region VARCHAR(100) NOT NULL DEFAULT '',
  country VARCHAR(100) NOT NULL DEFAULT '',
  at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS rate_hits (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  k VARCHAR(120) NOT NULL,
  at DATETIME NOT NULL,
  INDEX (k, at)
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  conv_id INT NOT NULL,
  dl_key CHAR(32) NOT NULL,
  name VARCHAR(200) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size INT NOT NULL,
  path VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (conv_id), INDEX (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  country VARCHAR(100) NOT NULL DEFAULT '',
  path VARCHAR(255) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

// Columns added after v0.1. CREATE TABLE IF NOT EXISTS never adds columns, so migrate.php adds any missing one from here.
const UPGRADES = [
  ['users', 'seen_at', 'DATETIME NULL'],                                  // last dashboard heartbeat (presence)
  ['users', 'available', 'TINYINT NOT NULL DEFAULT 1'],                   // agent's own "I can take live chats" switch
  ['tenants', 'live_chat', 'TINYINT NOT NULL DEFAULT 1'],                 // offer "Talk to a person" at all
  ['tenants', 'notify_emails', "VARCHAR(600) NOT NULL DEFAULT ''"],       // alert + transcript addresses ('' = the owner)
  ['tenants', 'email_transcripts', 'TINYINT NOT NULL DEFAULT 1'],         // email every finished chat
  ['tenants', 'email_alerts', 'TINYINT NOT NULL DEFAULT 1'],              // email when a visitor asks for a person
  ['conversations', 'visitor_seen_at', 'DATETIME NULL'],                 // widget heartbeat while the chat panel is open
  ['conversations', 'handoff_at', 'DATETIME NULL'],
  ['conversations', 'alerted_at', 'DATETIME NULL'],
  ['conversations', 'emailed_at', 'DATETIME NULL'],
  ['conversations', 'visitor_mail_at', 'DATETIME NULL'],                 // visitor asked "email me this chat" (pending until sent)
  ['conversations', 'user_agent', "VARCHAR(255) NOT NULL DEFAULT ''"],
  ['messages', 'file_id', 'INT NULL'],
  ['messages', 'delivered', 'TINYINT NOT NULL DEFAULT 0'],               // agent message seen by the widget (else emailed)
];
