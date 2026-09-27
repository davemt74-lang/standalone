-- Annotated Phase 71 Section 7 — Decision Command Center + Team Review
-- Extends the existing collaborative review system to Decision Memory subjects.

ALTER TABLE research_reviews
  MODIFY COLUMN subject_type ENUM(
    'claim','finding','report_version','agent_action','document','mission',
    'decision','decision_reconsideration'
  ) NOT NULL;
