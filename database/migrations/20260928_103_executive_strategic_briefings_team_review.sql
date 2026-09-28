-- Annotated Phase 73 Section 6 — Executive Strategic Briefings & Team Review
-- Adds immutable strategic briefing lineage while reusing Executive Briefings,
-- Research Docs, Collaborative Research Review, and Phase 59 publishing.

CREATE TABLE IF NOT EXISTS research_intelligence_strategic_briefings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  executive_briefing_id BIGINT UNSIGNED NOT NULL,
  source_strategic_review_id BIGINT UNSIGNED NULL,
  collaborative_review_id BIGINT UNSIGNED NULL,
  packet_json JSON NOT NULL,
  packet_hash CHAR(64) NOT NULL,
  source_review_consensus VARCHAR(64) NULL,
  source_review_completed_at DATETIME NULL,
  dedupe_key CHAR(64) NOT NULL UNIQUE,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_p73_strategic_briefing_executive(executive_briefing_id),
  INDEX idx_p73_strategic_briefing_portfolio(portfolio_id,created_at,id),
  INDEX idx_p73_strategic_briefing_source_review(source_strategic_review_id),
  INDEX idx_p73_strategic_briefing_team_review(collaborative_review_id),
  CONSTRAINT fk_p73_strategic_briefing_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_strategic_briefing_executive FOREIGN KEY(executive_briefing_id) REFERENCES research_executive_briefings(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_strategic_briefing_source_review FOREIGN KEY(source_strategic_review_id) REFERENCES research_intelligence_strategic_reviews(id) ON DELETE SET NULL,
  CONSTRAINT fk_p73_strategic_briefing_team_review FOREIGN KEY(collaborative_review_id) REFERENCES research_reviews(id) ON DELETE SET NULL,
  CONSTRAINT fk_p73_strategic_briefing_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
