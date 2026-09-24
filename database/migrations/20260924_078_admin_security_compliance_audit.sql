-- Admin V2.50 — Security, Compliance & Administrative Audit Center
-- Extends the V2.10 security audit ledger without replacing source-domain ledgers.

ALTER TABLE admin_security_audit_events
  MODIFY COLUMN subject_type VARCHAR(80) NOT NULL,
  ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER actor_user_id,
  ADD COLUMN source_domain VARCHAR(64) NOT NULL DEFAULT 'security' AFTER event_type,
  ADD COLUMN source_event_public_id VARCHAR(128) NULL AFTER source_domain,
  ADD COLUMN correlation_id VARCHAR(96) NULL AFTER source_event_public_id,
  ADD COLUMN sensitivity ENUM('standard','restricted','high') NOT NULL DEFAULT 'restricted' AFTER correlation_id,
  ADD COLUMN metadata_json JSON NULL AFTER after_json,
  ADD COLUMN actor_ip_hash CHAR(64) NULL AFTER reason,
  ADD COLUMN actor_user_agent_hash CHAR(64) NULL AFTER actor_ip_hash,
  ADD INDEX idx_admin_security_audit_account(account_id,created_at,id),
  ADD INDEX idx_admin_security_audit_domain(source_domain,created_at,id),
  ADD INDEX idx_admin_security_audit_correlation(correlation_id,created_at,id),
  ADD CONSTRAINT fk_admin_security_audit_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS admin_security_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  severity ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('open','investigating','monitoring','resolved','dismissed') NOT NULL DEFAULT 'open',
  account_id BIGINT UNSIGNED NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  opened_from_event_public_id VARCHAR(40) NULL,
  resolution_note TEXT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NOT NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_security_case_state(status,severity,updated_at,id),
  INDEX idx_admin_security_case_account(account_id,status,updated_at,id),
  INDEX idx_admin_security_case_assignee(assigned_user_id,status,updated_at,id),
  CONSTRAINT fk_admin_security_case_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_security_case_assignee FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_security_case_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_admin_security_case_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_security_case_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  case_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_security_case_event_case(case_id,created_at,id),
  INDEX idx_admin_security_case_event_actor(actor_user_id,created_at,id),
  INDEX idx_admin_security_case_event_type(event_type,created_at,id),
  CONSTRAINT fk_admin_security_case_event_case FOREIGN KEY(case_id) REFERENCES admin_security_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_security_case_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_privacy_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  request_type ENUM('access','export','deletion','restriction','rectification','consent_review','retention_review') NOT NULL,
  status ENUM('open','verifying','in_progress','blocked','completed','denied','cancelled') NOT NULL DEFAULT 'open',
  subject_user_id BIGINT UNSIGNED NULL,
  account_id BIGINT UNSIGNED NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  request_reference VARCHAR(190) NULL,
  request_note TEXT NULL,
  due_at DATETIME NULL,
  evidence_json JSON NULL,
  completion_note TEXT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NOT NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_privacy_request_state(status,due_at,updated_at,id),
  INDEX idx_admin_privacy_request_subject(subject_user_id,status,updated_at,id),
  INDEX idx_admin_privacy_request_account(account_id,status,updated_at,id),
  INDEX idx_admin_privacy_request_assignee(assigned_user_id,status,due_at,id),
  CONSTRAINT fk_admin_privacy_subject FOREIGN KEY(subject_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_privacy_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_privacy_assignee FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_privacy_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_admin_privacy_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_privacy_request_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  request_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_privacy_event_request(request_id,created_at,id),
  INDEX idx_admin_privacy_event_actor(actor_user_id,created_at,id),
  CONSTRAINT fk_admin_privacy_event_request FOREIGN KEY(request_id) REFERENCES admin_privacy_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_privacy_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_exports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  requested_by_user_id BIGINT UNSIGNED NOT NULL,
  export_format ENUM('csv','json') NOT NULL,
  filters_json JSON NOT NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  content_sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_audit_export_actor(requested_by_user_id,created_at,id),
  INDEX idx_admin_audit_export_hash(content_sha256,created_at,id),
  CONSTRAINT fk_admin_audit_export_actor FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_roles(public_id,role_key,name,description,capabilities_json,is_system,status)
VALUES(
  'ar-security-compliance-admin',
  'security_compliance_admin',
  'Security & Compliance Admin',
  'Security posture, administrative audit, security cases, privacy workflows and audited evidence exports without implicit source-domain mutation authority.',
  JSON_ARRAY(
    'admin.operations.view',
    'admin.security.view','admin.security.manage','admin.security.export',
    'admin.privacy.view','admin.privacy.manage',
    'admin.roles.view','admin.actions.view','admin.accounts.view','admin.trust.view'
  ),
  1,'active'
)
ON DUPLICATE KEY UPDATE
  name=VALUES(name),
  description=VALUES(description),
  capabilities_json=VALUES(capabilities_json),
  is_system=1,
  status='active';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.operations.manage',
  'admin.support.view','admin.support.manage',
  'admin.customer_success.view',
  'admin.security.view','admin.privacy.view',
  'admin.actions.view','admin.actions.request','admin.actions.approve','admin.actions.execute',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.finance.view',
  'admin.models.view','admin.research_data.view','admin.trust.view'
)
WHERE role_key='operations_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.support.view',
  'admin.customer_success.view',
  'admin.security.view','admin.privacy.view',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.finance.view',
  'admin.models.view','admin.research_data.view','admin.trust.view',
  'admin.actions.view','admin.roles.view'
)
WHERE role_key='read_only_auditor';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.operations.manage',
  'admin.security.view',
  'admin.trust.view','admin.trust.manage',
  'admin.actions.view'
)
WHERE role_key='trust_ops_admin';
