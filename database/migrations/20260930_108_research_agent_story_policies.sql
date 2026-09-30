-- Phase 78.2 — Proactive Story Triggers & Publishing Policy
CREATE TABLE IF NOT EXISTS research_agent_story_policies (
  agent_id BIGINT UNSIGNED PRIMARY KEY,
  publish_mode ENUM('draft_only','approval','auto_publish') NOT NULL DEFAULT 'approval',
  min_priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  daily_story_cap TINYINT UNSIGNED NOT NULL DEFAULT 3,
  quiet_hours_enabled TINYINT(1) NOT NULL DEFAULT 0,
  quiet_start TIME NULL,
  quiet_end TIME NULL,
  trigger_evidence TINYINT(1) NOT NULL DEFAULT 1,
  trigger_risk TINYINT(1) NOT NULL DEFAULT 1,
  trigger_question TINYINT(1) NOT NULL DEFAULT 1,
  trigger_decision TINYINT(1) NOT NULL DEFAULT 1,
  trigger_task TINYINT(1) NOT NULL DEFAULT 1,
  trigger_update TINYINT(1) NOT NULL DEFAULT 1,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_research_agent_story_policy_agent FOREIGN KEY(agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_agent_story_policy_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_research_agent_story_daily_cap CHECK (daily_story_cap BETWEEN 1 AND 20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
