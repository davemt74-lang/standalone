-- Annotated Phase 73 Section 4 — Strategic Dependency & Conflict Graph
-- Explicit, audited relationships across native Portfolio Decisions and Action Plans.

CREATE TABLE IF NOT EXISTS research_intelligence_strategic_edges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  created_in_portfolio_id BIGINT UNSIGNED NOT NULL,
  source_type ENUM('decision','action_plan') NOT NULL,
  source_public_id VARCHAR(80) NOT NULL,
  target_type ENUM('decision','action_plan') NOT NULL,
  target_public_id VARCHAR(80) NOT NULL,
  relation_type ENUM('depends_on','supports','conflicts_with','duplicates','supersedes','blocks','materially_affects') NOT NULL,
  rationale TEXT NOT NULL,
  confidence DECIMAL(5,4) NULL,
  materiality ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  source_revision INT UNSIGNED NOT NULL,
  source_state_hash CHAR(64) NOT NULL,
  target_revision INT UNSIGNED NOT NULL,
  target_state_hash CHAR(64) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  removal_reason TEXT NULL,
  removed_by_user_id BIGINT UNSIGNED NULL,
  removed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_p73_strategic_edge(source_type,source_public_id,target_type,target_public_id,relation_type),
  INDEX idx_p73_strategic_edge_source(source_type,source_public_id,active),
  INDEX idx_p73_strategic_edge_target(target_type,target_public_id,active),
  INDEX idx_p73_strategic_edge_portfolio(created_in_portfolio_id,active,relation_type),
  CONSTRAINT fk_p73_strategic_edge_portfolio FOREIGN KEY(created_in_portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_strategic_edge_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_p73_strategic_edge_remover FOREIGN KEY(removed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_p73_strategic_edge_confidence CHECK (confidence IS NULL OR (confidence>=0 AND confidence<=1)),
  CONSTRAINT chk_p73_strategic_edge_not_self CHECK (NOT (source_type=target_type AND source_public_id=target_public_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_strategic_edge_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  edge_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('created','restored','updated','removed') NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  snapshot_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_p73_strategic_edge_event(edge_id,id),
  CONSTRAINT fk_p73_strategic_edge_event_edge FOREIGN KEY(edge_id) REFERENCES research_intelligence_strategic_edges(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_strategic_edge_event_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
