-- Annotated Phase 24 — Living Research Publishing & Distribution
-- Immutable research_report_versions remain authoritative publication history.
-- Persist only explicit subscription/read/topic state.

CREATE TABLE IF NOT EXISTS research_report_subscriptions (
  user_id BIGINT UNSIGNED NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  notify_updates TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,report_id),
  INDEX idx_report_subscription_report(report_id,notify_updates,updated_at),
  CONSTRAINT fk_report_subscription_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_subscription_report FOREIGN KEY(report_id) REFERENCES research_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_report_reader_state (
  user_id BIGINT UNSIGNED NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  last_read_version_id BIGINT UNSIGNED NULL,
  last_read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,report_id),
  INDEX idx_report_reader_updated(user_id,updated_at),
  CONSTRAINT fk_report_reader_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_reader_report FOREIGN KEY(report_id) REFERENCES research_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_reader_version FOREIGN KEY(last_read_version_id) REFERENCES research_report_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_report_topics (
  report_id BIGINT UNSIGNED NOT NULL,
  topic_slug VARCHAR(80) NOT NULL,
  topic_label VARCHAR(120) NOT NULL,
  position TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(report_id,topic_slug),
  INDEX idx_report_topic_slug(topic_slug,report_id),
  CONSTRAINT fk_report_topic_report FOREIGN KEY(report_id) REFERENCES research_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
