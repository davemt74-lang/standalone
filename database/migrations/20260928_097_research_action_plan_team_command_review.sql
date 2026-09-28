-- Annotated Phase 72 Section 6 — Action Plan Team Command & Review
-- Extends the existing Collaborative Research Review engine to Action Plan strategic execution subjects.

ALTER TABLE research_reviews
  MODIFY COLUMN subject_type ENUM(
    'claim','finding','report_version','agent_action','document','mission',
    'decision','decision_reconsideration','action_plan'
  ) NOT NULL;
