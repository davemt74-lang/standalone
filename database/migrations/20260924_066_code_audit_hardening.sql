-- Code Audit Hardening — commercial account serialization, AI quota reservations & Stripe account state ordering

CREATE TABLE IF NOT EXISTS ai_usage_reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  ai_run_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  reserved_tokens BIGINT UNSIGNED NOT NULL,
  actual_tokens BIGINT UNSIGNED NULL,
  max_output_tokens INT UNSIGNED NOT NULL,
  status ENUM('reserved','settled','released') NOT NULL DEFAULT 'reserved',
  expires_at DATETIME NOT NULL,
  settled_at DATETIME NULL,
  released_at DATETIME NULL,
  release_reason VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_usage_reservation_run(ai_run_id),
  INDEX idx_ai_usage_reservation_account_period(account_id,period_start,period_end,status,expires_at,id),
  CONSTRAINT fk_ai_usage_reservation_run FOREIGN KEY(ai_run_id) REFERENCES ai_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_usage_reservation_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_usage_reservation_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_checkout_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  mode ENUM('test','live') NOT NULL,
  stripe_customer_id VARCHAR(255) NULL,
  stripe_price_id VARCHAR(255) NOT NULL,
  stripe_checkout_session_id VARCHAR(255) NULL,
  checkout_url TEXT NULL,
  request_json JSON NOT NULL,
  status ENUM('creating','open','completed','expired') NOT NULL DEFAULT 'creating',
  expires_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stripe_checkout_mode_session(mode,stripe_checkout_session_id),
  INDEX idx_stripe_checkout_account_mode(account_id,mode,status,updated_at,id),
  CONSTRAINT fk_stripe_checkout_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_stripe_checkout_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stripe_checkout_actor FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_account_state_watermarks (
  account_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('test','live') NOT NULL,
  last_event_created_at DATETIME NULL,
  last_event_id VARCHAR(255) NULL,
  last_event_type VARCHAR(120) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(account_id,mode),
  INDEX idx_stripe_account_state_event(mode,last_event_created_at,account_id),
  CONSTRAINT fk_stripe_account_state_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stripe_account_state_watermarks(account_id,mode,last_event_created_at,last_event_id,last_event_type)
SELECT x.account_id,x.mode,MAX(x.last_event_created_at),NULL,'migration_backfill'
FROM (
  SELECT account_id,mode,last_event_created_at FROM stripe_subscriptions WHERE last_event_created_at IS NOT NULL
  UNION ALL
  SELECT i.account_id,i.mode,i.last_event_created_at
  FROM stripe_invoices i
  JOIN stripe_webhook_events w ON w.mode=i.mode AND w.stripe_event_id=i.last_event_id
  WHERE i.last_event_created_at IS NOT NULL
    AND w.event_type IN ('invoice.paid','invoice.payment_failed','invoice.payment_action_required','invoice.marked_uncollectible')
) x
GROUP BY x.account_id,x.mode
ON DUPLICATE KEY UPDATE
  last_event_created_at=CASE
    WHEN last_event_created_at IS NULL THEN VALUES(last_event_created_at)
    WHEN VALUES(last_event_created_at) IS NULL THEN last_event_created_at
    WHEN VALUES(last_event_created_at)>last_event_created_at THEN VALUES(last_event_created_at)
    ELSE last_event_created_at
  END,
  last_event_type='migration_backfill';
