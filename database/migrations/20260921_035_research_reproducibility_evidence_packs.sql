-- Annotated Phase 28 — Research Reproducibility & Evidence Packs
-- Evidence Packs are explicit immutable snapshots of permission-scoped Research state.
-- Current Research remains authoritative; packs are replayable records, never editable working copies.

CREATE TABLE IF NOT EXISTS research_evidence_packs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  scope_type ENUM('project','claim','finding','report_version') NOT NULL,
  scope_public_id VARCHAR(64) NOT NULL,
  scope_hash CHAR(64) NOT NULL,
  manifest_hash CHAR(64) NOT NULL,
  manifest_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_evidence_pack_project(project_id,created_at),
  INDEX idx_evidence_pack_scope(project_id,scope_type,scope_public_id,created_at),
  INDEX idx_evidence_pack_creator(created_by_user_id,created_at),
  CONSTRAINT fk_evidence_pack_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_evidence_pack_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
