-- Server-side abuse/rate limiting. Keep immutable once applied.
CREATE TABLE IF NOT EXISTS rate_limit_counters (
  bucket VARCHAR(80) NOT NULL,
  subject_hash CHAR(64) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  window_expires_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(bucket,subject_hash),
  INDEX idx_rate_limit_expiry(window_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
