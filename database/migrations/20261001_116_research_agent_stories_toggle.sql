-- Research Agent Stories master on/off control
ALTER TABLE research_agent_story_policies
  ADD COLUMN stories_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER agent_id;
