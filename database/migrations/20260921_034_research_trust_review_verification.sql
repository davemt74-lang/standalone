-- Annotated Phase 27 — Research Trust, Review & Verification
-- Automated evidence states remain derived from authoritative Research data.
-- Persist only explicit human verification/review events; never persist an opaque trust score.

CREATE TABLE IF NOT EXISTS research_verification_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  subject_type ENUM('claim','finding','report_version') NOT NULL,
  subject_public_id VARCHAR(64) NOT NULL,
  subject_hash CHAR(64) NOT NULL,
  decision ENUM('reviewed_current','needs_review','disputed','abstained') NOT NULL,
  evidence_state_hash CHAR(64) NOT NULL,
  evidence_state_json LONGTEXT NOT NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_verification_project(project_id,created_at),
  INDEX idx_verification_subject(project_id,subject_type,subject_public_id,created_at),
  INDEX idx_verification_reviewer(reviewer_user_id,created_at),
  CONSTRAINT fk_verification_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_verification_reviewer FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
