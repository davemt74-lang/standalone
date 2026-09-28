-- Annotated Phase 73 Section 1 — Portfolio → Native Decision Handoff
-- Preserves all historical Phase 61 portfolio decision rows while routing new decisions
-- into the authoritative Phase 71 Research Decision Ledger.

ALTER TABLE research_intelligence_portfolio_decision_links
  MODIFY COLUMN outcome_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS decision_id BIGINT UNSIGNED NULL AFTER outcome_id,
  ADD COLUMN IF NOT EXISTS handoff_key CHAR(64) NULL AFTER decision_id,
  ADD UNIQUE KEY IF NOT EXISTS uq_intel_portfolio_native_decision(portfolio_id,decision_id),
  ADD UNIQUE KEY IF NOT EXISTS uq_intel_portfolio_handoff_key(portfolio_id,handoff_key),
  ADD INDEX IF NOT EXISTS idx_intel_portfolio_decision_native(decision_id),
  ADD CONSTRAINT fk_intel_decision_native FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE;

ALTER TABLE research_intelligence_portfolio_decision_links
  ADD CONSTRAINT chk_intel_portfolio_decision_target CHECK ((outcome_id IS NOT NULL AND decision_id IS NULL) OR (outcome_id IS NULL AND decision_id IS NOT NULL)),
  ADD CONSTRAINT chk_intel_portfolio_native_handoff_key CHECK (decision_id IS NULL OR handoff_key IS NOT NULL);
