-- Annotated Phase 79 Section 3 — Agent Memory / Knowledge Management

CREATE TABLE IF NOT EXISTS research_memory_controls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  object_type VARCHAR(32) NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  retrieval_state ENUM('inherit','include','exclude') NOT NULL DEFAULT 'inherit',
  correction_text TEXT NULL,
  correction_hash CHAR(64) NULL,
  corrected_by_user_id BIGINT UNSIGNED NULL,
  corrected_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_memory_object(project_id,object_type,object_public_id),
  INDEX idx_research_memory_project(project_id,retrieval_state,updated_at),
  CONSTRAINT fk_research_memory_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_memory_created_by FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_memory_corrected_by FOREIGN KEY(corrected_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_memory_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  control_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('created','retrieval_changed','corrected','correction_cleared','restored') NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_memory_events_control(control_id,created_at),
  INDEX idx_research_memory_events_project(project_id,created_at),
  CONSTRAINT fk_research_memory_events_control FOREIGN KEY(control_id) REFERENCES research_memory_controls(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_memory_events_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_memory_events_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_memory_usage (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  research_agent_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  conversation_message_id BIGINT UNSIGNED NULL,
  object_type VARCHAR(32) NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  locator_label VARCHAR(190) NULL,
  usage_type ENUM('agent_retrieval') NOT NULL DEFAULT 'agent_retrieval',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_memory_usage_object(project_id,object_type,object_public_id,created_at),
  INDEX idx_research_memory_usage_agent(research_agent_id,created_at),
  INDEX idx_research_memory_usage_user(user_id,created_at),
  CONSTRAINT fk_research_memory_usage_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_memory_usage_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_memory_usage_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_memory_usage_message FOREIGN KEY(conversation_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
