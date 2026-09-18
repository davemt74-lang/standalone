-- Annotated V1.1 Phase 10: Search & Discovery Intelligence.
-- Expand-only and retry-safe. Keep immutable once released.

CREATE TABLE IF NOT EXISTS saved_searches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  query_text VARCHAR(500) NOT NULL,
  filters_json JSON NULL,
  visibility ENUM('private','team','project') NOT NULL DEFAULT 'private',
  team_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  alerts_enabled TINYINT(1) NOT NULL DEFAULT 0,
  last_alerted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_saved_search_owner(owner_user_id,updated_at),
  INDEX idx_saved_search_alert(alerts_enabled,last_alerted_at),
  CONSTRAINT fk_saved_search_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_search_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_search_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_search_matches (
  saved_search_id BIGINT UNSIGNED NOT NULL,
  object_type ENUM('source','annotation','research_report','research_entity','person') NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notified_at DATETIME NULL,
  PRIMARY KEY(saved_search_id,object_type,object_public_id),
  INDEX idx_saved_search_match_notify(saved_search_id,notified_at,first_seen_at),
  CONSTRAINT fk_saved_search_match FOREIGN KEY(saved_search_id) REFERENCES saved_searches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_recent_queries (
  user_id BIGINT UNSIGNED NOT NULL,
  query_hash CHAR(64) NOT NULL,
  query_text VARCHAR(500) NOT NULL,
  filters_json JSON NULL,
  use_count INT UNSIGNED NOT NULL DEFAULT 1,
  last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,query_hash),
  INDEX idx_search_recent_user(user_id,last_used_at),
  CONSTRAINT fk_search_recent_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discovery_entities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  entity_type ENUM('person','company','organization','place','event','topic','date','other') NOT NULL,
  canonical_name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  status ENUM('active','merged','hidden') NOT NULL DEFAULT 'active',
  merged_into_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_discovery_entity_name(entity_type,normalized_name),
  INDEX idx_discovery_entity_status(status,entity_type,canonical_name),
  CONSTRAINT fk_discovery_entity_merge FOREIGN KEY(merged_into_id) REFERENCES discovery_entities(id) ON DELETE SET NULL,
  CONSTRAINT fk_discovery_entity_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discovery_entity_aliases (
  entity_id BIGINT UNSIGNED NOT NULL,
  alias_name VARCHAR(255) NOT NULL,
  normalized_alias VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(entity_id,normalized_alias),
  INDEX idx_discovery_alias_lookup(normalized_alias),
  CONSTRAINT fk_discovery_alias_entity FOREIGN KEY(entity_id) REFERENCES discovery_entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discovery_entity_mentions (
  entity_id BIGINT UNSIGNED NOT NULL,
  object_type ENUM('source','annotation','research_report') NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  origin_report_public_id VARCHAR(64) NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  mention_weight DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  excerpt VARCHAR(1000) NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(entity_id,object_type,object_public_id,origin_report_public_id),
  INDEX idx_discovery_mention_object(object_type,object_public_id),
  INDEX idx_discovery_mention_origin(origin_report_public_id),
  INDEX idx_discovery_mention_source(source_id,entity_id),
  CONSTRAINT fk_discovery_mention_entity FOREIGN KEY(entity_id) REFERENCES discovery_entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_discovery_mention_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
