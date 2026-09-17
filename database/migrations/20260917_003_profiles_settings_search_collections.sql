-- Annotated V1 beta identity, privacy, saved collections, and search support.
ALTER TABLE users ADD COLUMN IF NOT EXISTS bio TEXT NULL AFTER display_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS website_url VARCHAR(500) NULL AFTER bio;
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_image_url VARCHAR(500) NULL AFTER website_url;

CREATE TABLE IF NOT EXISTS user_preferences (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  profile_visibility ENUM('public','private') NOT NULL DEFAULT 'public',
  search_visibility TINYINT(1) NOT NULL DEFAULT 1,
  default_annotation_visibility ENUM('public','team','private') NOT NULL DEFAULT 'public',
  notify_social TINYINT(1) NOT NULL DEFAULT 1,
  notify_research TINYINT(1) NOT NULL DEFAULT 1,
  notify_sources TINYINT(1) NOT NULL DEFAULT 1,
  notify_live TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_preferences_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  visibility ENUM('private','public') NOT NULL DEFAULT 'private',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_collection_owner(owner_user_id,updated_at),
  CONSTRAINT fk_collection_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_items (
  collection_id BIGINT UNSIGNED NOT NULL,
  annotation_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(collection_id,annotation_id),
  INDEX idx_collection_item_annotation(annotation_id),
  CONSTRAINT fk_collection_item_collection FOREIGN KEY(collection_id) REFERENCES collections(id) ON DELETE CASCADE,
  CONSTRAINT fk_collection_item_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_collection_item_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
