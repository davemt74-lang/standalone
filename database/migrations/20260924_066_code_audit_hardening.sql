-- Code Audit Hardening — commercial account serialization & Stripe account state ordering

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
