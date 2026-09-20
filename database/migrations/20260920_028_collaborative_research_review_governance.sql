-- Annotated Phase 21 — Collaborative Research Review & Governance
-- Review responses/comments/events are append-only. Completed review snapshots are immutable decision records.

CREATE TABLE IF NOT EXISTS research_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NOT NULL,
  subject_type ENUM('claim','finding','report_version','agent_action') NOT NULL,
  subject_public_id VARCHAR(64) NOT NULL,
  subject_hash CHAR(64) NOT NULL,
  subject_version_label VARCHAR(120) NULL,
  title VARCHAR(255) NOT NULL,
  instructions TEXT NULL,
  status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
  due_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  completion_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_review_project(project_id,status,due_at),
  INDEX idx_research_review_requester(requested_by_user_id,status,created_at),
  INDEX idx_research_review_subject(subject_type,subject_public_id,status),
  CONSTRAINT fk_research_review_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_requester FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_review_assignments (
  review_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  assigned_by_user_id BIGINT UNSIGNED NOT NULL,
  latest_response_id BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME NULL,
  PRIMARY KEY(review_id,reviewer_user_id),
  INDEX idx_research_review_assignment_user(reviewer_user_id,responded_at,assigned_at),
  CONSTRAINT fk_research_review_assignment_review FOREIGN KEY(review_id) REFERENCES research_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_assignment_reviewer FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_assignment_assigner FOREIGN KEY(assigned_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_review_responses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  review_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  decision ENUM('approve','request_changes','disagree','abstain') NOT NULL,
  comment TEXT NULL,
  subject_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_review_response_review(review_id,id),
  INDEX idx_research_review_response_user(reviewer_user_id,created_at),
  CONSTRAINT fk_research_review_response_review FOREIGN KEY(review_id) REFERENCES research_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_response_reviewer FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_review_assignments
  ADD CONSTRAINT fk_research_review_assignment_latest_response FOREIGN KEY(latest_response_id) REFERENCES research_review_responses(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS research_review_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  review_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_review_comment(review_id,id),
  CONSTRAINT fk_research_review_comment_review FOREIGN KEY(review_id) REFERENCES research_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_comment_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_review_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  review_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(48) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_review_event(review_id,id),
  CONSTRAINT fk_research_review_event_review FOREIGN KEY(review_id) REFERENCES research_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_automations
  MODIFY COLUMN workflow_type ENUM('briefing','review','source_refresh','cross_research_review','outcome_review','review_digest') NOT NULL;
