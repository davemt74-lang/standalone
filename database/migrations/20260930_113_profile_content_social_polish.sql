-- Phase 79.2 — Profile Content System & Social Polish
ALTER TABLE profile_pins
  MODIFY COLUMN object_type ENUM('annotation','research_report','collection','research_agent') NOT NULL;
