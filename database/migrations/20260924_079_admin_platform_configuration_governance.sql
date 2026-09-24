-- Admin V2.60 — Platform Configuration, Feature Governance & Release Operations
-- Adds governance metadata and rollout controls over existing release/configuration authorities.

CREATE TABLE IF NOT EXISTS admin_platform_features (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  feature_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(1000) NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'platform',
  lifecycle_status ENUM('draft','pilot','active','paused','retired') NOT NULL DEFAULT 'active',
  enforcement_mode ENUM('observe','enforce') NOT NULL DEFAULT 'observe',
  default_enabled TINYINT(1) NOT NULL DEFAULT 1,
  rollout_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
  packages_json JSON NULL,
  accounts_json JSON NULL,
  dependencies_json JSON NULL,
  owner_note VARCHAR(255) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_platform_feature_state(lifecycle_status,enforcement_mode,category,updated_at,id),
  CONSTRAINT fk_admin_platform_feature_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_platform_feature_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_platform_modules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  module_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(1000) NULL,
  desired_state ENUM('enabled','maintenance','disabled') NOT NULL DEFAULT 'enabled',
  version_label VARCHAR(80) NULL,
  health_source VARCHAR(100) NULL,
  dependencies_json JSON NULL,
  owner_note VARCHAR(255) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_platform_module_state(desired_state,updated_at,id),
  CONSTRAINT fk_admin_platform_module_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_platform_module_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_platform_integrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  integration_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  provider VARCHAR(100) NOT NULL,
  category VARCHAR(80) NOT NULL,
  config_group VARCHAR(100) NULL,
  desired_state ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
  secret_policy ENUM('external_config_only','none') NOT NULL DEFAULT 'external_config_only',
  owner_note VARCHAR(255) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_platform_integration_state(category,desired_state,updated_at,id),
  CONSTRAINT fk_admin_platform_integration_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_platform_integration_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_platform_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  snapshot_type ENUM('configuration','release_readiness','drift_baseline') NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  snapshot_json JSON NOT NULL,
  source_build_sha CHAR(40) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_platform_snapshot_type(snapshot_type,created_at,id),
  INDEX idx_admin_platform_snapshot_fingerprint(fingerprint,created_at,id),
  CONSTRAINT fk_admin_platform_snapshot_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_platform_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  subject_type VARCHAR(80) NOT NULL,
  subject_public_id VARCHAR(255) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(1000) NOT NULL,
  correlation_id VARCHAR(96) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_platform_event_type(event_type,created_at,id),
  INDEX idx_admin_platform_event_subject(subject_type,subject_public_id,created_at,id),
  INDEX idx_admin_platform_event_actor(actor_user_id,created_at,id),
  INDEX idx_admin_platform_event_correlation(correlation_id,created_at,id),
  CONSTRAINT fk_admin_platform_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_platform_features(public_id,feature_key,name,description,category,lifecycle_status,enforcement_mode,default_enabled,rollout_percent,dependencies_json)
VALUES
('apf-research-workspace','research_workspace','Research Workspace','Research projects, workspace, retrieval and governed research workflows.','research','active','observe',1,100,JSON_ARRAY()),
('apf-agent-chat','agent_chat','Agent Chat','Annotated Agent Chat and read-only administrative context surfaces.','ai','active','observe',1,100,JSON_ARRAY()),
('apf-customer-success','customer_success','Customer Success','Admin Customer Success portfolio, health and retention workflows.','admin','active','observe',1,100,JSON_ARRAY('accounts')),
('apf-security-compliance','security_compliance','Security & Compliance','Security audit, cases, privacy workflows and permission review.','admin','active','observe',1,100,JSON_ARRAY('admin_access')),
('apf-browser-extension','browser_extension','Browser Extension','Annotated Chrome extension pairing and browser capture surfaces.','capture','active','observe',1,100,JSON_ARRAY()),
('apf-vp3-connector','vp3_connector','VP3 Connector','VP3 account connection and research ingestion integration.','integration','active','observe',1,100,JSON_ARRAY())
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT INTO admin_platform_modules(public_id,module_key,name,description,desired_state,version_label,health_source,dependencies_json)
VALUES
('apm-core','core','Annotated Core','Core application, accounts, annotations and social surfaces.','enabled','V1.1','release',JSON_ARRAY()),
('apm-research','research','Research Runtime','Research workspace, retrieval, monitoring, tasks, programs and intelligence.','enabled','V1.1','workers',JSON_ARRAY('core')),
('apm-ai','ai','AI Runtime','AI providers, usage accounting, Agent Chat and model operations.','enabled','V1.1','workers',JSON_ARRAY('core')),
('apm-commerce','commerce','Commercial Runtime','Accounts, subscriptions, Stripe, billing, finance and promotions.','enabled','V1.1','billing',JSON_ARRAY('core')),
('apm-admin','admin','Admin Control Plane','Admin operations, access, support, finance, success, security and platform governance.','enabled','V2.60','release',JSON_ARRAY('core')),
('apm-extension','extension','Chrome Extension','Manifest V3 browser companion and capture client.','enabled','0.36.0','extension',JSON_ARRAY('core'))
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),version_label=VALUES(version_label),health_source=VALUES(health_source),dependencies_json=VALUES(dependencies_json);

