-- Annotated Phase 61 — Portfolio Intelligence Operations & Executive Follow-Through
-- Operational continuity for Phase 60. Reuses the existing Research Automation invocation,
-- Research Tasks, Decision Memory, notifications, and Phase 59 publication/distribution.

ALTER TABLE research_intelligence_portfolios
  ADD COLUMN IF NOT EXISTS briefing_policy ENUM('always','material_only') NOT NULL DEFAULT 'material_only' AFTER briefing_cadence,
  ADD COLUMN IF NOT EXISTS materiality_threshold ENUM('any','important','high') NOT NULL DEFAULT 'important' AFTER briefing_policy,
  ADD COLUMN IF NOT EXISTS next_cycle_at DATETIME NULL AFTER briefing_day_of_month,
  ADD COLUMN IF NOT EXISTS last_cycle_at DATETIME NULL AFTER next_cycle_at,
  ADD COLUMN IF NOT EXISTS last_material_hash CHAR(64) NULL AFTER last_cycle_at,
  ADD INDEX IF NOT EXISTS idx_intel_portfolio_cycle_due(status,next_cycle_at,id);

CREATE TABLE IF NOT EXISTS research_intelligence_portfolio_cycles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  scheduled_for DATETIME NOT NULL,
  trigger_type ENUM('schedule','manual','recovery') NOT NULL DEFAULT 'schedule',
  status ENUM('processing','completed','skipped','failed') NOT NULL DEFAULT 'processing',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
  material_hash CHAR(64) NULL,
  previous_material_hash CHAR(64) NULL,
  material_changed TINYINT(1) NOT NULL DEFAULT 0,
  snapshot_id BIGINT UNSIGNED NULL,
  briefing_id BIGINT UNSIGNED NULL,
  detail VARCHAR(1000) NULL,
  dedupe_key CHAR(64) NOT NULL UNIQUE,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_intel_cycle_portfolio(portfolio_id,scheduled_for),
  INDEX idx_intel_cycle_status(status,updated_at),
  CONSTRAINT fk_intel_cycle_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_cycle_snapshot FOREIGN KEY(snapshot_id) REFERENCES research_intelligence_portfolio_snapshots(id) ON DELETE SET NULL,
  CONSTRAINT fk_intel_cycle_briefing FOREIGN KEY(briefing_id) REFERENCES research_executive_briefings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_portfolio_subscriptions (
  portfolio_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  cadence ENUM('inherit','every_briefing','weekly','monthly','quarterly') NOT NULL DEFAULT 'inherit',
  delivery_preference ENUM('draft_ready','published_only','both') NOT NULL DEFAULT 'both',
  last_notified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(portfolio_id,user_id),
  INDEX idx_intel_subscription_user(user_id,status,updated_at),
  CONSTRAINT fk_intel_subscription_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_subscription_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_briefing_receipts (
  briefing_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  notification_public_id VARCHAR(40) NULL,
  delivery_state ENUM('notified','read','acknowledged') NOT NULL DEFAULT 'notified',
  notified_at DATETIME NULL,
  read_at DATETIME NULL,
  acknowledged_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(briefing_id,user_id),
  INDEX idx_intel_receipt_user(user_id,delivery_state,updated_at),
  CONSTRAINT fk_intel_receipt_briefing FOREIGN KEY(briefing_id) REFERENCES research_executive_briefings(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_receipt_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_portfolio_decision_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  outcome_id BIGINT UNSIGNED NOT NULL,
  insight_id BIGINT UNSIGNED NULL,
  briefing_id BIGINT UNSIGNED NULL,
  task_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_intel_decision_outcome(portfolio_id,outcome_id),
  INDEX idx_intel_decision_task(portfolio_id,task_id),
  CONSTRAINT fk_intel_decision_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_decision_outcome FOREIGN KEY(outcome_id) REFERENCES research_outcome_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_decision_insight FOREIGN KEY(insight_id) REFERENCES research_intelligence_insights(id) ON DELETE SET NULL,
  CONSTRAINT fk_intel_decision_briefing FOREIGN KEY(briefing_id) REFERENCES research_executive_briefings(id) ON DELETE SET NULL,
  CONSTRAINT fk_intel_decision_task FOREIGN KEY(task_id) REFERENCES research_tasks(id) ON DELETE SET NULL,
  CONSTRAINT fk_intel_decision_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_portfolio_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  insight_id BIGINT UNSIGNED NULL,
  briefing_id BIGINT UNSIGNED NULL,
  feedback_type ENUM('useful','irrelevant','resolved','escalated','acted_on') NOT NULL,
  note VARCHAR(2000) NULL,
  fingerprint CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_intel_feedback(portfolio_id,user_id,fingerprint),
  INDEX idx_intel_feedback_portfolio(portfolio_id,feedback_type,updated_at),
  CONSTRAINT fk_intel_feedback_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_feedback_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_intel_feedback_insight FOREIGN KEY(insight_id) REFERENCES research_intelligence_insights(id) ON DELETE SET NULL,
  CONSTRAINT fk_intel_feedback_briefing FOREIGN KEY(briefing_id) REFERENCES research_executive_briefings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
