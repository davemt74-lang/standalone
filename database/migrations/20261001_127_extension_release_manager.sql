-- Chrome Extension Release Manager V1. Immutable uploads, per-channel publication pointer
-- and append-only operator events. Does not replace the repository's packaged fallback.
CREATE TABLE IF NOT EXISTS extension_releases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 public_id VARCHAR(40) NOT NULL UNIQUE,
 version VARCHAR(32) NOT NULL,
 channel ENUM('stable','beta') NOT NULL DEFAULT 'stable',
 artifact_uri VARCHAR(255) NOT NULL,
 sha256 CHAR(64) NOT NULL,
 size_bytes BIGINT UNSIGNED NOT NULL,
 manifest_json LONGTEXT NOT NULL,
 developer_name VARCHAR(190) NOT NULL,
 developer_url VARCHAR(512) NULL,
 release_notes TEXT NOT NULL,
 build_date DATE NULL,
 uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 published_at DATETIME NULL,
 UNIQUE KEY uq_extension_release_version_channel(version,channel),
 INDEX idx_extension_release_history(channel,created_at),
 CONSTRAINT fk_extension_release_uploader FOREIGN KEY(uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS extension_release_channels (
 channel ENUM('stable','beta') NOT NULL PRIMARY KEY,
 release_id BIGINT UNSIGNED NOT NULL,
 updated_by_user_id BIGINT UNSIGNED NOT NULL,
 published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_extension_release_channel_release FOREIGN KEY(release_id) REFERENCES extension_releases(id) ON DELETE RESTRICT,
 CONSTRAINT fk_extension_release_channel_actor FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS extension_release_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 release_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(48) NOT NULL,
 channel ENUM('stable','beta') NOT NULL,
 from_release_id BIGINT UNSIGNED NULL,
 reason VARCHAR(1000) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_extension_release_event_history(release_id,created_at),
 CONSTRAINT fk_extension_release_event_release FOREIGN KEY(release_id) REFERENCES extension_releases(id) ON DELETE RESTRICT,
 CONSTRAINT fk_extension_release_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_extension_release_event_from FOREIGN KEY(from_release_id) REFERENCES extension_releases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
