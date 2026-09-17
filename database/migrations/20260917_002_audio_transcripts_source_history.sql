-- Annotated V1 audio commentary transcription, source history, and team Live additions.
CREATE TABLE IF NOT EXISTS annotation_transcripts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  annotation_id BIGINT UNSIGNED NOT NULL,
  status ENUM('queued','processing','ready','failed','blocked') NOT NULL DEFAULT 'queued',
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
  UNIQUE KEY uq_annotation_transcript(annotation_id),
  INDEX idx_transcript_status(status,updated_at),
  FULLTEXT KEY ft_transcript_text(raw_text,edited_text),
  CONSTRAINT fk_transcript_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transcription_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transcript_id BIGINT UNSIGNED NOT NULL,
  input_path VARCHAR(500) NOT NULL,
  status ENUM('queued','processing','done','blocked','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_transcription_job(transcript_id),
  INDEX idx_transcription_jobs_status(status,created_at),
  CONSTRAINT fk_transcription_job_transcript FOREIGN KEY(transcript_id) REFERENCES annotation_transcripts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_change_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  previous_version_id BIGINT UNSIGNED NULL,
  new_version_id BIGINT UNSIGNED NOT NULL,
  change_type ENUM('updated','edited','moved','unavailable') NOT NULL,
  target_changed TINYINT(1) NOT NULL DEFAULT 0,
  diff_summary VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_source_change_source(source_id,created_at),
  CONSTRAINT fk_source_change_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_source_change_previous FOREIGN KEY(previous_version_id) REFERENCES source_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_source_change_new FOREIGN KEY(new_version_id) REFERENCES source_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_watches (
  source_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(source_id,user_id),
  CONSTRAINT fk_source_watch_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_source_watch_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
