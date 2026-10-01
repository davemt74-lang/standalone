-- Phase 80 Section 3 — Campaign Participation, Eligibility & Terms

CREATE TABLE IF NOT EXISTS sponsored_research_campaign_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  invited_user_id BIGINT UNSIGNED NOT NULL,
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','declined','revoked','expired') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NULL,
  accepted_at DATETIME NULL,
  declined_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_campaign_invite(campaign_id,invited_user_id),
  INDEX idx_sponsored_campaign_invite_user(invited_user_id,status,expires_at),
  CONSTRAINT fk_sponsored_campaign_invite_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_campaign_invite_user FOREIGN KEY(invited_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_campaign_invite_actor FOREIGN KEY(invited_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_campaign_terms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  campaign_revision INT UNSIGNED NOT NULL,
  terms_text MEDIUMTEXT NOT NULL,
  terms_hash CHAR(64) NOT NULL,
  requires_conflict_disclosure TINYINT(1) NOT NULL DEFAULT 1,
  requires_nda TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_campaign_terms_version(campaign_id,version_number),
  INDEX idx_sponsored_campaign_terms_current(campaign_id,created_at,id),
  CONSTRAINT fk_sponsored_campaign_terms_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_campaign_terms_actor FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_participations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NOT NULL,
  research_profile_id BIGINT UNSIGNED NOT NULL,
  invite_id BIGINT UNSIGNED NULL,
  status ENUM('active','withdrawn','removed','completed') NOT NULL DEFAULT 'active',
  campaign_revision_accepted INT UNSIGNED NOT NULL,
  terms_id BIGINT UNSIGNED NOT NULL,
  eligibility_snapshot_json JSON NOT NULL,
  conflict_disclosure TEXT NULL,
  nda_accepted TINYINT(1) NOT NULL DEFAULT 0,
  sponsorship_disclosure_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  withdrawn_at DATETIME NULL,
  removed_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_campaign_participant(campaign_id,researcher_user_id),
  INDEX idx_sponsored_participant_user(researcher_user_id,status,updated_at),
  INDEX idx_sponsored_participant_campaign(campaign_id,status,updated_at),
  CONSTRAINT fk_sponsored_participation_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_participation_user FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_participation_profile FOREIGN KEY(research_profile_id) REFERENCES research_account_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_participation_invite FOREIGN KEY(invite_id) REFERENCES sponsored_research_campaign_invites(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_participation_terms FOREIGN KEY(terms_id) REFERENCES sponsored_research_campaign_terms(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_participation_acceptances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  participation_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NOT NULL,
  terms_id BIGINT UNSIGNED NOT NULL,
  campaign_revision INT UNSIGNED NOT NULL,
  eligibility_snapshot_json JSON NOT NULL,
  conflict_disclosure TEXT NULL,
  nda_accepted TINYINT(1) NOT NULL DEFAULT 0,
  sponsorship_disclosure_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
  accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_acceptance_participation(participation_id,accepted_at,id),
  INDEX idx_sponsored_acceptance_campaign(campaign_id,accepted_at,id),
  INDEX idx_sponsored_acceptance_researcher(researcher_user_id,accepted_at,id),
  CONSTRAINT fk_sponsored_acceptance_participation FOREIGN KEY(participation_id) REFERENCES sponsored_research_participations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_acceptance_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_acceptance_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_acceptance_terms FOREIGN KEY(terms_id) REFERENCES sponsored_research_campaign_terms(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_participation_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  participation_id BIGINT UNSIGNED NULL,
  researcher_user_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_participation_event_campaign(campaign_id,created_at,id),
  INDEX idx_sponsored_participation_event_user(researcher_user_id,created_at,id),
  CONSTRAINT fk_sponsored_part_event_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_part_event_participation FOREIGN KEY(participation_id) REFERENCES sponsored_research_participations(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_part_event_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_part_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
