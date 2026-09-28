-- Annotated Phase 72 Section 7 — Completion, Outcome Handoff & Decision Learning
-- Explicit completed Action Plan -> existing Decision Outcome Memory bridge.

CREATE TABLE IF NOT EXISTS research_action_plan_outcome_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  action_plan_id BIGINT UNSIGNED NOT NULL,
  decision_outcome_id BIGINT UNSIGNED NOT NULL,
  execution_baseline_id BIGINT UNSIGNED NOT NULL,
  action_plan_revision INT UNSIGNED NOT NULL,
  action_plan_config_hash CHAR(64) NOT NULL,
  handoff_state_hash CHAR(64) NOT NULL,
  handoff_snapshot_json JSON NOT NULL,
  recorded_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rap_outcome_plan(action_plan_id),
  UNIQUE KEY uq_rap_outcome_decision_outcome(decision_outcome_id),
  INDEX idx_rap_outcome_baseline(execution_baseline_id),
  CONSTRAINT fk_rap_outcome_plan FOREIGN KEY(action_plan_id) REFERENCES research_action_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_rap_outcome_decision_outcome FOREIGN KEY(decision_outcome_id) REFERENCES research_decision_outcomes(id) ON DELETE CASCADE,
  CONSTRAINT fk_rap_outcome_baseline FOREIGN KEY(execution_baseline_id) REFERENCES research_action_plan_execution_baselines(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rap_outcome_user FOREIGN KEY(recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
