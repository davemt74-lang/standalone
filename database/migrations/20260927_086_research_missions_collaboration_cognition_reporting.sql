-- Annotated Research Missions V1 — Section 6
-- Collaboration compatibility only: Missions become first-class subjects in the existing Review Center.
-- No new review workflow, scheduler, queue, worker, or approval semantics are introduced.

ALTER TABLE research_reviews
  MODIFY COLUMN subject_type ENUM('claim','finding','report_version','agent_action','document','mission') NOT NULL;
