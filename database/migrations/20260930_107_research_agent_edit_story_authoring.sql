-- Phase 78.1 — Research Agent Edit & Story Authoring Foundation
ALTER TABLE research_agent_stories
  ADD COLUMN status ENUM('draft','published','archived') NOT NULL DEFAULT 'published' AFTER generation_status,
  ADD COLUMN scheduled_at DATETIME NULL AFTER published_at,
  ADD INDEX idx_research_agent_story_status(agent_id,status,published_at);
