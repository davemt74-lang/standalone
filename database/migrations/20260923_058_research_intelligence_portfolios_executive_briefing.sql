-- Annotated Phase 60 — Research Intelligence Portfolios & Executive Briefing
-- Durable Program portfolios aggregate existing Phase 58 state. Executive briefings
-- remain normal Research Docs and use Phase 59 review/publishing.

CREATE TABLE IF NOT EXISTS research_intelligence_portfolios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NULL,
  anchor_program_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  objective TEXT NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  briefing_cadence ENUM('manual','weekly','monthly','quarterly') NOT NULL DEFAULT 'manual',
  timezone_name VARCHAR(64) NOT NULL DEFAULT 'UTC',
  briefing_time_local TIME NOT NULL DEFAULT '09:00:00',
  briefing_weekday TINYINT UNSIGNED NULL,
  briefing_day_of_month TINYINT UNSIGNED NULL,
  last_briefed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_intel_portfolio_owner(owner_user_id,status,updated_at),
  INDEX idx_intel_portfolio_team(team_id,status,updated_at),
  CONSTRAINT fk_intel_portfolio_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_portfolio_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_portfolio_anchor_program FOREIGN KEY(anchor_program_id) REFERENCES research_programs(id) ON DELETE SET NULL,
  CONSTRAINT chk_intel_portfolio_weekday CHECK (briefing_weekday IS NULL OR briefing_weekday<=6),
  CONSTRAINT chk_intel_portfolio_monthday CHECK (briefing_day_of_month IS NULL OR (briefing_day_of_month>=1 AND briefing_day_of_month<=28))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_portfolio_programs (
  portfolio_id BIGINT UNSIGNED NOT NULL,
  program_id BIGINT UNSIGNED NOT NULL,
  added_by_user_id BIGINT UNSIGNED NOT NULL,
  member_role ENUM('primary','supporting','watch') NOT NULL DEFAULT 'supporting',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(portfolio_id,program_id),
  INDEX idx_intel_portfolio_program(program_id,portfolio_id),
  CONSTRAINT fk_intel_portfolio_member_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_portfolio_member_program FOREIGN KEY(program_id) REFERENCES research_programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_portfolio_member_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_portfolio_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  snapshot_kind ENUM('manual','briefing','system') NOT NULL DEFAULT 'manual',
  window_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  aggregate_json JSON NOT NULL,
  provenance_json JSON NOT NULL,
  snapshot_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_intel_portfolio_snapshot(portfolio_id,created_at),
  CONSTRAINT fk_intel_portfolio_snapshot_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_portfolio_snapshot_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_intelligence_portfolios
  ADD COLUMN IF NOT EXISTS latest_snapshot_id BIGINT UNSIGNED NULL AFTER anchor_program_id,
  ADD INDEX IF NOT EXISTS idx_intel_portfolio_latest_snapshot(latest_snapshot_id),
  ADD CONSTRAINT fk_intel_portfolio_latest_snapshot FOREIGN KEY(latest_snapshot_id) REFERENCES research_intelligence_portfolio_snapshots(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS research_intelligence_insights (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  snapshot_id BIGINT UNSIGNED NULL,
  insight_kind ENUM('aggregation','inference') NOT NULL,
  category ENUM('trend','risk','opportunity','contradiction','decision','freshness','dependency','other') NOT NULL DEFAULT 'other',
  severity ENUM('info','watch','high') NOT NULL DEFAULT 'info',
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  confidence DECIMAL(5,4) NULL,
  provenance_json JSON NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  status ENUM('active','resolved','dismissed') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT UNSIGNED NULL,
  created_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_intel_portfolio_insight(portfolio_id,fingerprint),
  INDEX idx_intel_portfolio_insight_state(portfolio_id,status,severity,created_at),
  INDEX idx_intel_portfolio_insight_snapshot(snapshot_id,created_at),
  CONSTRAINT fk_intel_portfolio_insight_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_portfolio_insight_snapshot FOREIGN KEY(snapshot_id) REFERENCES research_intelligence_portfolio_snapshots(id) ON DELETE SET NULL,
  CONSTRAINT fk_intel_portfolio_insight_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_intel_portfolio_confidence CHECK (confidence IS NULL OR (confidence>=0 AND confidence<=1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_executive_briefings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  document_object_id BIGINT UNSIGNED NOT NULL,
  publication_workflow_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  status ENUM('draft','in_review','published','superseded') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_intel_briefing_document(document_object_id),
  UNIQUE KEY uq_intel_briefing_publication(publication_workflow_id),
  INDEX idx_intel_briefing_portfolio(portfolio_id,created_at),
  CONSTRAINT fk_intel_briefing_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_briefing_snapshot FOREIGN KEY(snapshot_id) REFERENCES research_intelligence_portfolio_snapshots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_intel_briefing_document FOREIGN KEY(document_object_id) REFERENCES research_workspace_objects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_intel_briefing_publication FOREIGN KEY(publication_workflow_id) REFERENCES research_publication_workflows(id) ON DELETE SET NULL,
  CONSTRAINT fk_intel_briefing_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_portfolio_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_type ENUM('user','agent','system') NOT NULL DEFAULT 'system',
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_intel_portfolio_event(portfolio_id,created_at),
  CONSTRAINT fk_intel_portfolio_event_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_portfolio_event_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
