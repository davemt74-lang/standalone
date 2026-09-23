-- Annotated Phase 53 — Research Desktop Files, Uploads & Recordings

CREATE TABLE IF NOT EXISTS research_workspace_uploads (
  object_id BIGINT UNSIGNED PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  storage_uri VARCHAR(500) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(160) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  checksum CHAR(64) NOT NULL,
  processing_status ENUM('queued','extracting','ready','blocked','failed') NOT NULL DEFAULT 'queued',
  extracted_text MEDIUMTEXT NULL,
  page_count INT UNSIGNED NULL,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rw_upload_project(project_id,processing_status,updated_at),
  INDEX idx_rw_upload_checksum(project_id,checksum),
  FULLTEXT KEY ft_rw_upload_text(extracted_text),
  CONSTRAINT fk_rw_upload_object FOREIGN KEY(object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE,
  CONSTRAINT fk_rw_upload_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_workspace_recordings (
  object_id BIGINT UNSIGNED PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  storage_uri VARCHAR(500) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(160) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  checksum CHAR(64) NOT NULL,
  duration_seconds DECIMAL(12,3) NULL,
  recording_source ENUM('browser','upload') NOT NULL DEFAULT 'browser',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rw_recording_project(project_id,updated_at),
  CONSTRAINT fk_rw_recording_object FOREIGN KEY(object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE,
  CONSTRAINT fk_rw_recording_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_workspace_recording_transcripts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  object_id BIGINT UNSIGNED NOT NULL UNIQUE,
  status ENUM('queued','processing','ready','blocked','failed') NOT NULL DEFAULT 'queued',
  language VARCHAR(32) NULL,
  provider VARCHAR(64) NULL,
  model VARCHAR(128) NULL,
  raw_text MEDIUMTEXT NULL,
  edited_text MEDIUMTEXT NULL,
  confidence DECIMAL(6,5) NULL,
  segments_json JSON NULL,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FULLTEXT KEY ft_rw_recording_transcript(raw_text,edited_text),
  CONSTRAINT fk_rw_recording_transcript_object FOREIGN KEY(object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_file_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  object_id BIGINT UNSIGNED NOT NULL UNIQUE,
  input_path VARCHAR(500) NOT NULL,
  status ENUM('queued','processing','done','blocked','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  claim_token CHAR(32) NULL,
  lease_expires_at DATETIME NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_research_file_jobs_claim(status,available_at,lease_expires_at,created_at),
  CONSTRAINT fk_research_file_job_object FOREIGN KEY(object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_transcription_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transcript_id BIGINT UNSIGNED NOT NULL UNIQUE,
  input_path VARCHAR(500) NOT NULL,
  status ENUM('queued','processing','done','blocked','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  claim_token CHAR(32) NULL,
  lease_expires_at DATETIME NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_research_transcription_claim(status,available_at,lease_expires_at,created_at),
  CONSTRAINT fk_research_transcription_job FOREIGN KEY(transcript_id) REFERENCES research_workspace_recording_transcripts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
