-- Admin V2.30 — Financial Reporting & Reconciliation

CREATE TABLE IF NOT EXISTS admin_finance_reconciliation_exceptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  stripe_mode VARCHAR(16) NOT NULL,
  account_id BIGINT UNSIGNED NULL,
  exception_key VARCHAR(190) NOT NULL,
  exception_type VARCHAR(80) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  status ENUM('open','acknowledged','resolved','ignored') NOT NULL DEFAULT 'open',
  source_type VARCHAR(80) NOT NULL,
  source_public_id VARCHAR(255) NULL,
  title VARCHAR(255) NOT NULL,
  detail TEXT NULL,
  expected_json JSON NULL,
  observed_json JSON NULL,
  delta_cents BIGINT NOT NULL DEFAULT 0,
  assigned_user_id BIGINT UNSIGNED NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolution_reason VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_finance_exception_mode_key(stripe_mode,exception_key),
  INDEX idx_finance_exception_queue(stripe_mode,status,severity,last_seen_at,id),
  INDEX idx_finance_exception_account(account_id,status,last_seen_at,id),
  INDEX idx_finance_exception_assignee(assigned_user_id,status,last_seen_at,id),
  CONSTRAINT fk_finance_exception_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_finance_exception_assignee FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_finance_reconciliation_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  exception_id BIGINT UNSIGNED NULL,
  account_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_finance_recon_event_exception(exception_id,created_at,id),
  INDEX idx_finance_recon_event_account(account_id,created_at,id),
  INDEX idx_finance_recon_event_actor(actor_user_id,created_at,id),
  CONSTRAINT fk_finance_recon_event_exception FOREIGN KEY(exception_id) REFERENCES admin_finance_reconciliation_exceptions(id) ON DELETE SET NULL,
  CONSTRAINT fk_finance_recon_event_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_finance_recon_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_finance_period_closes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  stripe_mode VARCHAR(16) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  metrics_json JSON NOT NULL,
  metrics_sha256 CHAR(64) NOT NULL,
  closed_by_user_id BIGINT UNSIGNED NOT NULL,
  close_reason VARCHAR(1000) NOT NULL,
  closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_finance_period_close(stripe_mode,period_start,period_end),
  INDEX idx_finance_period_close_date(stripe_mode,period_end,id),
  CONSTRAINT fk_finance_period_close_actor FOREIGN KEY(closed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_finance_exports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  stripe_mode VARCHAR(16) NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NOT NULL,
  export_type VARCHAR(80) NOT NULL,
  filters_json JSON NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  content_sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_finance_export_actor(requested_by_user_id,created_at,id),
  INDEX idx_finance_export_type(stripe_mode,export_type,created_at,id),
  CONSTRAINT fk_finance_export_actor FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.support.view',
  'admin.accounts.view','admin.billing.view','admin.billing.manage',
  'admin.billing.sync','admin.billing.overage','admin.billing.tax',
  'admin.ai_usage.view',
  'admin.finance.view','admin.finance.manage','admin.finance.export',
  'admin.actions.view','admin.actions.request','admin.actions.approve','admin.actions.execute'
)
WHERE role_key='billing_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.operations.manage',
  'admin.support.view','admin.support.manage',
  'admin.actions.view','admin.actions.request','admin.actions.approve','admin.actions.execute',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.finance.view',
  'admin.models.view','admin.research_data.view','admin.trust.view'
)
WHERE role_key='operations_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.support.view',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.finance.view',
  'admin.models.view','admin.research_data.view','admin.trust.view',
  'admin.actions.view','admin.roles.view'
)
WHERE role_key='read_only_auditor';
