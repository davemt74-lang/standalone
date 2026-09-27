-- Annotated Phase 71 Section 2 — Mission → Decision Handoff
-- Explicit, idempotent handoff snapshots only. No Mission auto-conversion and no automatic Decision acceptance.

CREATE TABLE IF NOT EXISTS research_decision_handoffs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  mission_id BIGINT UNSIGNED NOT NULL,
  decision_id BIGINT UNSIGNED NOT NULL,
  mission_revision INT UNSIGNED NOT NULL,
  mission_status VARCHAR(32) NOT NULL,
  mission_state_hash CHAR(64) NOT NULL,
  snapshot_json JSON NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NULL,
  created_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  idempotency_key CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_decision_handoff_decision(decision_id),
  UNIQUE KEY uq_research_decision_handoff_idempotency(mission_id,idempotency_key),
  INDEX idx_research_decision_handoff_mission(mission_id,created_at),
  CONSTRAINT fk_research_decision_handoff_mission FOREIGN KEY(mission_id) REFERENCES research_missions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_handoff_decision FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_handoff_user FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
