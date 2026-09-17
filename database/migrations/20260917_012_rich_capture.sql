-- Annotated V1.1 rich capture: authorized tab-media uploads and provenance metadata. Keep immutable once applied.
CREATE TABLE IF NOT EXISTS media_uploads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  media_type ENUM('video','audio') NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  expected_bytes BIGINT UNSIGNED NULL,
  received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_bytes BIGINT UNSIGNED NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  checksum CHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_media_upload_user(user_id,consumed_at,expires_at),
  INDEX idx_media_upload_expiry(expires_at,consumed_at),
  CONSTRAINT fk_media_upload_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE captures ADD COLUMN IF NOT EXISTS media_provider VARCHAR(64) NULL AFTER end_seconds;
ALTER TABLE captures ADD COLUMN IF NOT EXISTS provider_media_id VARCHAR(190) NULL AFTER media_provider;
ALTER TABLE captures ADD COLUMN IF NOT EXISTS media_title VARCHAR(500) NULL AFTER provider_media_id;
ALTER TABLE captures ADD COLUMN IF NOT EXISTS media_author VARCHAR(500) NULL AFTER media_title;
ALTER TABLE captures ADD COLUMN IF NOT EXISTS source_media_duration_seconds DECIMAL(10,3) NULL AFTER media_author;
ALTER TABLE captures ADD COLUMN IF NOT EXISTS media_metadata_json JSON NULL AFTER source_media_duration_seconds;
ALTER TABLE captures ADD INDEX IF NOT EXISTS idx_capture_provider(media_provider,provider_media_id);

ALTER TABLE media_jobs ADD COLUMN IF NOT EXISTS input_is_clip TINYINT(1) NOT NULL DEFAULT 0 AFTER input_path;
