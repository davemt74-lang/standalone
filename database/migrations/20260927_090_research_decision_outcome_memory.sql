-- Annotated Phase 71 Section 4 — Outcome Memory
-- Decision-specific outcome assessment layered on the existing Phase 20 research_outcome_events ledger.

CREATE TABLE IF NOT EXISTS research_decision_outcomes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  decision_id BIGINT UNSIGNED NOT NULL,
  outcome_event_id BIGINT UNSIGNED NOT NULL,
  assessment ENUM('unresolved','success','partial','failure','mixed') NOT NULL DEFAULT 'unresolved',
  expected_summary MEDIUMTEXT NULL,
  actual_summary MEDIUMTEXT NOT NULL,
  variance_summary MEDIUMTEXT NULL,
  lessons MEDIUMTEXT NULL,
  confidence DECIMAL(5,4) NULL,
  follow_up_state ENUM('none','follow_up','resolved','reopened') NOT NULL DEFAULT 'none',
  recorded_by_user_id BIGINT UNSIGNED NULL,
  created_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  observed_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_decision_outcome_event(outcome_event_id),
  INDEX idx_research_decision_outcome_decision(decision_id,observed_at),
  INDEX idx_research_decision_outcome_assessment(decision_id,assessment,follow_up_state,updated_at),
  CONSTRAINT fk_research_decision_outcome_decision FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_outcome_event FOREIGN KEY(outcome_event_id) REFERENCES research_outcome_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_outcome_user FOREIGN KEY(recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_research_decision_outcome_confidence CHECK (confidence IS NULL OR (confidence>=0 AND confidence<=1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_decision_outcome_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  decision_outcome_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  snapshot_json JSON NOT NULL,
  change_reason VARCHAR(1000) NULL,
  edited_by_user_id BIGINT UNSIGNED NULL,
  edited_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_decision_outcome_revision(decision_outcome_id,revision_number),
  INDEX idx_research_decision_outcome_version(decision_outcome_id,created_at),
  CONSTRAINT fk_research_decision_outcome_version_outcome FOREIGN KEY(decision_outcome_id) REFERENCES research_decision_outcomes(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_outcome_version_user FOREIGN KEY(edited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
