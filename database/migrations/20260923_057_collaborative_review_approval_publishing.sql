-- Annotated Phase 59 — Collaborative Review, Approval & Publishing
-- Extends Phase 21 reviews and Phase 24 immutable report versions.
-- Review responses/messages/events and published report versions remain append-only audit records.

ALTER TABLE research_reviews
  MODIFY COLUMN subject_type ENUM('claim','finding','report_version','agent_action','document') NOT NULL;

ALTER TABLE research_review_assignments
  ADD COLUMN reviewer_role ENUM('reviewer','approver') NOT NULL DEFAULT 'reviewer' AFTER assigned_by_user_id,
  ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 1 AFTER reviewer_role;

CREATE TABLE IF NOT EXISTS research_publication_workflows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_id BIGINT UNSIGNED NOT NULL,
  document_object_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  research_agent_id BIGINT UNSIGNED NULL,
  source_plan_id BIGINT UNSIGNED NULL,
  source_program_run_id BIGINT UNSIGNED NULL,
  current_review_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  visibility ENUM('public','team','private') NOT NULL DEFAULT 'private',
  topics_json JSON NULL,
  status ENUM('draft','in_review','changes_requested','approved','published','archived') NOT NULL DEFAULT 'draft',
  current_round INT UNSIGNED NOT NULL DEFAULT 0,
  document_revision_number INT UNSIGNED NOT NULL,
  document_revision_public_id VARCHAR(40) NOT NULL,
  document_hash CHAR(64) NOT NULL,
  policy_json JSON NOT NULL,
  owner_approved_by_user_id BIGINT UNSIGNED NULL,
  owner_approved_at DATETIME NULL,
  approval_snapshot_json JSON NULL,
  published_report_id BIGINT UNSIGNED NULL,
  published_version_id BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_publication_workflow_project(project_id,status,updated_at),
  INDEX idx_research_publication_workflow_document(document_object_id,status,updated_at),
  INDEX idx_research_publication_workflow_review(current_review_id),
  INDEX idx_research_publication_workflow_plan(source_plan_id),
  INDEX idx_research_publication_workflow_program(source_program_run_id),
  CONSTRAINT fk_research_pub_workflow_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_workflow_document FOREIGN KEY(document_object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_workflow_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_workflow_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_pub_workflow_plan FOREIGN KEY(source_plan_id) REFERENCES research_task_plans(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_pub_workflow_program FOREIGN KEY(source_program_run_id) REFERENCES research_program_runs(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_pub_workflow_review FOREIGN KEY(current_review_id) REFERENCES research_reviews(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_pub_workflow_owner_approval FOREIGN KEY(owner_approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_pub_workflow_report FOREIGN KEY(published_report_id) REFERENCES research_reports(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_pub_workflow_version FOREIGN KEY(published_version_id) REFERENCES research_report_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_publication_review_rounds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  workflow_id BIGINT UNSIGNED NOT NULL,
  round_number INT UNSIGNED NOT NULL,
  review_id BIGINT UNSIGNED NOT NULL,
  document_revision_number INT UNSIGNED NOT NULL,
  document_revision_public_id VARCHAR(40) NOT NULL,
  document_hash CHAR(64) NOT NULL,
  started_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_publication_round(workflow_id,round_number),
  UNIQUE KEY uq_research_publication_review(review_id),
  CONSTRAINT fk_research_pub_round_workflow FOREIGN KEY(workflow_id) REFERENCES research_publication_workflows(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_round_review FOREIGN KEY(review_id) REFERENCES research_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_round_user FOREIGN KEY(started_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_review_threads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  review_id BIGINT UNSIGNED NOT NULL,
  workflow_id BIGINT UNSIGNED NULL,
  anchor_type ENUM('general','document_section','claim','source','citation','task','program_delta') NOT NULL DEFAULT 'general',
  anchor_public_id VARCHAR(64) NULL,
  locator_json JSON NULL,
  title VARCHAR(255) NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  resolved_by_user_id BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_review_thread_review(review_id,status,id),
  INDEX idx_research_review_thread_workflow(workflow_id,status,id),
  INDEX idx_research_review_thread_anchor(anchor_type,anchor_public_id),
  CONSTRAINT fk_research_review_thread_review FOREIGN KEY(review_id) REFERENCES research_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_thread_workflow FOREIGN KEY(workflow_id) REFERENCES research_publication_workflows(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_thread_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_thread_resolver FOREIGN KEY(resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_review_thread_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  thread_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_review_thread_message(thread_id,id),
  CONSTRAINT fk_research_review_thread_message_thread FOREIGN KEY(thread_id) REFERENCES research_review_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_review_thread_message_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_publication_approval_gates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workflow_id BIGINT UNSIGNED NOT NULL,
  gate_type ENUM('required_approvals','owner_approval','review_complete','no_unresolved_threads','no_failed_task_gates','no_high_contradictions','fresh_evidence') NOT NULL,
  required_json JSON NOT NULL,
  status ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
  detail VARCHAR(1000) NULL,
  evaluated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_publication_gate(workflow_id,gate_type),
  CONSTRAINT fk_research_pub_gate_workflow FOREIGN KEY(workflow_id) REFERENCES research_publication_workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_publication_distribution_targets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workflow_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('user','team','subscribers') NOT NULL,
  target_user_id BIGINT UNSIGNED NULL,
  target_team_id BIGINT UNSIGNED NULL,
  notify_in_app TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_pub_target_workflow(workflow_id,target_type),
  CONSTRAINT fk_research_pub_target_workflow FOREIGN KEY(workflow_id) REFERENCES research_publication_workflows(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_target_user FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_target_team FOREIGN KEY(target_team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_target_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_publication_distribution_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  workflow_id BIGINT UNSIGNED NOT NULL,
  report_version_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('user','team','subscribers') NOT NULL,
  target_user_id BIGINT UNSIGNED NULL,
  target_team_id BIGINT UNSIGNED NULL,
  status ENUM('sent','skipped','failed') NOT NULL,
  detail VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_pub_distribution_workflow(workflow_id,created_at),
  CONSTRAINT fk_research_pub_distribution_workflow FOREIGN KEY(workflow_id) REFERENCES research_publication_workflows(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_distribution_version FOREIGN KEY(report_version_id) REFERENCES research_report_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_pub_distribution_user FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_pub_distribution_team FOREIGN KEY(target_team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_publication_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  workflow_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_type ENUM('user','agent','system') NOT NULL DEFAULT 'system',
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_publication_event(workflow_id,id),
  CONSTRAINT fk_research_publication_event_workflow FOREIGN KEY(workflow_id) REFERENCES research_publication_workflows(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_publication_event_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_report_versions
  ADD COLUMN publication_workflow_id BIGINT UNSIGNED NULL AFTER report_id,
  ADD COLUMN document_object_id BIGINT UNSIGNED NULL AFTER publication_workflow_id,
  ADD COLUMN document_revision_number INT UNSIGNED NULL AFTER document_object_id,
  ADD COLUMN document_revision_public_id VARCHAR(40) NULL AFTER document_revision_number,
  ADD COLUMN approval_snapshot_json JSON NULL AFTER snapshot_hash,
  ADD INDEX idx_research_report_version_workflow(publication_workflow_id),
  ADD INDEX idx_research_report_version_document(document_object_id,document_revision_number),
  ADD CONSTRAINT fk_research_report_version_workflow FOREIGN KEY(publication_workflow_id) REFERENCES research_publication_workflows(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_research_report_version_document FOREIGN KEY(document_object_id) REFERENCES research_workspace_objects(id) ON DELETE SET NULL;
