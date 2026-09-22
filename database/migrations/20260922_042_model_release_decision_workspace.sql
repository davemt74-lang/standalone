-- Annotated Phase 43 — Model Release Decision Workspace
-- Human release-decision layer above Phase 42 readiness packets.
-- Does not transition Phase 40 model lifecycle or mutate AI task routing.

CREATE TABLE IF NOT EXISTS data_model_release_decisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  post_training_plan_id BIGINT UNSIGNED NOT NULL,
  readiness_packet_id BIGINT UNSIGNED NOT NULL,
  model_version_id BIGINT UNSIGNED NOT NULL,
  baseline_model_version_id BIGINT UNSIGNED NOT NULL,
  registry_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  outcome VARCHAR(40) NULL,
  title VARCHAR(190) NOT NULL,
  decision_summary TEXT NULL,
  rationale TEXT NULL,
  required_reviewers INT UNSIGNED NOT NULL DEFAULT 1,
  risk_policy_json JSON NOT NULL,
  risk_policy_hash CHAR(64) NOT NULL,
  deployment_plan_json JSON NOT NULL,
  deployment_plan_hash CHAR(64) NOT NULL,
  rollback_plan_json JSON NOT NULL,
  rollback_plan_hash CHAR(64) NOT NULL,
  readiness_packet_hash CHAR(64) NOT NULL,
  model_version_hash CHAR(64) NOT NULL,
  baseline_version_hash CHAR(64) NOT NULL,
  decision_hash CHAR(64) NULL,
  final_signature_hash CHAR(64) NULL,
  final_signed_by_user_id BIGINT UNSIGNED NULL,
  final_signed_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  opened_for_review_at DATETIME NULL,
  decided_at DATETIME NULL,
  archived_at DATETIME NULL,
  UNIQUE KEY uq_model_release_packet(readiness_packet_id),
  INDEX idx_model_release_status(status,updated_at),
  INDEX idx_model_release_model(model_version_id,status),
  CONSTRAINT fk_model_release_plan FOREIGN KEY(post_training_plan_id) REFERENCES data_post_training_plans(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_release_packet FOREIGN KEY(readiness_packet_id) REFERENCES data_post_training_packets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_release_model FOREIGN KEY(model_version_id) REFERENCES data_model_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_release_baseline FOREIGN KEY(baseline_model_version_id) REFERENCES data_model_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_release_registry FOREIGN KEY(registry_id) REFERENCES data_model_registry(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_release_final_signer FOREIGN KEY(final_signed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_release_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_release_checklist_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  decision_id BIGINT UNSIGNED NOT NULL,
  check_key VARCHAR(64) NOT NULL,
  category VARCHAR(64) NOT NULL,
  label VARCHAR(255) NOT NULL,
  description VARCHAR(1200) NULL,
  required TINYINT(1) NOT NULL DEFAULT 1,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  note VARCHAR(2000) NULL,
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_release_check(decision_id,check_key),
  INDEX idx_model_release_check_status(decision_id,status),
  CONSTRAINT fk_model_release_check_decision FOREIGN KEY(decision_id) REFERENCES data_model_release_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_release_check_reviewer FOREIGN KEY(reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_release_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  decision_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  recommendation VARCHAR(32) NOT NULL,
  note VARCHAR(3000) NULL,
  decision_snapshot_hash CHAR(64) NOT NULL,
  signature_hash CHAR(64) NOT NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_release_reviewer(decision_id,reviewer_user_id),
  INDEX idx_model_release_review_recommendation(decision_id,recommendation),
  CONSTRAINT fk_model_release_review_decision FOREIGN KEY(decision_id) REFERENCES data_model_release_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_release_review_user FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_release_signatures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  decision_id BIGINT UNSIGNED NOT NULL,
  signer_user_id BIGINT UNSIGNED NOT NULL,
  signature_type VARCHAR(32) NOT NULL,
  subject_hash CHAR(64) NOT NULL,
  signature_hash CHAR(64) NOT NULL,
  note VARCHAR(1000) NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_release_signature(decision_id,signature_type,signer_user_id),
  INDEX idx_model_release_signature_subject(decision_id,subject_hash),
  CONSTRAINT fk_model_release_signature_decision FOREIGN KEY(decision_id) REFERENCES data_model_release_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_release_signature_user FOREIGN KEY(signer_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_release_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  decision_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_release_event(decision_id,created_at),
  CONSTRAINT fk_model_release_event_decision FOREIGN KEY(decision_id) REFERENCES data_model_release_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_release_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
