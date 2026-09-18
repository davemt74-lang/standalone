-- Annotated V1.1 Phase 6 — source identity aliases and feed read state.
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
