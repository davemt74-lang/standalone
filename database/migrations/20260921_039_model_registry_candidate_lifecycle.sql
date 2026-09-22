-- Annotated Phase 40 — Model Registry & Candidate Lifecycle
-- Governance layer above existing ai_models provider/runtime configuration.
-- No training, fine-tuning, model-weight mutation, or automatic promotion.

CREATE TABLE IF NOT EXISTS data_model_registry (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  description TEXT NULL,
  active_version_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_data_model_registry_active(active_version_id),
  CONSTRAINT fk_data_model_registry_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  registry_id BIGINT UNSIGNED NOT NULL,
  ai_model_id BIGINT UNSIGNED NULL,
  version_label VARCHAR(120) NOT NULL,
  origin VARCHAR(32) NOT NULL DEFAULT 'external_hosted',
  status VARCHAR(24) NOT NULL DEFAULT 'experimental',
  architecture VARCHAR(180) NULL,
  intended_use TEXT NULL,
  license_code VARCHAR(120) NULL,
  source_uri VARCHAR(1000) NULL,
  context_window INT UNSIGNED NULL,
  artifact_ref VARCHAR(1000) NULL,
  artifact_hash CHAR(64) NULL,
  metadata_json JSON NULL,
  gate_policy_json JSON NOT NULL,
  gate_policy_hash CHAR(64) NOT NULL,
  version_hash CHAR(64) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_model_version(registry_id,version_label),
  INDEX idx_data_model_version_status(status,updated_at),
  INDEX idx_data_model_version_ai(ai_model_id,status),
  CONSTRAINT fk_data_model_version_registry FOREIGN KEY(registry_id) REFERENCES data_model_registry(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_model_version_ai FOREIGN KEY(ai_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_data_model_version_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_evaluation_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id BIGINT UNSIGNED NOT NULL,
  evaluation_run_id BIGINT UNSIGNED NOT NULL,
  linked_by_user_id BIGINT UNSIGNED NOT NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_model_eval_link(version_id,evaluation_run_id),
  INDEX idx_data_model_eval_run(evaluation_run_id,version_id),
  CONSTRAINT fk_data_model_eval_version FOREIGN KEY(version_id) REFERENCES data_model_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_model_eval_run FOREIGN KEY(evaluation_run_id) REFERENCES data_evaluation_runs(id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_model_eval_user FOREIGN KEY(linked_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_promotion_receipts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  version_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(24) NOT NULL,
  to_status VARCHAR(24) NOT NULL,
  previous_active_version_id BIGINT UNSIGNED NULL,
  gate_snapshot_json JSON NOT NULL,
  gate_snapshot_hash CHAR(64) NOT NULL,
  evidence_snapshot_json JSON NOT NULL,
  evidence_snapshot_hash CHAR(64) NOT NULL,
  note TEXT NULL,
  receipt_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_data_model_receipt_version(version_id,created_at),
  INDEX idx_data_model_receipt_transition(from_status,to_status,created_at),
  CONSTRAINT fk_data_model_receipt_version FOREIGN KEY(version_id) REFERENCES data_model_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_model_receipt_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_model_receipt_previous FOREIGN KEY(previous_active_version_id) REFERENCES data_model_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registry_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(48) NOT NULL,
  details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_data_model_event_registry(registry_id,created_at),
  INDEX idx_data_model_event_version(version_id,created_at),
  CONSTRAINT fk_data_model_event_registry FOREIGN KEY(registry_id) REFERENCES data_model_registry(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_model_event_version FOREIGN KEY(version_id) REFERENCES data_model_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_model_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
