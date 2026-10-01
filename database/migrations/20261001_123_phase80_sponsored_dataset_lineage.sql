-- Phase 80 Section 8 — Dataset Integration, Training Consent & Downstream Lineage

CREATE TABLE IF NOT EXISTS sponsored_research_dataset_builds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  knowledge_release_id BIGINT UNSIGNED NOT NULL,
  dataset_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('shared_retrieval','evaluation','training','commercial_training') NOT NULL,
  consent_manifest_json JSON NOT NULL,
  consent_manifest_hash CHAR(64) NOT NULL,
  release_manifest_hash CHAR(64) NOT NULL,
  status ENUM('draft','frozen','blocked','retired') NOT NULL DEFAULT 'draft',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  frozen_at DATETIME NULL,
  blocked_at DATETIME NULL,
  UNIQUE KEY uq_sponsored_dataset_build_dataset(dataset_id),
  INDEX idx_sponsored_dataset_build_release(knowledge_release_id,purpose,status,created_at),
  CONSTRAINT fk_sponsored_dataset_build_release FOREIGN KEY(knowledge_release_id) REFERENCES sponsored_research_knowledge_releases(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_dataset_build_dataset FOREIGN KEY(dataset_id) REFERENCES data_datasets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_dataset_build_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_dataset_build_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  build_id BIGINT UNSIGNED NOT NULL,
  knowledge_item_id BIGINT UNSIGNED NOT NULL,
  contributor_user_id BIGINT UNSIGNED NULL,
  usage_grant_id BIGINT UNSIGNED NOT NULL,
  usage_grant_hash CHAR(64) NOT NULL,
  source_rights_hash CHAR(64) NOT NULL,
  corpus_item_id BIGINT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_sponsored_dataset_build_item(build_id,knowledge_item_id),
  UNIQUE KEY uq_sponsored_dataset_build_position(build_id,position),
  INDEX idx_sponsored_dataset_build_contributor(contributor_user_id),
  CONSTRAINT fk_sponsored_dataset_build_item_build FOREIGN KEY(build_id) REFERENCES sponsored_research_dataset_builds(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_dataset_build_item_knowledge FOREIGN KEY(knowledge_item_id) REFERENCES sponsored_research_knowledge_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_dataset_build_item_contributor FOREIGN KEY(contributor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_dataset_build_item_grant FOREIGN KEY(usage_grant_id) REFERENCES data_usage_grants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_dataset_build_item_corpus FOREIGN KEY(corpus_item_id) REFERENCES data_corpus_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_dataset_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  build_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_dataset_event(build_id,created_at,id),
  CONSTRAINT fk_sponsored_dataset_event_build FOREIGN KEY(build_id) REFERENCES sponsored_research_dataset_builds(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_dataset_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
