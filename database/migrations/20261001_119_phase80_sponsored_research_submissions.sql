-- Phase 80 Section 4 — Sponsored Research Submission & Provenance

CREATE TABLE IF NOT EXISTS sponsored_research_submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  participation_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary MEDIUMTEXT NOT NULL,
  methodology MEDIUMTEXT NULL,
  limitations MEDIUMTEXT NULL,
  status ENUM('draft','submitted','revision_requested','accepted','rejected','withdrawn') NOT NULL DEFAULT 'draft',
  current_revision INT UNSIGNED NOT NULL DEFAULT 0,
  latest_snapshot_hash CHAR(64) NULL,
  submitted_at DATETIME NULL,
  withdrawn_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_submission_participation(participation_id),
  INDEX idx_sponsored_submission_campaign(campaign_id,status,updated_at),
  INDEX idx_sponsored_submission_researcher(researcher_user_id,status,updated_at),
  CONSTRAINT fk_sponsored_submission_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_submission_participation FOREIGN KEY(participation_id) REFERENCES sponsored_research_participations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_submission_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_submission_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  submission_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  campaign_revision INT UNSIGNED NOT NULL,
  participation_acceptance_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary MEDIUMTEXT NOT NULL,
  methodology MEDIUMTEXT NULL,
  limitations MEDIUMTEXT NULL,
  snapshot_json JSON NOT NULL,
  snapshot_hash CHAR(64) NOT NULL,
  submitted_by_user_id BIGINT UNSIGNED NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_submission_revision(submission_id,revision_number),
  INDEX idx_sponsored_submission_version_hash(snapshot_hash),
  CONSTRAINT fk_sponsored_submission_version_submission FOREIGN KEY(submission_id) REFERENCES sponsored_research_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_submission_version_acceptance FOREIGN KEY(participation_acceptance_id) REFERENCES sponsored_research_participation_acceptances(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_submission_version_user FOREIGN KEY(submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_submission_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  submission_version_id BIGINT UNSIGNED NOT NULL,
  asset_type ENUM('annotation','claim','finding','report_version','source','mission','dataset') NOT NULL,
  asset_public_id VARCHAR(64) NOT NULL,
  asset_version VARCHAR(64) NULL,
  contributor_user_id BIGINT UNSIGNED NULL,
  data_contribution_id BIGINT UNSIGNED NULL,
  snapshot_json JSON NOT NULL,
  snapshot_hash CHAR(64) NOT NULL,
  source_rights_snapshot_json JSON NULL,
  source_rights_snapshot_hash CHAR(64) NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_submission_asset(submission_version_id,asset_type,asset_public_id,asset_version),
  INDEX idx_sponsored_submission_asset_object(asset_type,asset_public_id,asset_version),
  INDEX idx_sponsored_submission_asset_contributor(contributor_user_id,created_at),
  CONSTRAINT fk_sponsored_submission_asset_version FOREIGN KEY(submission_version_id) REFERENCES sponsored_research_submission_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_submission_asset_contributor FOREIGN KEY(contributor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_submission_asset_contribution FOREIGN KEY(data_contribution_id) REFERENCES data_contributions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_submission_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  submission_id BIGINT UNSIGNED NOT NULL,
  submission_version_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_submission_event(submission_id,created_at,id),
  CONSTRAINT fk_sponsored_submission_event_submission FOREIGN KEY(submission_id) REFERENCES sponsored_research_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_submission_event_version FOREIGN KEY(submission_version_id) REFERENCES sponsored_research_submission_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_submission_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
