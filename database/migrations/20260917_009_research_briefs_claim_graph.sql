-- Annotated V1.1 Research Brief, Timeline, and Claim Graph. Keep immutable once applied.
CREATE TABLE IF NOT EXISTS claim_relations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  source_claim_id BIGINT UNSIGNED NOT NULL,
  target_claim_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('supports','contradicts','depends_on','refines','duplicates','context') NOT NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_claim_relation(source_claim_id,target_claim_id,relation_type),
  INDEX idx_claim_relation_project(project_id,created_at),
  INDEX idx_claim_relation_target(target_claim_id,created_at),
  CONSTRAINT fk_claim_relation_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_relation_source FOREIGN KEY(source_claim_id) REFERENCES research_claims(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_relation_target FOREIGN KEY(target_claim_id) REFERENCES research_claims(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_relation_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_claim_relation_distinct CHECK (source_claim_id<>target_claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_briefs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  generated_by ENUM('deterministic','ai') NOT NULL DEFAULT 'deterministic',
  status ENUM('current','archived') NOT NULL DEFAULT 'current',
  summary MEDIUMTEXT NOT NULL,
  key_evidence MEDIUMTEXT NULL,
  conflicts MEDIUMTEXT NULL,
  gaps MEDIUMTEXT NULL,
  next_steps MEDIUMTEXT NULL,
  snapshot_json JSON NULL,
  ai_run_public_id VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_brief_project(project_id,status,created_at),
  CONSTRAINT fk_research_brief_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_brief_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
