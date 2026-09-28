-- Annotated Phase 72 Section 3 — Programs & Recurring Follow-Through
-- Reuses existing Research Programs as the sole recurring scheduler/run authority.

CREATE TABLE IF NOT EXISTS research_action_plan_program_links (
  action_plan_id BIGINT UNSIGNED NOT NULL,
  program_id BIGINT UNSIGNED NOT NULL,
  program_role ENUM('execution_review','success_measure_check','evidence_refresh','decision_follow_up') NOT NULL DEFAULT 'execution_review',
  sync_with_action_plan TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(action_plan_id,program_id),
  UNIQUE KEY uq_research_action_plan_program(program_id),
  UNIQUE KEY uq_research_action_plan_program_role(action_plan_id,program_role),
  INDEX idx_research_action_plan_program_role(program_role,sync_with_action_plan),
  CONSTRAINT fk_research_action_plan_program_link_plan FOREIGN KEY(action_plan_id) REFERENCES research_action_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_program_link_program FOREIGN KEY(program_id) REFERENCES research_programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_program_link_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_program_deltas
  MODIFY COLUMN delta_type ENUM(
    'baseline',
    'source_added','source_changed','source_unavailable','source_restored',
    'claim_added','claim_status_changed','claim_strengthened','claim_weakened','claim_contradicted','claim_resolved',
    'contradiction_opened','contradiction_resolved',
    'entity_added','monitor_event','annotation_added','workspace_changed',
    'action_plan_status_changed','action_plan_source_stale','action_plan_source_current','action_plan_overdue',
    'milestone_added','milestone_started','milestone_completed','milestone_blocked','milestone_unblocked','milestone_overdue',
    'execution_progress_changed'
  ) NOT NULL;
