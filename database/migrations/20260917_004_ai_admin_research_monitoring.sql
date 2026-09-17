-- Annotated V1 beta AI providers, admin intelligence, source monitoring, moderation, and richer research.
ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_tier ENUM('free','pro') NOT NULL DEFAULT 'free' AFTER role;
ALTER TABLE users ADD COLUMN IF NOT EXISTS pro_expires_at DATETIME NULL AFTER plan_tier;
ALTER TABLE research_projects ADD COLUMN IF NOT EXISTS status ENUM('active','archived') NOT NULL DEFAULT 'active' AFTER description;
ALTER TABLE research_projects ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE sources ADD COLUMN IF NOT EXISTS monitoring_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status;
ALTER TABLE sources ADD COLUMN IF NOT EXISTS next_check_at DATETIME NULL AFTER last_checked_at;
ALTER TABLE rights_claims ADD COLUMN IF NOT EXISTS moderator_note TEXT NULL AFTER status;
ALTER TABLE rights_claims ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL AFTER created_at;

CREATE TABLE IF NOT EXISTS ai_providers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  label VARCHAR(120) NOT NULL,
  provider_type ENUM('openai','anthropic','gemini','openai_compatible') NOT NULL,
  api_base_url VARCHAR(500) NULL,
  api_key_ciphertext TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_models (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  provider_id BIGINT UNSIGNED NOT NULL,
  model_name VARCHAR(190) NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  admin_enabled TINYINT(1) NOT NULL DEFAULT 1,
  pro_enabled TINYINT(1) NOT NULL DEFAULT 0,
  max_output_tokens INT UNSIGNED NOT NULL DEFAULT 2048,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_model_provider(provider_id,model_name),
  INDEX idx_ai_model_access(enabled,admin_enabled,pro_enabled),
  CONSTRAINT fk_ai_model_provider FOREIGN KEY(provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  admin_default_model_id BIGINT UNSIGNED NULL,
  pro_default_model_id BIGINT UNSIGNED NULL,
  source_monitor_model_id BIGINT UNSIGNED NULL,
  moderation_model_id BIGINT UNSIGNED NULL,
  research_model_id BIGINT UNSIGNED NULL,
  transcript_cleanup_model_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_setting_admin FOREIGN KEY(admin_default_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_setting_pro FOREIGN KEY(pro_default_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_setting_source FOREIGN KEY(source_monitor_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_setting_moderation FOREIGN KEY(moderation_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_setting_research FOREIGN KEY(research_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_setting_transcript FOREIGN KEY(transcript_cleanup_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_setting_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ai_settings(id) VALUES(1);

CREATE TABLE IF NOT EXISTS ai_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NULL,
  initiated_by ENUM('system','admin','pro') NOT NULL,
  task_type VARCHAR(80) NOT NULL,
  model_id BIGINT UNSIGNED NULL,
  prompt_version VARCHAR(40) NOT NULL DEFAULT 'v1',
  scope_type VARCHAR(64) NULL,
  scope_public_id VARCHAR(64) NULL,
  input_refs_json JSON NULL,
  output_text MEDIUMTEXT NULL,
  status ENUM('queued','processing','completed','failed','blocked') NOT NULL DEFAULT 'processing',
  error_text VARCHAR(1000) NULL,
  input_tokens INT UNSIGNED NULL,
  output_tokens INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_ai_runs_user(user_id,created_at),
  INDEX idx_ai_runs_task(task_type,status,created_at),
  CONSTRAINT fk_ai_run_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_run_model FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  requested_by_user_id BIGINT UNSIGNED NULL,
  task_type VARCHAR(80) NOT NULL,
  model_id BIGINT UNSIGNED NULL,
  object_type VARCHAR(64) NULL,
  object_public_id VARCHAR(64) NULL,
  input_json JSON NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status ENUM('queued','processing','done','failed','blocked') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_ai_jobs_status(status,priority,created_at),
  CONSTRAINT fk_ai_job_user FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_job_model FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_sources (
  project_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(project_id,source_id),
  CONSTRAINT fk_project_source_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_source_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_source_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_note_project(project_id,created_at),
  CONSTRAINT fk_research_note_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_note_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  task_type ENUM('general','find_source','verify_claim','review_source_change') NOT NULL DEFAULT 'general',
  status ENUM('open','in_progress','done','archived') NOT NULL DEFAULT 'open',
  due_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_task_project(project_id,status,created_at),
  CONSTRAINT fk_research_task_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_task_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_task_assignee FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moderation_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  reported_by_user_id BIGINT UNSIGNED NULL,
  object_type ENUM('annotation','comment','live_message','user') NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  reason VARCHAR(80) NOT NULL,
  description TEXT NULL,
  status ENUM('open','under_review','actioned','dismissed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  INDEX idx_moderation_report_status(status,created_at),
  CONSTRAINT fk_moderation_report_user FOREIGN KEY(reported_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moderation_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id BIGINT UNSIGNED NULL,
  rights_claim_id BIGINT UNSIGNED NULL,
  moderator_user_id BIGINT UNSIGNED NOT NULL,
  action_type VARCHAR(80) NOT NULL,
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_moderation_action_report FOREIGN KEY(report_id) REFERENCES moderation_reports(id) ON DELETE SET NULL,
  CONSTRAINT fk_moderation_action_claim FOREIGN KEY(rights_claim_id) REFERENCES rights_claims(id) ON DELETE SET NULL,
  CONSTRAINT fk_moderation_action_user FOREIGN KEY(moderator_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_monitor_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_source_monitor_status(status,priority,scheduled_at),
  CONSTRAINT fk_source_monitor_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
