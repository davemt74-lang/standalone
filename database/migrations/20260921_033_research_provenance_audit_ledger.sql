-- Annotated Phase 26 — Research Provenance & Audit Ledger
-- Provenance is derived from authoritative Research state.
-- Persist only explicit immutable audit receipts requested by a user.

CREATE TABLE IF NOT EXISTS research_audit_receipts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  scope_type ENUM('project','report_version') NOT NULL DEFAULT 'project',
  report_version_id BIGINT UNSIGNED NULL,
  manifest_hash CHAR(64) NOT NULL,
  manifest_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_receipt_project(project_id,created_at),
  INDEX idx_audit_receipt_report_version(report_version_id,created_at),
  CONSTRAINT fk_audit_receipt_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_audit_receipt_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_audit_receipt_report_version FOREIGN KEY(report_version_id) REFERENCES research_report_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
