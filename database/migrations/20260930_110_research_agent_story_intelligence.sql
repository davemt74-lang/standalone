-- Phase 78.4 — Agent Story Intelligence
ALTER TABLE research_agent_stories
  ADD COLUMN intelligence_hash CHAR(64) NULL AFTER object_public_id,
  ADD COLUMN parent_story_id BIGINT UNSIGNED NULL AFTER intelligence_hash,
  ADD COLUMN why_it_matters VARCHAR(1000) NULL AFTER parent_story_id,
  ADD INDEX idx_research_agent_story_intelligence(agent_id,intelligence_hash),
  ADD INDEX idx_research_agent_story_parent(parent_story_id),
  ADD CONSTRAINT fk_research_agent_story_parent FOREIGN KEY(parent_story_id) REFERENCES research_agent_stories(id) ON DELETE SET NULL;
