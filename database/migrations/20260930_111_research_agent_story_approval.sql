-- Phase 78.6 — Story Approval & Operational Lifecycle
ALTER TABLE research_agent_stories
  ADD COLUMN origin ENUM('manual','proactive') NOT NULL DEFAULT 'proactive' AFTER generation_status,
  ADD COLUMN approval_state ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required' AFTER status,
  ADD COLUMN reviewed_by_user_id BIGINT UNSIGNED NULL AFTER approval_state,
  ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_user_id,
  ADD COLUMN review_note VARCHAR(1000) NULL AFTER reviewed_at,
  ADD INDEX idx_research_agent_story_approval(agent_id,approval_state,status),
  ADD CONSTRAINT fk_research_agent_story_reviewer FOREIGN KEY(reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
