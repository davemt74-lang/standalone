-- Research Agent Stories V1 — profile identity + proactive text stories
ALTER TABLE research_agents
  ADD COLUMN IF NOT EXISTS profile_image_url VARCHAR(500) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS visibility ENUM('private','friends','public') NOT NULL DEFAULT 'private' AFTER profile_image_url;

CREATE TABLE IF NOT EXISTS research_agent_stories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  agent_id BIGINT UNSIGNED NOT NULL,
  observation_key CHAR(64) NULL,
  story_type ENUM('update','evidence','risk','question','decision','task','briefing') NOT NULL DEFAULT 'update',
  priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  title VARCHAR(240) NOT NULL,
  body TEXT NOT NULL,
  source_body TEXT NULL,
  primary_url VARCHAR(500) NULL,
  object_type VARCHAR(64) NULL,
  object_public_id VARCHAR(64) NULL,
  generation_quality ENUM('deterministic','llm') NOT NULL DEFAULT 'deterministic',
  generation_status ENUM('ready','queued','enhanced','failed') NOT NULL DEFAULT 'ready',
  ai_run_public_id VARCHAR(40) NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_agent_story_observation(agent_id,observation_key),
  INDEX idx_research_agent_story_agent(agent_id,published_at),
  INDEX idx_research_agent_story_priority(priority,published_at),
  CONSTRAINT fk_research_agent_story_agent FOREIGN KEY(agent_id) REFERENCES research_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_agent_story_states (
  story_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  viewed_at DATETIME NULL,
  dismissed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(story_id,user_id),
  INDEX idx_research_agent_story_state_user(user_id,dismissed_at,viewed_at),
  CONSTRAINT fk_research_agent_story_state_story FOREIGN KEY(story_id) REFERENCES research_agent_stories(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_agent_story_state_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
