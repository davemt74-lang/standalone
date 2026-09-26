-- Phase 67 hardening — preserve System Report provenance if the requesting user is deleted.

ALTER TABLE research_system_reports
  DROP FOREIGN KEY fk_system_reports_requester;

ALTER TABLE research_system_reports
  MODIFY COLUMN requested_by_user_id BIGINT UNSIGNED NULL;

ALTER TABLE research_system_reports
  ADD CONSTRAINT fk_system_reports_requester
    FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
