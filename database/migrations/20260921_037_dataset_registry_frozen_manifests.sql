-- Annotated Phase 38 — Dataset Registry & Frozen Dataset Manifests
-- Reproducible dataset snapshots built only from Phase 37 governed corpus items.
-- A frozen manifest remains auditable; current-use eligibility is revalidated separately.

CREATE TABLE IF NOT EXISTS data_datasets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  version_number INT UNSIGNED NOT NULL DEFAULT 1,
  purpose VARCHAR(32) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  description TEXT NULL,
  selection_policy_json JSON NOT NULL,
  selection_policy_hash CHAR(64) NOT NULL,
  manifest_hash CHAR(64) NULL,
  item_count INT UNSIGNED NOT NULL DEFAULT 0,
  content_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  frozen_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  frozen_at DATETIME NULL,
  retired_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_dataset_slug_version(slug,version_number),
  INDEX idx_data_dataset_status(status,purpose,updated_at),
  CONSTRAINT fk_data_dataset_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_dataset_freezer FOREIGN KEY(frozen_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_dataset_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dataset_id BIGINT UNSIGNED NOT NULL,
  corpus_item_id BIGINT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL,
  corpus_public_id VARCHAR(40) NOT NULL,
  source_object_type VARCHAR(64) NOT NULL,
  source_object_public_id VARCHAR(64) NOT NULL,
  source_object_version VARCHAR(64) NULL,
  contributor_user_id BIGINT UNSIGNED NULL,
  corpus_type VARCHAR(64) NOT NULL,
  normalized_text_snapshot MEDIUMTEXT NOT NULL,
  metadata_snapshot_json JSON NULL,
  content_hash CHAR(64) NOT NULL,
  provenance_hash CHAR(64) NOT NULL,
  eligibility_snapshot_json JSON NOT NULL,
  eligibility_snapshot_hash CHAR(64) NOT NULL,
  item_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_dataset_item(dataset_id,corpus_item_id),
  UNIQUE KEY uq_data_dataset_position(dataset_id,position),
  INDEX idx_data_dataset_source(dataset_id,source_object_type,source_object_public_id),
  INDEX idx_data_dataset_contributor(dataset_id,contributor_user_id),
  CONSTRAINT fk_data_dataset_item_dataset FOREIGN KEY(dataset_id) REFERENCES data_datasets(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_dataset_item_corpus FOREIGN KEY(corpus_item_id) REFERENCES data_corpus_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_dataset_item_contributor FOREIGN KEY(contributor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_dataset_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dataset_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_data_dataset_event(dataset_id,created_at),
  CONSTRAINT fk_data_dataset_event_dataset FOREIGN KEY(dataset_id) REFERENCES data_datasets(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_dataset_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
