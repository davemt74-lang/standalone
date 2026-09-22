-- Annotated Phase 47 — Model Improvement Campaigns & Controlled Retraining Handoff
-- Orchestrates approved Phase 46 evidence through existing dataset/training/evaluation/release systems.
-- No automatic freeze, evaluation run, training queue/submission, promotion, deployment, rollback, or routing change.

CREATE TABLE IF NOT EXISTS data_model_improvement_campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  registry_id BIGINT UNSIGNED NOT NULL,
  base_model_version_id BIGINT UNSIGNED NOT NULL,
  strategy VARCHAR(48) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'planned',
  title VARCHAR(255) NOT NULL,
  objective VARCHAR(5000) NOT NULL,
  success_criteria VARCHAR(5000) NOT NULL,
  target_version_label VARCHAR(120) NULL,
  evaluation_dataset_id BIGINT UNSIGNED NULL,
  training_dataset_id BIGINT UNSIGNED NULL,
  baseline_evaluation_suite_id BIGINT UNSIGNED NULL,
  training_job_id BIGINT UNSIGNED NULL,
  post_training_plan_id BIGINT UNSIGNED NULL,
  plan_json JSON NULL,
  plan_hash CHAR(64) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  locked_by_user_id BIGINT UNSIGNED NULL,
  closed_by_user_id BIGINT UNSIGNED NULL,
  locked_at DATETIME NULL,
  closed_at DATETIME NULL,
  close_note VARCHAR(5000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_model_campaign_status(status,updated_at),
  INDEX idx_model_campaign_registry(registry_id,status),
  INDEX idx_model_campaign_base(base_model_version_id,status),
  CONSTRAINT fk_model_campaign_registry FOREIGN KEY(registry_id) REFERENCES data_model_registry(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_campaign_base FOREIGN KEY(base_model_version_id) REFERENCES data_model_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_campaign_eval_dataset FOREIGN KEY(evaluation_dataset_id) REFERENCES data_datasets(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_campaign_training_dataset FOREIGN KEY(training_dataset_id) REFERENCES data_datasets(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_campaign_baseline_suite FOREIGN KEY(baseline_evaluation_suite_id) REFERENCES data_evaluation_suites(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_campaign_training_job FOREIGN KEY(training_job_id) REFERENCES data_training_jobs(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_campaign_post_plan FOREIGN KEY(post_training_plan_id) REFERENCES data_post_training_plans(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_campaign_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_campaign_locker FOREIGN KEY(locked_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_campaign_closer FOREIGN KEY(closed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_improvement_campaign_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  improvement_case_id BIGINT UNSIGNED NOT NULL,
  baseline_recurrence_count INT UNSIGNED NOT NULL DEFAULT 0,
  baseline_evidence_count INT UNSIGNED NOT NULL DEFAULT 0,
  baseline_evidence_hash CHAR(64) NOT NULL,
  baseline_incident_metric VARCHAR(64) NULL,
  baseline_incident_value DECIMAL(18,6) NULL,
  baseline_incident_threshold DECIMAL(18,6) NULL,
  baseline_captured_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_campaign_case(campaign_id,improvement_case_id),
  INDEX idx_model_campaign_case_case(improvement_case_id,campaign_id),
  CONSTRAINT fk_model_campaign_case_campaign FOREIGN KEY(campaign_id) REFERENCES data_model_improvement_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_campaign_case_improvement FOREIGN KEY(improvement_case_id) REFERENCES data_model_improvement_cases(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_improvement_campaign_proposals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  proposal_id BIGINT UNSIGNED NOT NULL,
  proposal_type VARCHAR(32) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  approval_hash CHAR(64) NOT NULL,
  corpus_item_public_id VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_campaign_proposal(campaign_id,proposal_id),
  INDEX idx_model_campaign_proposal_proposal(proposal_id,campaign_id),
  CONSTRAINT fk_model_campaign_proposal_campaign FOREIGN KEY(campaign_id) REFERENCES data_model_improvement_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_campaign_proposal_proposal FOREIGN KEY(proposal_id) REFERENCES data_model_improvement_proposals(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_improvement_campaign_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  details_json JSON NULL,
  event_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_campaign_event(campaign_id,created_at),
  CONSTRAINT fk_model_campaign_event_campaign FOREIGN KEY(campaign_id) REFERENCES data_model_improvement_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_campaign_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
