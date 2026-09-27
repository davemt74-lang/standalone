-- Annotated Phase 71 Section 5 — Decision Evolution & Reconsideration
-- Explicit reconsideration cases and deterministic review signals. No automatic Decision disposition changes.

CREATE TABLE IF NOT EXISTS research_decision_reconsiderations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  decision_id BIGINT UNSIGNED NOT NULL,
  trigger_type ENUM('manual','evidence_change','challenge','outcome','reversal_condition','assumption') NOT NULL DEFAULT 'manual',
  trigger_public_id VARCHAR(160) NULL,
  title VARCHAR(255) NOT NULL,
  reason MEDIUMTEXT NOT NULL,
  materiality ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
  recommended_action ENUM('undetermined','retain','reopen','supersede','defer') NOT NULL DEFAULT 'undetermined',
  resolution MEDIUMTEXT NULL,
  decision_revision_opened INT UNSIGNED NOT NULL,
  decision_status_opened VARCHAR(32) NOT NULL,
  opening_context_hash CHAR(64) NOT NULL,
  opening_snapshot_json JSON NOT NULL,
  opened_by_user_id BIGINT UNSIGNED NULL,
  created_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  resolved_by_user_id BIGINT UNSIGNED NULL,
  applied_by_user_id BIGINT UNSIGNED NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  applied_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_decision_reconsideration(decision_id,status,materiality,updated_at),
  INDEX idx_research_decision_reconsideration_trigger(trigger_type,trigger_public_id),
  CONSTRAINT fk_research_decision_reconsideration_decision FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_reconsideration_opened_user FOREIGN KEY(opened_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_decision_reconsideration_resolved_user FOREIGN KEY(resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_decision_reconsideration_applied_user FOREIGN KEY(applied_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_decision_reconsideration_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  reconsideration_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_type ENUM('user','agent','system') NOT NULL DEFAULT 'user',
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_decision_reconsideration_event(reconsideration_id,id),
  CONSTRAINT fk_research_decision_reconsideration_event_case FOREIGN KEY(reconsideration_id) REFERENCES research_decision_reconsiderations(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_reconsideration_event_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
