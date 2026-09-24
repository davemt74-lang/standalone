-- Admin V2.0 — Unified Operations Command Center
-- Adds the operations layer over existing authoritative account, billing, membership and audit ledgers.

CREATE TABLE IF NOT EXISTS admin_operator_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  role_key VARCHAR(64) NOT NULL DEFAULT 'super_admin',
  capabilities_json JSON NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_operator_role(role_key,status),
  CONSTRAINT fk_admin_operator_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_operator_profiles(user_id,role_key,capabilities_json,status)
SELECT id,'super_admin',JSON_ARRAY('admin.*'),'active' FROM users WHERE role='admin'
ON DUPLICATE KEY UPDATE user_id=VALUES(user_id);

CREATE TABLE IF NOT EXISTS admin_operations_alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  fingerprint CHAR(64) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NULL,
  severity ENUM('info','warn','danger') NOT NULL DEFAULT 'warn',
  category VARCHAR(80) NOT NULL,
  title VARCHAR(255) NOT NULL,
  detail TEXT NULL,
  source_type VARCHAR(80) NOT NULL,
  source_public_id VARCHAR(255) NULL,
  target_url VARCHAR(500) NULL,
  status ENUM('open','snoozed','resolved') NOT NULL DEFAULT 'open',
  assigned_user_id BIGINT UNSIGNED NULL,
  snoozed_until DATETIME NULL,
  resolution_note VARCHAR(500) NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  last_scan_token VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_ops_alert_state(status,severity,last_seen_at,id),
  INDEX idx_admin_ops_alert_account(account_id,status,last_seen_at,id),
  INDEX idx_admin_ops_alert_category(category,status,last_seen_at,id),
  INDEX idx_admin_ops_alert_assignee(assigned_user_id,status,last_seen_at,id),
  INDEX idx_admin_ops_alert_scan(last_scan_token,status),
  CONSTRAINT fk_admin_ops_alert_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_ops_alert_assignee FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_saved_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  scope ENUM('command_center','accounts') NOT NULL DEFAULT 'command_center',
  name VARCHAR(120) NOT NULL,
  filters_json JSON NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_saved_view_name(user_id,scope,name),
  INDEX idx_admin_saved_view_user(user_id,scope,is_default,updated_at,id),
  CONSTRAINT fk_admin_saved_view_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_action_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  action_type VARCHAR(100) NOT NULL,
  status ENUM('previewed','executing','executed','failed','cancelled') NOT NULL DEFAULT 'previewed',
  risk_level ENUM('routine','elevated','high') NOT NULL DEFAULT 'routine',
  reason VARCHAR(500) NOT NULL,
  request_json JSON NOT NULL,
  result_json JSON NULL,
  correlation_id CHAR(64) NOT NULL UNIQUE,
  previewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  executed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_action_account(account_id,created_at,id),
  INDEX idx_admin_action_actor(actor_user_id,created_at,id),
  INDEX idx_admin_action_status(status,risk_level,created_at,id),
  INDEX idx_admin_action_type(action_type,created_at,id),
  CONSTRAINT fk_admin_action_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_action_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
