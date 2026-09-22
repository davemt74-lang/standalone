-- Annotated Phase 42 — Post-Training Evaluation & Promotion Readiness
-- Orchestrates Phase 39 evaluation for successful Phase 41 outputs and freezes
-- human-reviewable readiness packets. No automatic Phase 40 promotion or activation.

CREATE TABLE IF NOT EXISTS data_post_training_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  training_job_id BIGINT UNSIGNED NOT NULL,
  output_model_version_id BIGINT UNSIGNED NOT NULL,
  baseline_model_version_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  policy_json JSON NOT NULL,
  policy_hash CHAR(64) NOT NULL,
  training_completion_hash CHAR(64) NOT NULL,
  output_version_hash CHAR(64) NOT NULL,
  baseline_version_hash CHAR(64) NOT NULL,
  baseline_approval_receipt_hash CHAR(64) NOT NULL,
  plan_hash CHAR(64) NULL,
  last_error VARCHAR(1500) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  prepared_at DATETIME NULL,
  ready_at DATETIME NULL,
  blocked_at DATETIME NULL,
  archived_at DATETIME NULL,
  UNIQUE KEY uq_post_training_job(training_job_id),
  INDEX idx_post_training_status(status,updated_at),
  INDEX idx_post_training_output(output_model_version_id,status),
  CONSTRAINT fk_post_training_job FOREIGN KEY(training_job_id) REFERENCES data_training_jobs(id) ON DELETE RESTRICT,
  CONSTRAINT fk_post_training_output FOREIGN KEY(output_model_version_id) REFERENCES data_model_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_post_training_baseline FOREIGN KEY(baseline_model_version_id) REFERENCES data_model_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_post_training_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_post_training_suite_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  template_suite_id BIGINT UNSIGNED NOT NULL,
  candidate_suite_id BIGINT UNSIGNED NULL,
  baseline_run_id BIGINT UNSIGNED NOT NULL,
  candidate_run_id BIGINT UNSIGNED NULL,
  benchmark_fingerprint CHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'selected',
  comparison_json JSON NULL,
  comparison_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_post_training_template(plan_id,template_suite_id),
  INDEX idx_post_training_candidate_run(candidate_run_id),
  CONSTRAINT fk_post_training_link_plan FOREIGN KEY(plan_id) REFERENCES data_post_training_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_post_training_template_suite FOREIGN KEY(template_suite_id) REFERENCES data_evaluation_suites(id) ON DELETE RESTRICT,
  CONSTRAINT fk_post_training_candidate_suite FOREIGN KEY(candidate_suite_id) REFERENCES data_evaluation_suites(id) ON DELETE RESTRICT,
  CONSTRAINT fk_post_training_baseline_run FOREIGN KEY(baseline_run_id) REFERENCES data_evaluation_runs(id) ON DELETE RESTRICT,
  CONSTRAINT fk_post_training_candidate_run FOREIGN KEY(candidate_run_id) REFERENCES data_evaluation_runs(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_post_training_packets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  plan_id BIGINT UNSIGNED NOT NULL,
  sequence_number INT UNSIGNED NOT NULL,
  readiness_state VARCHAR(32) NOT NULL DEFAULT 'ready_for_human_consideration',
  packet_json JSON NOT NULL,
  packet_hash CHAR(64) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_post_training_packet_sequence(plan_id,sequence_number),
  INDEX idx_post_training_packet_plan(plan_id,created_at),
  CONSTRAINT fk_post_training_packet_plan FOREIGN KEY(plan_id) REFERENCES data_post_training_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_post_training_packet_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_post_training_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_post_training_event_plan(plan_id,created_at),
  CONSTRAINT fk_post_training_event_plan FOREIGN KEY(plan_id) REFERENCES data_post_training_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_post_training_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
