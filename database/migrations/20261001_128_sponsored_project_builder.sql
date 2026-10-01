-- Sponsored Research Section 2: one canonical, versioned campaign project specification.
-- Existing campaigns remain valid with an empty specification until sponsors edit them.
ALTER TABLE sponsored_research_campaigns
  ADD COLUMN project_specs_json JSON NULL AFTER disclosure_json;
