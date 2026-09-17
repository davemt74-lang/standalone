-- Annotated V1.1 Research Intelligence & Knowledge Layer. Keep immutable once applied.
CREATE TABLE IF NOT EXISTS research_claims (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  statement TEXT NOT NULL,
  claim_type ENUM('factual','disputed','prediction','interpretation','data_point') NOT NULL DEFAULT 'factual',
  status ENUM('unverified','supported','disputed','contradicted','resolved') NOT NULL DEFAULT 'unverified',
  resolution_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_claim_project(project_id,status,created_at),
  CONSTRAINT fk_research_claim_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_claim_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claim_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  claim_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  evidence_type ENUM('annotation','source_version') NOT NULL,
  annotation_id BIGINT UNSIGNED NULL,
  source_version_id BIGINT UNSIGNED NOT NULL,
  relationship ENUM('supports','contradicts','context','primary') NOT NULL DEFAULT 'supports',
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_claim_evidence_claim(claim_id,created_at),
  INDEX idx_claim_evidence_annotation(annotation_id),
  INDEX idx_claim_evidence_version(source_version_id),
  CONSTRAINT fk_claim_evidence_claim FOREIGN KEY(claim_id) REFERENCES research_claims(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_evidence_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_evidence_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE SET NULL,
  CONSTRAINT fk_claim_evidence_version FOREIGN KEY(source_version_id) REFERENCES source_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_findings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(250) NOT NULL,
  summary MEDIUMTEXT NOT NULL,
  status ENUM('draft','final','archived') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_finding_project(project_id,status,updated_at),
  CONSTRAINT fk_research_finding_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_finding_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finding_claims (
  finding_id BIGINT UNSIGNED NOT NULL,
  claim_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  relationship ENUM('primary','supports','contradicts','context') NOT NULL DEFAULT 'supports',
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(finding_id,claim_id),
  INDEX idx_finding_claim_claim(claim_id),
  CONSTRAINT fk_finding_claim_finding FOREIGN KEY(finding_id) REFERENCES research_findings(id) ON DELETE CASCADE,
  CONSTRAINT fk_finding_claim_claim FOREIGN KEY(claim_id) REFERENCES research_claims(id) ON DELETE CASCADE,
  CONSTRAINT fk_finding_claim_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
