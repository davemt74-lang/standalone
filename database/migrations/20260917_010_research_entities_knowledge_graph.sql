-- Annotated V1.1 entity extraction and knowledge graph. Keep immutable once applied.
CREATE TABLE IF NOT EXISTS research_entities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  entity_type ENUM('person','company','organization','place','event','topic','date','other') NOT NULL,
  canonical_name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  status ENUM('suggested','confirmed','archived') NOT NULL DEFAULT 'confirmed',
  source_type ENUM('manual','ai') NOT NULL DEFAULT 'manual',
  ai_run_public_id VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_entity_project_name(project_id,entity_type,normalized_name),
  UNIQUE KEY uq_research_entity_id_project(id,project_id),
  INDEX idx_research_entity_project(project_id,status,entity_type,canonical_name),
  CONSTRAINT fk_research_entity_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_entity_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_entity_mentions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  mention_type ENUM('source_version','annotation','claim','finding') NOT NULL,
  source_version_id BIGINT UNSIGNED NULL,
  annotation_id BIGINT UNSIGNED NULL,
  claim_id BIGINT UNSIGNED NULL,
  finding_id BIGINT UNSIGNED NULL,
  excerpt VARCHAR(1000) NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entity_mention(entity_id,mention_type,source_version_id,annotation_id,claim_id,finding_id),
  INDEX idx_entity_mention_project(project_id,created_at),
  INDEX idx_entity_mention_entity(entity_id,created_at),
  CONSTRAINT fk_entity_mention_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_entity_mention_entity_project FOREIGN KEY(entity_id,project_id) REFERENCES research_entities(id,project_id) ON DELETE CASCADE,
  CONSTRAINT fk_entity_mention_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_entity_mention_version FOREIGN KEY(source_version_id) REFERENCES source_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_entity_mention_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE SET NULL,
  CONSTRAINT fk_entity_mention_claim FOREIGN KEY(claim_id) REFERENCES research_claims(id) ON DELETE CASCADE,
  CONSTRAINT fk_entity_mention_finding FOREIGN KEY(finding_id) REFERENCES research_findings(id) ON DELETE CASCADE,
  CONSTRAINT chk_entity_mention_target CHECK (
    (mention_type='source_version' AND source_version_id IS NOT NULL AND annotation_id IS NULL AND claim_id IS NULL AND finding_id IS NULL) OR
    (mention_type='annotation' AND source_version_id IS NOT NULL AND claim_id IS NULL AND finding_id IS NULL) OR
    (mention_type='claim' AND source_version_id IS NULL AND annotation_id IS NULL AND claim_id IS NOT NULL AND finding_id IS NULL) OR
    (mention_type='finding' AND source_version_id IS NULL AND annotation_id IS NULL AND claim_id IS NULL AND finding_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_entity_relations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  source_entity_id BIGINT UNSIGNED NOT NULL,
  target_entity_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('related_to','part_of','located_in','works_for','owns','involved_in','occurred_at','associated_with') NOT NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entity_relation(source_entity_id,target_entity_id,relation_type),
  INDEX idx_entity_relation_project(project_id,created_at),
  INDEX idx_entity_relation_source_project(source_entity_id,project_id),
  INDEX idx_entity_relation_target_project(target_entity_id,project_id),
  CONSTRAINT fk_entity_relation_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_entity_relation_source_project FOREIGN KEY(source_entity_id,project_id) REFERENCES research_entities(id,project_id) ON DELETE CASCADE,
  CONSTRAINT fk_entity_relation_target_project FOREIGN KEY(target_entity_id,project_id) REFERENCES research_entities(id,project_id) ON DELETE CASCADE,
  CONSTRAINT fk_entity_relation_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_entity_relation_distinct CHECK (source_entity_id<>target_entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
