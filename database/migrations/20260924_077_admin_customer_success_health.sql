-- Admin V2.40 — Customer Success, Account Health & Retention

CREATE TABLE IF NOT EXISTS admin_customer_success_assignments (
  account_id BIGINT UNSIGNED PRIMARY KEY,
  owner_user_id BIGINT UNSIGNED NULL,
  assigned_by_user_id BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_success_owner(owner_user_id,updated_at,account_id),
  CONSTRAINT fk_customer_success_assignment_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_success_assignment_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_success_assignment_actor FOREIGN KEY(assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_customer_health_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  health_score TINYINT UNSIGNED NOT NULL,
  health_state ENUM('healthy','watch','at_risk','critical') NOT NULL,
  lifecycle_stage ENUM('onboarding','adopting','established','expansion_ready','recovery') NOT NULL,
  signals_json JSON NOT NULL,
  reasons_json JSON NOT NULL,
  opportunities_json JSON NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  source_max_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customer_health_account(account_id,created_at,id),
  INDEX idx_customer_health_state(health_state,lifecycle_stage,created_at,id),
  INDEX idx_customer_health_score(health_score,created_at,id),
  CONSTRAINT fk_customer_health_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_health_actor FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_customer_success_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  objective TEXT NOT NULL,
  status ENUM('active','paused','completed','cancelled') NOT NULL DEFAULT 'active',
  target_date DATE NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NOT NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_success_plan_account(account_id,status,target_date,id),
  INDEX idx_customer_success_plan_owner(owner_user_id,status,target_date,id),
  CONSTRAINT fk_customer_success_plan_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_success_plan_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_success_plan_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_success_plan_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_customer_success_milestones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  plan_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  status ENUM('pending','in_progress','completed','blocked') NOT NULL DEFAULT 'pending',
  due_at DATETIME NULL,
  completed_at DATETIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_success_milestone_plan(plan_id,status,due_at,sort_order,id),
  CONSTRAINT fk_customer_success_milestone_plan FOREIGN KEY(plan_id) REFERENCES admin_customer_success_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_success_milestone_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_success_milestone_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_customer_success_followups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  note TEXT NULL,
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
  due_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_success_followup_due(status,due_at,priority,id),
  INDEX idx_customer_success_followup_account(account_id,status,due_at,id),
  INDEX idx_customer_success_followup_assignee(assigned_user_id,status,due_at,id),
  CONSTRAINT fk_customer_success_followup_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_success_followup_assignee FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_success_followup_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_success_followup_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_customer_success_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  subject_type VARCHAR(80) NULL,
  subject_public_id VARCHAR(255) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customer_success_event_account(account_id,created_at,id),
  INDEX idx_customer_success_event_actor(actor_user_id,created_at,id),
  INDEX idx_customer_success_event_type(event_type,created_at,id),
  CONSTRAINT fk_customer_success_event_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_success_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_roles(public_id,role_key,name,description,capabilities_json,is_system,status)
VALUES(
  'ar-customer-success-admin',
  'customer_success_admin',
  'Customer Success Admin',
  'Customer Success portfolio, health, onboarding, plans and follow-up management with read-only account, support, finance and AI context.',
  JSON_ARRAY(
    'admin.operations.view',
    'admin.customer_success.view','admin.customer_success.manage',
    'admin.accounts.view','admin.support.view','admin.finance.view','admin.ai_usage.view',
    'admin.actions.view'
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
  'admin.actions.view','admin.actions.request','admin.actions.approve','admin.actions.execute',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.finance.view',
  'admin.models.view','admin.research_data.view','admin.trust.view'
)
WHERE role_key='operations_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view',
  'admin.support.view','admin.support.manage',
  'admin.customer_success.view',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.actions.view'
)
WHERE role_key='support_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.support.view',
  'admin.customer_success.view',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.finance.view',
  'admin.models.view','admin.research_data.view','admin.trust.view',
  'admin.actions.view','admin.roles.view'
)
WHERE role_key='read_only_auditor';
