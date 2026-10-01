-- Phase 80 Section 10 — Sponsored Project Review, Revision & Completion

ALTER TABLE sponsored_research_project_submissions
  ADD COLUMN supersedes_submission_id BIGINT UNSIGNED NULL AFTER research_agent_id,
  ADD COLUMN revision_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER supersedes_submission_id,
  ADD COLUMN review_note TEXT NULL AFTER reviewed_at,
  ADD COLUMN reviewed_by_user_id BIGINT UNSIGNED NULL AFTER review_note,
  ADD COLUMN decision_at DATETIME NULL AFTER reviewed_by_user_id,
  ADD UNIQUE KEY uq_sponsored_project_submission_superseded(supersedes_submission_id),
  ADD INDEX idx_sponsored_project_submission_revision(assignment_id,revision_number,id),
  ADD CONSTRAINT fk_sponsored_project_submission_supersedes FOREIGN KEY(supersedes_submission_id) REFERENCES sponsored_research_project_submissions(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_sponsored_project_submission_reviewer FOREIGN KEY(reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE sponsored_research_agent_assignments
  ADD COLUMN completed_submission_id BIGINT UNSIGNED NULL AFTER agent_submit_enabled,
  ADD CONSTRAINT fk_sponsored_agent_assignment_completed_submission FOREIGN KEY(completed_submission_id) REFERENCES sponsored_research_project_submissions(id) ON DELETE RESTRICT;
