-- Annotated V1.1 Phase 8: Live Rooms + Cloak Mode hardening. Immutable after release.

CREATE TABLE IF NOT EXISTS live_presence_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  room_type ENUM('public','team','project') NOT NULL DEFAULT 'public',
  team_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  client_session_id VARCHAR(64) NOT NULL,
  presence_mode ENUM('visible','team_only','cloaked','off') NOT NULL DEFAULT 'cloaked',
  cloak_alias VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_live_presence_client(user_id,client_session_id),
  INDEX idx_live_presence_room(source_id,room_type,team_id,project_id,last_seen_at),
  INDEX idx_live_presence_seen(last_seen_at),
  CONSTRAINT fk_live_presence_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_presence_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_presence_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_presence_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE live_messages
  MODIFY COLUMN room_type ENUM('public','team','project') NOT NULL DEFAULT 'public',
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(40) NULL AFTER id,
  ADD COLUMN IF NOT EXISTS project_id BIGINT UNSIGNED NULL AFTER team_id,
  ADD COLUMN IF NOT EXISTS parent_message_id BIGINT UNSIGNED NULL AFTER project_id,
  ADD COLUMN IF NOT EXISTS identity_mode ENUM('visible','team_only','cloaked') NOT NULL DEFAULT 'visible' AFTER parent_message_id,
  ADD COLUMN IF NOT EXISTS cloak_alias VARCHAR(32) NULL AFTER identity_mode,
  ADD COLUMN IF NOT EXISTS client_message_id VARCHAR(64) NULL AFTER cloak_alias,
  ADD COLUMN IF NOT EXISTS pinned_at DATETIME NULL AFTER source_timestamp_seconds,
  ADD COLUMN IF NOT EXISTS pinned_by_user_id BIGINT UNSIGNED NULL AFTER pinned_at,
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER pinned_by_user_id,
  ADD COLUMN IF NOT EXISTS deleted_by_user_id BIGINT UNSIGNED NULL AFTER deleted_at;

UPDATE live_messages SET public_id=CONCAT('legacy-live-',id) WHERE public_id IS NULL OR public_id='';
ALTER TABLE live_messages MODIFY COLUMN public_id VARCHAR(40) NOT NULL;
ALTER TABLE live_messages ADD UNIQUE KEY IF NOT EXISTS uq_live_public(public_id);
ALTER TABLE live_messages ADD UNIQUE KEY IF NOT EXISTS uq_live_client_message(user_id,client_message_id);
ALTER TABLE live_messages ADD INDEX IF NOT EXISTS idx_live_room_cursor(source_id,room_type,team_id,project_id,id);
ALTER TABLE live_messages ADD INDEX IF NOT EXISTS idx_live_parent(parent_message_id);

CREATE TABLE IF NOT EXISTS live_message_reactions (
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reaction VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(message_id,user_id,reaction),
  INDEX idx_live_reaction_message(message_id),
  CONSTRAINT fk_live_reaction_message FOREIGN KEY(message_id) REFERENCES live_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_reaction_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS live_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  event_key VARCHAR(190) NOT NULL UNIQUE,
  source_id BIGINT UNSIGNED NOT NULL,
  room_type ENUM('public','team','project') NOT NULL DEFAULT 'public',
  team_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type ENUM('annotation','comment','source_change','research_add') NOT NULL,
  object_type VARCHAR(64) NOT NULL,
  object_public_id VARCHAR(64) NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_live_event_room(source_id,room_type,team_id,project_id,id),
  CONSTRAINT fk_live_event_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_event_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_event_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
