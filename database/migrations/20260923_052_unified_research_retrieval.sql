-- Annotated Phase 54 — Unified Research Knowledge & Retrieval

CREATE TABLE IF NOT EXISTS research_retrieval_projects (
  project_id BIGINT UNSIGNED PRIMARY KEY,
  state_hash CHAR(64) NULL,
  status ENUM('pending','indexing','ready','failed') NOT NULL DEFAULT 'pending',
  document_count INT UNSIGNED NOT NULL DEFAULT 0,
  chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  queued_at DATETIME NULL,
  indexed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_retrieval_project_status(status,updated_at),
  CONSTRAINT fk_retrieval_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_retrieval_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  object_type VARCHAR(32) NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  folder_public_id VARCHAR(64) NULL,
  title VARCHAR(255) NOT NULL,
  source_status VARCHAR(32) NOT NULL DEFAULT 'ready',
  content_hash CHAR(64) NOT NULL,
  source_updated_at DATETIME NULL,
  metadata_json JSON NULL,
  indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_retrieval_object(project_id,object_type,object_public_id),
  INDEX idx_retrieval_document_project(project_id,object_type,source_status,updated_at),
  INDEX idx_retrieval_document_folder(project_id,folder_public_id,object_type),
  FULLTEXT KEY ft_retrieval_document_title(title),
  CONSTRAINT fk_retrieval_document_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_retrieval_chunks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  chunk_index INT UNSIGNED NOT NULL,
  locator_type VARCHAR(32) NOT NULL DEFAULT 'section',
  locator_label VARCHAR(190) NULL,
  locator_json JSON NULL,
  heading VARCHAR(255) NULL,
  content MEDIUMTEXT NOT NULL,
  content_hash CHAR(64) NOT NULL,
  token_estimate INT UNSIGNED NOT NULL DEFAULT 0,
  embedding_status ENUM('none','queued','ready','failed') NOT NULL DEFAULT 'none',
  embedding_provider VARCHAR(64) NULL,
  embedding_model VARCHAR(128) NULL,
  embedding_dimensions INT UNSIGNED NULL,
  embedding_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_retrieval_chunk(document_id,chunk_index),
  INDEX idx_retrieval_chunk_document(document_id,chunk_index),
  INDEX idx_retrieval_chunk_embedding(embedding_status,updated_at),
  FULLTEXT KEY ft_retrieval_chunk_text(heading,content),
  CONSTRAINT fk_retrieval_chunk_document FOREIGN KEY(document_id) REFERENCES research_retrieval_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_retrieval_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL UNIQUE,
  status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  claim_token CHAR(32) NULL,
  lease_expires_at DATETIME NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_retrieval_jobs_claim(status,available_at,lease_expires_at,created_at),
  CONSTRAINT fk_retrieval_job_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_retrieval_queries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  query_hash CHAR(64) NOT NULL,
  query_text VARCHAR(1000) NOT NULL,
  retrieval_mode ENUM('browse','lexical','hybrid') NOT NULL DEFAULT 'lexical',
  filters_json JSON NULL,
  result_refs_json JSON NULL,
  result_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_retrieval_query_project(project_id,created_at),
  INDEX idx_retrieval_query_user(user_id,created_at),
  CONSTRAINT fk_retrieval_query_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_retrieval_query_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
