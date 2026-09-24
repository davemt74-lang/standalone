-- Admin V2.20 — Support Operations & Customer Service Workspace

CREATE TABLE IF NOT EXISTS admin_support_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  duplicate_of_case_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'general',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status ENUM('open','assigned','waiting_customer','waiting_internal','escalated','resolved','closed') NOT NULL DEFAULT 'open',
  source ENUM('admin','customer','system','alert') NOT NULL DEFAULT 'admin',
  escalation_team VARCHAR(80) NULL,
  escalation_reason VARCHAR(500) NULL,
  resolution_summary VARCHAR(1000) NULL,
  sla_due_at DATETIME NULL,
  first_response_at DATETIME NULL,
  last_customer_activity_at DATETIME NULL,
  last_operator_activity_at DATETIME NULL,
  resolved_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_support_case_queue(status,priority,sla_due_at,updated_at,id),
  INDEX idx_support_case_assignee(assigned_user_id,status,updated_at,id),
  INDEX idx_support_case_account(account_id,status,updated_at,id),
  INDEX idx_support_case_user(user_id,status,updated_at,id),
  INDEX idx_support_case_category(category,status,updated_at,id),
  INDEX idx_support_case_duplicate(duplicate_of_case_id),
  CONSTRAINT fk_support_case_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_support_case_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_support_case_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_support_case_assignee FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_support_case_duplicate FOREIGN KEY(duplicate_of_case_id) REFERENCES admin_support_cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_support_case_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  case_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  visibility ENUM('internal','customer') NOT NULL DEFAULT 'internal',
  body TEXT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_support_event_case(case_id,created_at,id),
  INDEX idx_support_event_actor(actor_user_id,created_at,id),
  INDEX idx_support_event_type(event_type,created_at,id),
  CONSTRAINT fk_support_event_case FOREIGN KEY(case_id) REFERENCES admin_support_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_support_case_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  case_id BIGINT UNSIGNED NOT NULL,
  link_type VARCHAR(80) NOT NULL,
  linked_public_id VARCHAR(255) NOT NULL,
  label VARCHAR(255) NULL,
  target_url VARCHAR(500) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_support_case_link(case_id,link_type,linked_public_id),
  INDEX idx_support_link_target(link_type,linked_public_id),
  CONSTRAINT fk_support_link_case FOREIGN KEY(case_id) REFERENCES admin_support_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_link_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_support_saved_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  filters_json JSON NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_support_saved_view(user_id,name),
  INDEX idx_support_saved_view_user(user_id,is_default,updated_at,id),
  CONSTRAINT fk_support_saved_view_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view',
  'admin.support.view','admin.support.manage',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.actions.view'
)
WHERE role_key='support_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.operations.manage',
  'admin.support.view','admin.support.manage',
  'admin.actions.view','admin.actions.request','admin.actions.approve','admin.actions.execute',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.models.view','admin.research_data.view','admin.trust.view'
)
WHERE role_key='operations_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.support.view',
  'admin.accounts.view','admin.billing.view','admin.ai_usage.view',
  'admin.models.view','admin.research_data.view','admin.trust.view',
  'admin.actions.view','admin.roles.view'
)
WHERE role_key='read_only_auditor';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.support.view',
  'admin.accounts.view','admin.billing.view','admin.billing.manage',
  'admin.billing.sync','admin.billing.overage','admin.billing.tax','admin.ai_usage.view',
  'admin.actions.view','admin.actions.request','admin.actions.approve','admin.actions.execute'
)
WHERE role_key='billing_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.support.view',
  'admin.ai_usage.view','admin.ai_usage.manage','admin.models.view','admin.models.manage',
  'admin.actions.view'
)
WHERE role_key='ai_models_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.support.view',
  'admin.research_data.view','admin.research_data.manage','admin.actions.view'
)
WHERE role_key='research_data_admin';

UPDATE admin_roles
SET capabilities_json=JSON_ARRAY(
  'admin.operations.view','admin.operations.manage','admin.support.view',
  'admin.trust.view','admin.trust.manage','admin.actions.view'
)
WHERE role_key='trust_ops_admin';
