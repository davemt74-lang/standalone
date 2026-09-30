-- Phase 78.3 — Story Composer, Editing & Scheduling
ALTER TABLE research_agent_stories
  ADD COLUMN edited_by_user_id BIGINT UNSIGNED NULL AFTER scheduled_at,
  ADD COLUMN archived_at DATETIME NULL AFTER edited_by_user_id,
  ADD INDEX idx_research_agent_story_schedule(status,scheduled_at),
  ADD CONSTRAINT fk_research_agent_story_editor FOREIGN KEY(edited_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
