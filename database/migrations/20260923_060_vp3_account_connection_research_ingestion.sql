-- Annotated Phase 63 — VP3 Account Connection & Research Ingestion
-- VP3 remains authoritative for VP3 account access and source artifacts.
-- Annotated stores revocable connection credentials plus explicit Research snapshots.

CREATE TABLE IF NOT EXISTS vp3_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  vp3_user_id VARCHAR(190) NOT NULL,
  vp3_display_name VARCHAR(190) NULL,
  vp3_email VARCHAR(190) NULL,
  status ENUM('active','disconnected') NOT NULL DEFAULT 'active',
  scopes_json JSON NOT NULL,
  access_token_cipher LONGTEXT NULL,
  refresh_token_cipher LONGTEXT NULL,
  access_expires_at DATETIME NULL,
  refresh_expires_at DATETIME NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  last_checked_at DATETIME NULL,
  disconnected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vp3_connection_user(user_id),
  UNIQUE KEY uq_vp3_connection_remote(vp3_user_id),
  INDEX idx_vp3_connection_status(status,updated_at),
  CONSTRAINT fk_vp3_connection_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vp3_imports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  remote_artifact_type VARCHAR(60) NOT NULL DEFAULT 'meeting',
  remote_artifact_id VARCHAR(190) NOT NULL,
  remote_version_hash CHAR(64) NOT NULL,
  remote_updated_at DATETIME NULL,
  original_url TEXT NULL,
  title VARCHAR(500) NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  source_version_id BIGINT UNSIGNED NULL,
  transcript_document_object_id BIGINT UNSIGNED NULL,
  summary_document_object_id BIGINT UNSIGNED NULL,
  imported_parts_json JSON NOT NULL,
  status ENUM('current','update_available','source_unavailable') NOT NULL DEFAULT 'current',
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_checked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vp3_import_project_artifact(project_id,remote_artifact_type,remote_artifact_id),
  INDEX idx_vp3_import_user(user_id,status,updated_at),
  CONSTRAINT fk_vp3_import_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vp3_import_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_vp3_import_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE SET NULL,
  CONSTRAINT fk_vp3_import_source_version FOREIGN KEY(source_version_id) REFERENCES source_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_vp3_import_transcript_doc FOREIGN KEY(transcript_document_object_id) REFERENCES research_workspace_objects(id) ON DELETE SET NULL,
  CONSTRAINT fk_vp3_import_summary_doc FOREIGN KEY(summary_document_object_id) REFERENCES research_workspace_objects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
