-- Annotated Phase 46 — Production Feedback & Model Improvement Loop
-- Converts production evidence into human-governed improvement work.
-- No automatic training, promotion, deployment, rollback, or routing changes.

CREATE TABLE IF NOT EXISTS data_model_improvement_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  model_version_id BIGINT UNSIGNED NULL,
  deployment_id BIGINT UNSIGNED NULL,
  primary_incident_id BIGINT UNSIGNED NULL,
  route_key VARCHAR(64) NOT NULL,
  cluster_key CHAR(64) NOT NULL UNIQUE,
  classification VARCHAR(48) NOT NULL DEFAULT 'untriaged',
  severity VARCHAR(16) NOT NULL DEFAULT 'warning',
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  title VARCHAR(255) NOT NULL,
  summary VARCHAR(3000) NULL,
  recurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
  evidence_count INT UNSIGNED NOT NULL DEFAULT 0,
  evidence_hash CHAR(64) NOT NULL,
  triage_note VARCHAR(5000) NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  triaged_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  triaged_at DATETIME NULL,
  resolved_at DATETIME NULL,
  INDEX idx_model_improvement_case_status(status,severity,updated_at),
  INDEX idx_model_improvement_case_version(model_version_id,route_key,status),
  INDEX idx_model_improvement_case_deployment(deployment_id,status),
  CONSTRAINT fk_model_improvement_case_version FOREIGN KEY(model_version_id) REFERENCES data_model_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_improvement_case_deployment FOREIGN KEY(deployment_id) REFERENCES data_model_deployments(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_improvement_case_incident FOREIGN KEY(primary_incident_id) REFERENCES data_model_incidents(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_improvement_case_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_improvement_case_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_improvement_case_triager FOREIGN KEY(triaged_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_improvement_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NOT NULL,
  incident_id BIGINT UNSIGNED NULL,
  observation_id BIGINT UNSIGNED NULL,
  outcome_signal_id BIGINT UNSIGNED NULL,
  evidence_type VARCHAR(48) NOT NULL,
  evidence_ref_public_id VARCHAR(64) NOT NULL,
  evidence_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_improvement_evidence(case_id,evidence_type,evidence_ref_public_id),
  INDEX idx_model_improvement_evidence_case(case_id,created_at),
  CONSTRAINT fk_model_improvement_evidence_case FOREIGN KEY(case_id) REFERENCES data_model_improvement_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_improvement_evidence_incident FOREIGN KEY(incident_id) REFERENCES data_model_incidents(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_improvement_evidence_observation FOREIGN KEY(observation_id) REFERENCES data_model_observations(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_improvement_evidence_signal FOREIGN KEY(outcome_signal_id) REFERENCES data_model_outcome_signals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_improvement_proposals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  case_id BIGINT UNSIGNED NOT NULL,
  proposal_type VARCHAR(32) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  title VARCHAR(255) NOT NULL,
  sanitized_input TEXT NOT NULL,
  sanitized_expected_output TEXT NOT NULL,
  sanitized_context TEXT NULL,
  purpose_justification VARCHAR(3000) NULL,
  redaction_attested TINYINT(1) NOT NULL DEFAULT 0,
  rights_attested TINYINT(1) NOT NULL DEFAULT 0,
  content_hash CHAR(64) NOT NULL,
  approval_hash CHAR(64) NULL,
  corpus_item_public_id VARCHAR(40) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  published_at DATETIME NULL,
  UNIQUE KEY uq_model_improvement_case_type_title(case_id,proposal_type,title),
  INDEX idx_model_improvement_proposal_status(proposal_type,status,updated_at),
  CONSTRAINT fk_model_improvement_proposal_case FOREIGN KEY(case_id) REFERENCES data_model_improvement_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_improvement_proposal_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_improvement_proposal_approver FOREIGN KEY(approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_regression_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  proposal_id BIGINT UNSIGNED NOT NULL UNIQUE,
  case_id BIGINT UNSIGNED NOT NULL,
  model_version_id BIGINT UNSIGNED NULL,
  route_key VARCHAR(64) NOT NULL,
  label VARCHAR(255) NOT NULL,
  query_text TEXT NOT NULL,
  expected_behavior TEXT NOT NULL,
  case_hash CHAR(64) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_regression_case_version(model_version_id,route_key,active),
  CONSTRAINT fk_model_regression_proposal FOREIGN KEY(proposal_id) REFERENCES data_model_improvement_proposals(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_regression_improvement_case FOREIGN KEY(case_id) REFERENCES data_model_improvement_cases(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_regression_version FOREIGN KEY(model_version_id) REFERENCES data_model_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_improvement_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  details_json JSON NULL,
  evidence_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_improvement_event(case_id,created_at),
  CONSTRAINT fk_model_improvement_event_case FOREIGN KEY(case_id) REFERENCES data_model_improvement_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_improvement_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
