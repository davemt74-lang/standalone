-- Phase 80 Section 5 — Review, Acceptance, Revision & Disputes

CREATE TABLE IF NOT EXISTS sponsored_research_review_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  submission_version_id BIGINT UNSIGNED NOT NULL,
  opened_by_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('open','in_review','revision_requested','accepted','rejected','disputed','resolved') NOT NULL DEFAULT 'open',
  blind_review TINYINT(1) NOT NULL DEFAULT 1,
  criteria_json JSON NOT NULL,
  criteria_hash CHAR(64) NOT NULL,
  final_decision ENUM('accepted','revision_requested','rejected') NULL,
  decision_note MEDIUMTEXT NULL,
  decided_by_user_id BIGINT UNSIGNED NULL,
  decided_at DATETIME NULL,
  compensation_eligible TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_review_submission_version(submission_version_id),
  INDEX idx_sponsored_review_campaign(campaign_id,status,updated_at),
  CONSTRAINT fk_sponsored_review_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_review_submission FOREIGN KEY(submission_id) REFERENCES sponsored_research_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_review_version FOREIGN KEY(submission_version_id) REFERENCES sponsored_research_submission_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_review_opener FOREIGN KEY(opened_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_review_decider FOREIGN KEY(decided_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_review_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  review_case_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  assigned_by_user_id BIGINT UNSIGNED NOT NULL,
  reviewer_role ENUM('sponsor','independent','subject_matter') NOT NULL DEFAULT 'independent',
  status ENUM('assigned','completed','recused','revoked') NOT NULL DEFAULT 'assigned',
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_sponsored_review_assignment(review_case_id,reviewer_user_id),
  INDEX idx_sponsored_review_assignment_user(reviewer_user_id,status,assigned_at),
  CONSTRAINT fk_sponsored_review_assignment_case FOREIGN KEY(review_case_id) REFERENCES sponsored_research_review_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_review_assignment_user FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_review_assignment_actor FOREIGN KEY(assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_review_responses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  review_case_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  recommendation ENUM('accept','request_revision','reject','abstain') NOT NULL,
  quality_json JSON NOT NULL,
  quality_hash CHAR(64) NOT NULL,
  comment MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_review_response_assignment(assignment_id),
  INDEX idx_sponsored_review_response_case(review_case_id,created_at),
  CONSTRAINT fk_sponsored_review_response_case FOREIGN KEY(review_case_id) REFERENCES sponsored_research_review_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_review_response_assignment FOREIGN KEY(assignment_id) REFERENCES sponsored_research_review_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_review_response_user FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_disputes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  review_case_id BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  opened_by_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('open','under_review','resolved') NOT NULL DEFAULT 'open',
  reason MEDIUMTEXT NOT NULL,
  evidence_json JSON NULL,
  resolution ENUM('upheld','modified','dismissed') NULL,
  resolution_note MEDIUMTEXT NULL,
  resolved_by_user_id BIGINT UNSIGNED NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  INDEX idx_sponsored_dispute_status(status,opened_at),
  INDEX idx_sponsored_dispute_submission(submission_id,opened_at),
  CONSTRAINT fk_sponsored_dispute_review FOREIGN KEY(review_case_id) REFERENCES sponsored_research_review_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_dispute_submission FOREIGN KEY(submission_id) REFERENCES sponsored_research_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_dispute_opener FOREIGN KEY(opened_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_dispute_resolver FOREIGN KEY(resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_review_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  review_case_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_review_event(review_case_id,created_at,id),
  CONSTRAINT fk_sponsored_review_event_case FOREIGN KEY(review_case_id) REFERENCES sponsored_research_review_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_review_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
