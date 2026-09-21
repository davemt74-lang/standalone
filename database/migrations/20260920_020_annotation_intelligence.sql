-- Annotated Phase 13 — Annotation Intelligence
-- Derived intelligence is stored separately from immutable captured evidence.

ALTER TABLE ai_settings
  ADD COLUMN IF NOT EXISTS annotation_intelligence_model_id BIGINT UNSIGNED NULL AFTER transcript_cleanup_model_id;
ALTER TABLE ai_settings
  ADD CONSTRAINT fk_ai_setting_annotation_intelligence FOREIGN KEY(annotation_intelligence_model_id) REFERENCES ai_models(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS annotation_intelligence (
  annotation_id BIGINT UNSIGNED PRIMARY KEY,
  status ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
  input_hash CHAR(64) NULL,
  summary TEXT NULL,
  topics_json JSON NULL,
  entities_json JSON NULL,
  claims_json JSON NULL,
  confidence DECIMAL(5,4) NULL,
  model_id BIGINT UNSIGNED NULL,
  ai_run_public_id VARCHAR(40) NULL,
  prompt_version VARCHAR(40) NOT NULL DEFAULT 'phase13-v1',
  last_error VARCHAR(1000) NULL,
  queued_at DATETIME NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_annotation_intelligence_status(status,updated_at),
  INDEX idx_annotation_intelligence_model(model_id,processed_at),
  CONSTRAINT fk_annotation_intelligence_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_annotation_intelligence_model FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS annotation_relationships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_annotation_id BIGINT UNSIGNED NOT NULL,
  target_annotation_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('related','duplicate','corroborates','conflicts') NOT NULL,
  confidence DECIMAL(5,4) NOT NULL,
  rationale VARCHAR(1000) NULL,
  generated_by ENUM('deterministic','ai') NOT NULL DEFAULT 'ai',
  ai_run_public_id VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_annotation_relationship(source_annotation_id,target_annotation_id,relation_type),
  INDEX idx_annotation_relationship_target(target_annotation_id,relation_type,confidence),
  CONSTRAINT fk_annotation_relationship_source FOREIGN KEY(source_annotation_id) REFERENCES annotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_annotation_relationship_target FOREIGN KEY(target_annotation_id) REFERENCES annotations(id) ON DELETE CASCADE,
  CONSTRAINT chk_annotation_relationship_distinct CHECK(source_annotation_id<>target_annotation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
