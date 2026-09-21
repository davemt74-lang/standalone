CREATE TABLE IF NOT EXISTS annotation_reactions (
  annotation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reaction VARCHAR(32) NOT NULL DEFAULT 'like',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(annotation_id,user_id,reaction),
  INDEX idx_annotation_reaction_user(user_id,created_at),
  CONSTRAINT fk_annotation_reaction_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_annotation_reaction_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