INSERT INTO admin_platform_integrations(public_id,integration_key,name,provider,category,config_group,desired_state,secret_policy)
VALUES
('api-stripe','stripe','Stripe','Stripe','billing','stripe','enabled','external_config_only'),
('api-openai','openai','OpenAI','OpenAI','ai','openai','enabled','external_config_only'),
('api-anthropic','anthropic','Anthropic','Anthropic','ai','anthropic','enabled','external_config_only'),
('api-elevenlabs','elevenlabs','ElevenLabs','ElevenLabs','voice','elevenlabs','enabled','external_config_only'),
('api-google-oauth','google_oauth','Google OAuth','Google','identity','oauth.google','enabled','external_config_only'),
('api-x-oauth','x_oauth','X OAuth','X','identity','oauth.x','enabled','external_config_only'),
('api-vp3','vp3','VP3','VP3','platform','vp3','enabled','external_config_only'),
('api-mail','mail','Outbound Mail','Configured mail provider','communications','mail','enabled','external_config_only')
ON DUPLICATE KEY UPDATE name=VALUES(name),provider=VALUES(provider),category=VALUES(category),config_group=VALUES(config_group),secret_policy=VALUES(secret_policy);

INSERT INTO admin_approval_policies(public_id,action_type,name,risk_level,required_approvals,distinct_from_requester,approver_capability,status)
VALUES
('aap-platform-feature','change_platform_feature','Platform feature rollout change','elevated',1,1,'admin.actions.approve','active')
ON DUPLICATE KEY UPDATE name=VALUES(name),risk_level=VALUES(risk_level),required_approvals=VALUES(required_approvals),distinct_from_requester=VALUES(distinct_from_requester),approver_capability=VALUES(approver_capability),status=VALUES(status);

INSERT INTO admin_roles(public_id,role_key,name,description,capabilities_json,is_system,status)
VALUES(
  'ar-platform-operations-admin',
  'platform_operations_admin',
  'Platform Operations Admin',
  'Platform configuration visibility, feature governance, integration health and release readiness with governed feature changes.',
  JSON_ARRAY(
    'admin.operations.view',
    'admin.platform.view','admin.platform.manage','admin.platform.release',
    'admin.trust.view','admin.security.view',
    'admin.actions.view','admin.actions.request','admin.actions.execute'
  ),
  1,'active'
)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),capabilities_json=VALUES(capabilities_json),is_system=1,status='active';

UPDATE admin_roles
SET capabilities_json=CASE
  WHEN JSON_CONTAINS(capabilities_json,JSON_QUOTE('admin.platform.view'),'$') THEN capabilities_json
  ELSE JSON_ARRAY_APPEND(capabilities_json,'$','admin.platform.view')
END
WHERE role_key IN ('operations_admin','read_only_auditor','trust_ops_admin');

UPDATE admin_roles
SET capabilities_json=CASE
  WHEN JSON_CONTAINS(capabilities_json,JSON_QUOTE('admin.platform.release'),'$') THEN capabilities_json
  ELSE JSON_ARRAY_APPEND(capabilities_json,'$','admin.platform.release')
END
WHERE role_key IN ('operations_admin','trust_ops_admin');
