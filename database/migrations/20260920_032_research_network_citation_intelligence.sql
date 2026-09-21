-- Annotated Phase 25 — Research Network & Citation Intelligence
-- Working-project references are explicit user state.
-- Published citation edges are immutable indexes of version-pinned report snapshots.

CREATE TABLE IF NOT EXISTS research_project_report_references (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  target_report_id BIGINT UNSIGNED NOT NULL,
  target_version_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('background','supports','contrasts','extends','method','context') NOT NULL DEFAULT 'context',
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project_report_reference(project_id,target_version_id),
  INDEX idx_project_report_reference_target(target_version_id,project_id),
  CONSTRAINT fk_project_report_reference_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_report_reference_report FOREIGN KEY(target_report_id) REFERENCES research_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_report_reference_version FOREIGN KEY(target_version_id) REFERENCES research_report_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_report_reference_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_report_version_citations (
  source_version_id BIGINT UNSIGNED NOT NULL,
  target_version_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('background','supports','contrasts','extends','method','context') NOT NULL DEFAULT 'context',
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(source_version_id,target_version_id),
  INDEX idx_report_citation_target(target_version_id,source_version_id),
  CONSTRAINT fk_report_citation_source FOREIGN KEY(source_version_id) REFERENCES research_report_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_citation_target FOREIGN KEY(target_version_id) REFERENCES research_report_versions(id) ON DELETE CASCADE,
  CONSTRAINT chk_report_citation_not_self CHECK (source_version_id<>target_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
