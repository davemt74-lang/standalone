-- Admin V2.10 — Roles, Delegated Permissions & Approval Policies
-- Keeps users.role='admin' as the authentication boundary while enforcing scoped operator capabilities inside Admin.

CREATE TABLE IF NOT EXISTS admin_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  role_key VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  capabilities_json JSON NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_roles_status(status,is_system,name,id),
  CONSTRAINT fk_admin_role_created_by FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_role_updated_by FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_roles(public_id,role_key,name,description,capabilities_json,is_system,status)
VALUES
('ar-super-admin','super_admin','Super Admin','Full Admin authority. Protected system role.',JSON_ARRAY('admin.*'),1,'active'),
('ar-operations-admin','operations_admin','Operations Admin','Command Center, alerts, governed actions, and read access across operational domains.',JSON_ARRAY('admin.operations.view','admin.operations.manage','admin.actions.view','admin.actions.request','admin.actions.approve','admin.actions.execute','admin.accounts.view','admin.billing.view','admin.ai_usage.view','admin.models.view','admin.research_data.view','admin.trust.view'),1,'active'),
('ar-billing-admin','billing_admin','Billing Admin','Accounts and commercial billing operations, including governed billing actions.',JSON_ARRAY('admin.operations.view','admin.accounts.view','admin.billing.view','admin.billing.manage','admin.billing.sync','admin.billing.overage','admin.billing.tax','admin.ai_usage.view','admin.actions.view','admin.actions.request','admin.actions.approve','admin.actions.execute'),1,'active'),
('ar-support-admin','support_admin','Support Admin','Account support and operational queue management without billing or model mutation authority.',JSON_ARRAY('admin.operations.view','admin.operations.manage','admin.accounts.view','admin.accounts.manage','admin.actions.view'),1,'active'),
('ar-ai-models-admin','ai_models_admin','AI & Models Admin','AI usage and model lifecycle administration.',JSON_ARRAY('admin.operations.view','admin.ai_usage.view','admin.ai_usage.manage','admin.models.view','admin.models.manage','admin.actions.view'),1,'active'),
('ar-research-data-admin','research_data_admin','Research & Data Admin','Research infrastructure, sources, datasets, and discovery administration.',JSON_ARRAY('admin.operations.view','admin.research_data.view','admin.research_data.manage','admin.actions.view'),1,'active'),
('ar-trust-ops-admin','trust_ops_admin','Trust & Operations Admin','Moderation, system health, release audit, and operational queue administration.',JSON_ARRAY('admin.operations.view','admin.operations.manage','admin.trust.view','admin.trust.manage','admin.actions.view'),1,'active'),
('ar-read-only-auditor','read_only_auditor','Read-only Auditor','Read-only access to Admin operational, commercial, model, research, trust, action, and role evidence.',JSON_ARRAY('admin.operations.view','admin.accounts.view','admin.billing.view','admin.ai_usage.view','admin.models.view','admin.research_data.view','admin.trust.view','admin.actions.view','admin.roles.view'),1,'active')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),capabilities_json=VALUES(capabilities_json),is_system=VALUES(is_system),status=VALUES(status);

ALTER TABLE admin_operator_profiles
  ADD COLUMN role_id BIGINT UNSIGNED NULL AFTER user_id,
  ADD COLUMN assigned_by_user_id BIGINT UNSIGNED NULL AFTER status,
  ADD COLUMN assigned_at DATETIME NULL AFTER assigned_by_user_id,
  ADD INDEX idx_admin_operator_role_id(role_id,status),
  ADD INDEX idx_admin_operator_assigned_by(assigned_by_user_id,assigned_at),
  ADD CONSTRAINT fk_admin_operator_role FOREIGN KEY(role_id) REFERENCES admin_roles(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_admin_operator_assigned_by FOREIGN KEY(assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

UPDATE admin_operator_profiles p
JOIN admin_roles r ON r.role_key=p.role_key
SET p.role_id=r.id,p.assigned_at=COALESCE(p.assigned_at,p.created_at)
WHERE p.role_id IS NULL;

CREATE TABLE IF NOT EXISTS admin_approval_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  action_type VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  risk_level ENUM('routine','elevated','high') NOT NULL DEFAULT 'elevated',
  required_approvals TINYINT UNSIGNED NOT NULL DEFAULT 1,
  distinct_from_requester TINYINT(1) NOT NULL DEFAULT 1,
  approver_capability VARCHAR(128) NOT NULL DEFAULT 'admin.actions.approve',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_approval_policy_status(status,risk_level,action_type),
  CONSTRAINT fk_admin_approval_policy_created_by FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_approval_policy_updated_by FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_approval_policies(public_id,action_type,name,risk_level,required_approvals,distinct_from_requester,approver_capability,status)
VALUES
('aap-resync-stripe','resync_stripe_subscription','Stripe subscription resync','routine',0,0,'admin.actions.approve','active'),
('aap-ai-overage','reconcile_ai_overage','AI overage reconciliation','elevated',1,1,'admin.actions.approve','active'),
('aap-tax-policy','sync_tax_policy','Billing profile and tax-policy synchronization','elevated',1,1,'admin.actions.approve','active')
ON DUPLICATE KEY UPDATE name=VALUES(name),risk_level=VALUES(risk_level),required_approvals=VALUES(required_approvals),distinct_from_requester=VALUES(distinct_from_requester),approver_capability=VALUES(approver_capability),status=VALUES(status);

ALTER TABLE admin_action_records
  MODIFY COLUMN status ENUM('previewed','pending_approval','approved','executing','executed','failed','cancelled','rejected') NOT NULL DEFAULT 'previewed',
  ADD COLUMN approval_policy_id BIGINT UNSIGNED NULL AFTER risk_level,
  ADD COLUMN required_approvals TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER approval_policy_id,
  ADD COLUMN approval_requested_at DATETIME NULL AFTER previewed_at,
  ADD COLUMN approved_at DATETIME NULL AFTER approval_requested_at,
  ADD COLUMN rejected_at DATETIME NULL AFTER approved_at,
  ADD INDEX idx_admin_action_approval(status,required_approvals,approval_requested_at,id),
  ADD CONSTRAINT fk_admin_action_approval_policy FOREIGN KEY(approval_policy_id) REFERENCES admin_approval_policies(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS admin_action_approvals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  action_record_id BIGINT UNSIGNED NOT NULL,
  approver_user_id BIGINT UNSIGNED NOT NULL,
  decision ENUM('approved','rejected') NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_action_approver(action_record_id,approver_user_id),
  INDEX idx_admin_action_approval_record(action_record_id,decision,created_at,id),
  INDEX idx_admin_action_approval_actor(approver_user_id,created_at,id),
  CONSTRAINT fk_admin_action_approval_record FOREIGN KEY(action_record_id) REFERENCES admin_action_records(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_action_approval_actor FOREIGN KEY(approver_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_security_audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  subject_type ENUM('role','operator','approval_policy','action') NOT NULL,
  subject_public_id VARCHAR(64) NULL,
  event_type VARCHAR(100) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_security_audit_actor(actor_user_id,created_at,id),
  INDEX idx_admin_security_audit_subject(subject_type,subject_public_id,created_at,id),
  INDEX idx_admin_security_audit_type(event_type,created_at,id),
  CONSTRAINT fk_admin_security_audit_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
