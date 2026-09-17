-- Annotated V1.1 research publishing and immutable report snapshots. Keep immutable once applied.
CREATE TABLE IF NOT EXISTS research_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL UNIQUE,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  visibility ENUM('public','team','private') NOT NULL DEFAULT 'private',
  status ENUM('published','unpublished','archived') NOT NULL DEFAULT 'published',
  current_version_id BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_report_visibility(status,visibility,published_at),
  CONSTRAINT fk_research_report_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_report_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_report_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  report_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  published_by_user_id BIGINT UNSIGNED NOT NULL,
  visibility ENUM('public','team','private') NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  snapshot_json LONGTEXT NOT NULL,
  snapshot_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_report_version(report_id,version_number),
  INDEX idx_research_report_version_created(report_id,created_at),
  CONSTRAINT fk_research_report_version_report FOREIGN KEY(report_id) REFERENCES research_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_report_version_user FOREIGN KEY(published_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
