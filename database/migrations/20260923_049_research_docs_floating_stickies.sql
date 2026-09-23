-- Annotated Phase 50B — Research Docs + Floating Sticky Notes

CREATE TABLE IF NOT EXISTS research_workspace_documents (
  object_id BIGINT UNSIGNED PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(40) NOT NULL DEFAULT 'document',
  content_html MEDIUMTEXT NULL,
  plain_text MEDIUMTEXT NULL,
  summary TEXT NULL,
  revision_number INT UNSIGNED NOT NULL DEFAULT 1,
  content_hash CHAR(64) NOT NULL,
  created_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  last_edited_by_user_id BIGINT UNSIGNED NULL,
  last_edited_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_workspace_doc_project(project_id,updated_at),
  INDEX idx_research_workspace_doc_editor(last_edited_by_user_id,last_edited_at),
  CONSTRAINT fk_research_workspace_doc_object FOREIGN KEY(object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_workspace_doc_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_workspace_doc_editor FOREIGN KEY(last_edited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_workspace_document_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  document_object_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  title VARCHAR(240) NOT NULL,
  content_html MEDIUMTEXT NULL,
  plain_text MEDIUMTEXT NULL,
  summary TEXT NULL,
  edited_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_workspace_doc_revision(document_object_id,revision_number),
  INDEX idx_research_workspace_doc_revision_created(document_object_id,created_at),
  CONSTRAINT fk_research_workspace_doc_revision_object FOREIGN KEY(document_object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_workspace_doc_revision_editor FOREIGN KEY(edited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_workspace_stickies (
  object_id BIGINT UNSIGNED PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT 'yellow',
  position_x INT NOT NULL DEFAULT 32,
  position_y INT NOT NULL DEFAULT 96,
  width_px INT NOT NULL DEFAULT 240,
  height_px INT NOT NULL DEFAULT 190,
  z_index INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_workspace_sticky_project(project_id,z_index,updated_at),
  CONSTRAINT fk_research_workspace_sticky_object FOREIGN KEY(object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_workspace_sticky_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
