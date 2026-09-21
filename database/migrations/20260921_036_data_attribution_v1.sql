-- Annotated Phase 37 — Data & Attribution Architecture v1
-- Append-only contribution lineage, explicit usage grants, source rights,
-- derived corpus eligibility, and AI response attribution.
-- Public visibility never implies model-training permission.

CREATE TABLE IF NOT EXISTS data_contributor_preferences (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  allow_shared_retrieval TINYINT(1) NOT NULL DEFAULT 0,
  allow_evaluation TINYINT(1) NOT NULL DEFAULT 0,
  allow_training TINYINT(1) NOT NULL DEFAULT 0,
  allow_commercial_training TINYINT(1) NOT NULL DEFAULT 0,
  attribution_required TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_data_pref_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_usage_grants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  object_type VARCHAR(64) NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  grantor_user_id BIGINT UNSIGNED NOT NULL,
  shared_retrieval_allowed TINYINT(1) NOT NULL DEFAULT 0,
  evaluation_allowed TINYINT(1) NOT NULL DEFAULT 0,
  training_allowed TINYINT(1) NOT NULL DEFAULT 0,
  commercial_training_allowed TINYINT(1) NOT NULL DEFAULT 0,
  attribution_required TINYINT(1) NOT NULL DEFAULT 1,
  license_code VARCHAR(80) NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_usage_object_grantor(object_type,object_public_id,grantor_user_id),
  INDEX idx_data_usage_object(object_type,object_public_id,revoked_at),
  INDEX idx_data_usage_grantor(grantor_user_id,updated_at),
  CONSTRAINT fk_data_usage_grantor FOREIGN KEY(grantor_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_rights (
  source_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  rights_class VARCHAR(40) NOT NULL DEFAULT 'unknown',
  retrieval_allowed TINYINT(1) NOT NULL DEFAULT 0,
  excerpt_storage_allowed TINYINT(1) NOT NULL DEFAULT 0,
  model_context_allowed TINYINT(1) NOT NULL DEFAULT 0,
  training_allowed TINYINT(1) NOT NULL DEFAULT 0,
  commercial_training_allowed TINYINT(1) NOT NULL DEFAULT 0,
  license_code VARCHAR(80) NULL,
  rights_holder VARCHAR(255) NULL,
  policy_url VARCHAR(1000) NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'unreviewed',
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_source_rights_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_source_rights_reviewer FOREIGN KEY(reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_contributions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  actor_type VARCHAR(24) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  ai_run_id BIGINT UNSIGNED NULL,
  object_type VARCHAR(64) NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  object_version_id VARCHAR(64) NULL,
  contribution_type VARCHAR(64) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  state_hash CHAR(64) NOT NULL,
  metadata_json JSON NULL,
  parent_contribution_id BIGINT UNSIGNED NULL,
  dedupe_key CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  superseded_at DATETIME NULL,
  revoked_at DATETIME NULL,
  INDEX idx_data_contribution_actor(actor_user_id,created_at),
  INDEX idx_data_contribution_object(object_type,object_public_id,created_at),
  INDEX idx_data_contribution_ai(ai_run_id),
  CONSTRAINT fk_data_contribution_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_data_contribution_ai FOREIGN KEY(ai_run_id) REFERENCES ai_runs(id) ON DELETE SET NULL,
  CONSTRAINT fk_data_contribution_parent FOREIGN KEY(parent_contribution_id) REFERENCES data_contributions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_provenance_edges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  from_type VARCHAR(64) NOT NULL,
  from_public_id VARCHAR(64) NOT NULL,
  from_version_id VARCHAR(64) NULL,
  relationship VARCHAR(64) NOT NULL,
  to_type VARCHAR(64) NOT NULL,
  to_public_id VARCHAR(64) NOT NULL,
  to_version_id VARCHAR(64) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  ai_run_id BIGINT UNSIGNED NULL,
  confidence DECIMAL(5,4) NULL,
  human_confirmed_at DATETIME NULL,
  metadata_json JSON NULL,
  dedupe_key CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  INDEX idx_data_edge_from(from_type,from_public_id,created_at),
  INDEX idx_data_edge_to(to_type,to_public_id,created_at),
  CONSTRAINT fk_data_edge_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_data_edge_ai FOREIGN KEY(ai_run_id) REFERENCES ai_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_corpus_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  source_object_type VARCHAR(64) NOT NULL,
  source_object_public_id VARCHAR(64) NOT NULL,
  source_object_version VARCHAR(64) NULL,
  contributor_user_id BIGINT UNSIGNED NULL,
  corpus_type VARCHAR(64) NOT NULL,
  normalized_text MEDIUMTEXT NOT NULL,
  metadata_json JSON NULL,
  content_hash CHAR(64) NOT NULL,
  provenance_hash CHAR(64) NOT NULL,
  shared_retrieval_eligible TINYINT(1) NOT NULL DEFAULT 0,
  evaluation_eligible TINYINT(1) NOT NULL DEFAULT 0,
  training_eligible TINYINT(1) NOT NULL DEFAULT 0,
  commercial_training_eligible TINYINT(1) NOT NULL DEFAULT 0,
  attribution_required TINYINT(1) NOT NULL DEFAULT 1,
  eligibility_reason VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  invalidated_at DATETIME NULL,
  UNIQUE KEY uq_data_corpus_object(source_object_type,source_object_public_id,source_object_version),
  INDEX idx_data_corpus_eligibility(training_eligible,shared_retrieval_eligible,invalidated_at),
  INDEX idx_data_corpus_contributor(contributor_user_id,refreshed_at),
  CONSTRAINT fk_data_corpus_contributor FOREIGN KEY(contributor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_response_lineage (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  ai_run_id BIGINT UNSIGNED NOT NULL UNIQUE,
  conversation_message_id BIGINT UNSIGNED NULL,
  response_hash CHAR(64) NOT NULL,
  context_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_data_response_message(conversation_message_id),
  CONSTRAINT fk_data_response_ai FOREIGN KEY(ai_run_id) REFERENCES ai_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_response_message FOREIGN KEY(conversation_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_response_attributions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_lineage_id BIGINT UNSIGNED NOT NULL,
  object_type VARCHAR(64) NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  object_version_id VARCHAR(64) NULL,
  contributor_user_id BIGINT UNSIGNED NULL,
  contribution_id BIGINT UNSIGNED NULL,
  attribution_type VARCHAR(32) NOT NULL DEFAULT 'context',
  usage_rank INT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_response_object(response_lineage_id,object_type,object_public_id,object_version_id),
  INDEX idx_data_response_contributor(contributor_user_id,created_at),
  INDEX idx_data_response_object(object_type,object_public_id,created_at),
  CONSTRAINT fk_data_response_lineage FOREIGN KEY(response_lineage_id) REFERENCES data_response_lineage(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_response_contributor FOREIGN KEY(contributor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_data_response_contribution FOREIGN KEY(contribution_id) REFERENCES data_contributions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
