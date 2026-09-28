-- Annotated Phase 72 Section 2 — Milestones, Tasks & Dependencies
-- Action Plan orchestration layered on the existing Research Task Plan / Task / dependency runtime.
-- No parallel task queue or execution worker is introduced.

ALTER TABLE research_action_plans
  ADD COLUMN execution_task_plan_id BIGINT UNSIGNED NULL AFTER project_id,
  ADD UNIQUE KEY uq_research_action_plan_task_plan(execution_task_plan_id),
  ADD CONSTRAINT fk_research_action_plan_task_plan FOREIGN KEY(execution_task_plan_id) REFERENCES research_task_plans(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS research_action_plan_milestones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  action_plan_id BIGINT UNSIGNED NOT NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  status ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
  completion_criteria_json JSON NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  target_on DATE NULL,
  completed_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_action_milestone_plan(action_plan_id,status,position),
  INDEX idx_research_action_milestone_owner(owner_user_id,status,target_on),
  CONSTRAINT fk_research_action_milestone_plan FOREIGN KEY(action_plan_id) REFERENCES research_action_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_milestone_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_action_milestone_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_action_plan_milestone_dependencies (
  milestone_id BIGINT UNSIGNED NOT NULL,
  depends_on_milestone_id BIGINT UNSIGNED NOT NULL,
  dependency_type ENUM('finish_to_start','blocking') NOT NULL DEFAULT 'finish_to_start',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(milestone_id,depends_on_milestone_id),
  INDEX idx_research_action_milestone_dependency_parent(depends_on_milestone_id,milestone_id),
  CONSTRAINT fk_research_action_milestone_dependency FOREIGN KEY(milestone_id) REFERENCES research_action_plan_milestones(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_milestone_dependency_parent FOREIGN KEY(depends_on_milestone_id) REFERENCES research_action_plan_milestones(id) ON DELETE CASCADE,
  CONSTRAINT chk_research_action_milestone_not_self CHECK (milestone_id<>depends_on_milestone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_action_plan_task_links (
  action_plan_id BIGINT UNSIGNED NOT NULL,
  milestone_id BIGINT UNSIGNED NULL,
  task_id BIGINT UNSIGNED NOT NULL,
  link_role ENUM('execution','validation','supporting') NOT NULL DEFAULT 'execution',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(action_plan_id,task_id),
  UNIQUE KEY uq_research_action_plan_task_once(task_id),
  INDEX idx_research_action_plan_task_milestone(milestone_id,task_id),
  CONSTRAINT fk_research_action_plan_task_link_plan FOREIGN KEY(action_plan_id) REFERENCES research_action_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_task_link_milestone FOREIGN KEY(milestone_id) REFERENCES research_action_plan_milestones(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_action_plan_task_link_task FOREIGN KEY(task_id) REFERENCES research_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_task_link_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
