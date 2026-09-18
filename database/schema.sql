CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  username VARCHAR(50) NOT NULL UNIQUE,
  display_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  live_presence_mode ENUM('visible','team_only','cloaked','off') NOT NULL DEFAULT 'cloaked',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('google','x') NOT NULL,
  provider_user_id VARCHAR(190) NOT NULL,
  provider_email VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_provider_identity(provider, provider_user_id),
  CONSTRAINT fk_identity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS follows (
  follower_user_id BIGINT UNSIGNED NOT NULL,
  followed_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(follower_user_id, followed_user_id),
  CONSTRAINT fk_follow_a FOREIGN KEY(follower_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_follow_b FOREIGN KEY(followed_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  source_type ENUM('webpage','article','youtube','video','podcast','audio','image','other') NOT NULL DEFAULT 'webpage',
  canonical_url TEXT NOT NULL,
  canonical_url_hash CHAR(64) NOT NULL UNIQUE,
  domain VARCHAR(190) NOT NULL,
  title VARCHAR(500) NULL,
  current_version_id BIGINT UNSIGNED NULL,
  status ENUM('current','updated','edited','moved','unavailable','verification_pending') NOT NULL DEFAULT 'current',
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_checked_at DATETIME NULL,
  INDEX idx_source_domain(domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  final_url TEXT NOT NULL,
  title VARCHAR(500) NULL,
  extracted_text MEDIUMTEXT NULL,
  content_hash CHAR(64) NULL,
  target_content_hash CHAR(64) NULL,
  screenshot_path VARCHAR(500) NULL,
  metadata_json JSON NULL,
  UNIQUE KEY uq_source_version(source_id, version_number),
  CONSTRAINT fk_version_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sources ADD CONSTRAINT fk_source_current_version FOREIGN KEY(current_version_id) REFERENCES source_versions(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS captures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  source_id BIGINT UNSIGNED NOT NULL,
  source_version_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  capture_type ENUM('text','video_clip','audio_clip','image_region','page_region') NOT NULL,
  selected_text MEDIUMTEXT NULL,
  start_seconds DECIMAL(10,3) NULL,
  end_seconds DECIMAL(10,3) NULL,
  screenshot_target_path VARCHAR(500) NULL,
  screenshot_context_path VARCHAR(500) NULL,
  selector_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_capture_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_capture_version FOREIGN KEY(source_version_id) REFERENCES source_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_capture_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS annotations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  source_version_id BIGINT UNSIGNED NOT NULL,
  capture_id BIGINT UNSIGNED NOT NULL,
  text_commentary TEXT NULL,
  audio_commentary_path VARCHAR(500) NULL,
  visibility ENUM('public','team','private') NOT NULL DEFAULT 'public',
  status ENUM('draft','processing','published','restricted','removed') NOT NULL DEFAULT 'published',
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_annotations_source(source_id,published_at),
  INDEX idx_annotations_user(user_id,published_at),
  CONSTRAINT fk_annotation_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_annotation_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_annotation_version FOREIGN KEY(source_version_id) REFERENCES source_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_annotation_capture FOREIGN KEY(capture_id) REFERENCES captures(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  annotation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  parent_comment_id BIGINT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comment_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_parent FOREIGN KEY(parent_comment_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_annotations (
  user_id BIGINT UNSIGNED NOT NULL,
  annotation_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id, annotation_id),
  CONSTRAINT fk_saved_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teams (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_team_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_members (
  team_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('owner','admin','researcher','viewer') NOT NULL DEFAULT 'researcher',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(team_id,user_id),
  CONSTRAINT fk_team_member_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS research_projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_project_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_annotations (
  project_id BIGINT UNSIGNED NOT NULL,
  annotation_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(project_id,annotation_id),
  CONSTRAINT fk_pa_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS live_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  source_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  room_type ENUM('public','team','project') NOT NULL DEFAULT 'public',
  team_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  parent_message_id BIGINT UNSIGNED NULL,
  identity_mode ENUM('visible','team_only','cloaked') NOT NULL DEFAULT 'visible',
  cloak_alias VARCHAR(32) NULL,
  client_message_id VARCHAR(64) NULL,
  body TEXT NOT NULL,
  source_timestamp_seconds DECIMAL(10,3) NULL,
  pinned_at DATETIME NULL,
  pinned_by_user_id BIGINT UNSIGNED NULL,
  deleted_at DATETIME NULL,
  deleted_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_live_client_message(user_id,client_message_id),
  INDEX idx_live_source(source_id,created_at),
  INDEX idx_live_room_cursor(source_id,room_type,team_id,project_id,id),
  INDEX idx_live_parent(parent_message_id),
  CONSTRAINT fk_live_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS rights_claims (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  annotation_id BIGINT UNSIGNED NOT NULL,
  claimant_name VARCHAR(190) NOT NULL,
  claimant_email VARCHAR(190) NOT NULL,
  claim_type VARCHAR(64) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('submitted','under_review','resolved','rejected','restricted') NOT NULL DEFAULT 'submitted',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rights_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Phase 6 — canonical source aliases and per-item feed read state.
CREATE TABLE IF NOT EXISTS source_url_aliases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  normalized_url TEXT NOT NULL,
  url_hash CHAR(64) NOT NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source_url_alias_hash(url_hash),
  INDEX idx_source_url_alias_source(source_id),
  CONSTRAINT fk_source_url_alias_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feed_reads (
  user_id BIGINT UNSIGNED NOT NULL,
  annotation_id BIGINT UNSIGNED NOT NULL,
  first_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,annotation_id),
  INDEX idx_feed_reads_annotation(annotation_id),
  CONSTRAINT fk_feed_read_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_feed_read_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
