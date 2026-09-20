-- Annotated Phase 15 — Agent-Driven Research
-- Bounded Agent mutations use Propose -> Confirm -> Execute with provenance and audit history.

CREATE TABLE IF NOT EXISTS agent_action_proposals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  conversation_id BIGINT UNSIGNED NOT NULL,
  assistant_message_id BIGINT UNSIGNED NOT NULL,
  proposed_by_user_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  capability_key VARCHAR(100) NOT NULL,
  status ENUM('pending','confirmed','executed','rejected','stale','failed') NOT NULL DEFAULT 'pending',
  arguments_json JSON NOT NULL,
  provenance_json JSON NOT NULL,
  project_state_hash CHAR(64) NOT NULL,
  dedupe_key CHAR(64) NOT NULL UNIQUE,
  result_type VARCHAR(60) NULL,
  result_public_id VARCHAR(64) NULL,
  result_json JSON NULL,
  error_text VARCHAR(1000) NULL,
  expires_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  executed_at DATETIME NULL,
  rejected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agent_action_user(proposed_by_user_id,status,created_at),
  INDEX idx_agent_action_project(project_id,status,created_at),
  INDEX idx_agent_action_message(assistant_message_id,status),
  CONSTRAINT fk_agent_action_conversation FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_action_message FOREIGN KEY(assistant_message_id) REFERENCES conversation_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_action_user FOREIGN KEY(proposed_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_action_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_action_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proposal_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('proposed','confirmed','executed','rejected','stale','failed') NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_agent_action_event(proposal_id,created_at),
  CONSTRAINT fk_agent_action_event_proposal FOREIGN KEY(proposal_id) REFERENCES agent_action_proposals(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_action_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
