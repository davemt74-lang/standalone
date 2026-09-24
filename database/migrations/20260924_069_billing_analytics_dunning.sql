-- Admin V1.60 — Billing Analytics, Revenue Operations & Dunning
-- Stripe remains payment/provider truth; these tables persist derived operations, analytics and admin decisions.

CREATE TABLE IF NOT EXISTS billing_operations_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  dunning_grace_days INT UNSIGNED NOT NULL DEFAULT 7,
  trial_reminder_days INT UNSIGNED NOT NULL DEFAULT 3,
  suspend_after_grace TINYINT(1) NOT NULL DEFAULT 0,
  restore_after_payment TINYINT(1) NOT NULL DEFAULT 1,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_billing_ops_settings_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO billing_operations_settings(id) VALUES(1)
ON DUPLICATE KEY UPDATE id=VALUES(id);

CREATE TABLE IF NOT EXISTS billing_daily_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  snapshot_date DATE NOT NULL,
  stripe_mode ENUM('test','live') NOT NULL,
  currency VARCHAR(12) NOT NULL DEFAULT 'usd',
  mrr_cents BIGINT NOT NULL DEFAULT 0,
  arr_cents BIGINT NOT NULL DEFAULT 0,
  active_subscriptions INT UNSIGNED NOT NULL DEFAULT 0,
  trialing_subscriptions INT UNSIGNED NOT NULL DEFAULT 0,
  past_due_subscriptions INT UNSIGNED NOT NULL DEFAULT 0,
  paused_subscriptions INT UNSIGNED NOT NULL DEFAULT 0,
  canceled_subscriptions INT UNSIGNED NOT NULL DEFAULT 0,
  new_mrr_cents BIGINT NOT NULL DEFAULT 0,
  expansion_mrr_cents BIGINT NOT NULL DEFAULT 0,
  contraction_mrr_cents BIGINT NOT NULL DEFAULT 0,
  churned_mrr_cents BIGINT NOT NULL DEFAULT 0,
  recovered_mrr_cents BIGINT NOT NULL DEFAULT 0,
  gross_collected_cents BIGINT NOT NULL DEFAULT 0,
  failed_amount_cents BIGINT NOT NULL DEFAULT 0,
  refunded_amount_cents BIGINT NOT NULL DEFAULT 0,
  disputed_amount_cents BIGINT NOT NULL DEFAULT 0,
  open_dunning_cases INT UNSIGNED NOT NULL DEFAULT 0,
  over_capacity_accounts INT UNSIGNED NOT NULL DEFAULT 0,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_daily_mode_date(stripe_mode,snapshot_date),
  INDEX idx_billing_daily_date(snapshot_date,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_account_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  snapshot_date DATE NOT NULL,
  stripe_mode ENUM('test','live') NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  billing_source VARCHAR(32) NOT NULL,
  account_status VARCHAR(32) NOT NULL,
  subscription_status VARCHAR(48) NOT NULL,
  monthly_price_cents BIGINT NOT NULL DEFAULT 0,
  member_count INT UNSIGNED NOT NULL DEFAULT 0,
  seat_limit INT UNSIGNED NOT NULL DEFAULT 0,
  pending_reserved_seats INT UNSIGNED NOT NULL DEFAULT 0,
  trial_ends_at DATETIME NULL,
  period_end DATETIME NULL,
  stripe_subscription_id VARCHAR(255) NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  dunning_status VARCHAR(32) NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_account_snapshot(stripe_mode,snapshot_date,account_id),
  INDEX idx_billing_account_snapshot_account(account_id,snapshot_date,id),
  CONSTRAINT fk_billing_account_snapshot_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_account_snapshot_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_dunning_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  stripe_mode ENUM('test','live') NOT NULL,
  stripe_invoice_id VARCHAR(255) NOT NULL,
  stripe_subscription_id VARCHAR(255) NULL,
  status ENUM('open','action_required','grace','suspended','recovered','uncollectible','closed') NOT NULL DEFAULT 'open',
  stage INT UNSIGNED NOT NULL DEFAULT 1,
  failure_count INT UNSIGNED NOT NULL DEFAULT 1,
  amount_due_cents BIGINT NOT NULL DEFAULT 0,
  amount_remaining_cents BIGINT NOT NULL DEFAULT 0,
  opened_at DATETIME NOT NULL,
  last_failure_at DATETIME NULL,
  grace_until DATETIME NULL,
  next_review_at DATETIME NULL,
  suspended_by_dunning TINYINT(1) NOT NULL DEFAULT 0,
  suspended_at DATETIME NULL,
  suspension_lifecycle_event_id BIGINT UNSIGNED NULL,
  recovered_at DATETIME NULL,
  closed_at DATETIME NULL,
  last_event_id VARCHAR(255) NULL,
  last_event_created_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_dunning_invoice(stripe_mode,stripe_invoice_id),
  INDEX idx_billing_dunning_account(account_id,status,updated_at,id),
  INDEX idx_billing_dunning_review(status,next_review_at,id),
  INDEX idx_billing_dunning_lifecycle_event(suspension_lifecycle_event_id),
  CONSTRAINT fk_billing_dunning_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_dunning_lifecycle_event FOREIGN KEY(suspension_lifecycle_event_id) REFERENCES account_admin_events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_dunning_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  case_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  source ENUM('stripe','admin','system') NOT NULL DEFAULT 'system',
  event_type VARCHAR(100) NOT NULL,
  stripe_event_id VARCHAR(255) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_billing_dunning_event_case(case_id,created_at,id),
  INDEX idx_billing_dunning_event_account(account_id,created_at,id),
  CONSTRAINT fk_billing_dunning_event_case FOREIGN KEY(case_id) REFERENCES billing_dunning_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_dunning_event_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_dunning_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  note_type ENUM('general','dunning','trial','cancellation','dispute') NOT NULL DEFAULT 'general',
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_billing_note_account(account_id,created_at,id),
  CONSTRAINT fk_billing_note_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_note_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_cancellation_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  stripe_subscription_id VARCHAR(255) NULL,
  source ENUM('customer','admin','system') NOT NULL DEFAULT 'customer',
  actor_user_id BIGINT UNSIGNED NULL,
  reason_code ENUM('price','missing_features','not_using','temporary','support','competitor','business_closed','other') NOT NULL DEFAULT 'other',
  feedback_text VARCHAR(2000) NULL,
  effective_at DATETIME NULL,
  win_back_eligible TINYINT(1) NOT NULL DEFAULT 1,
  reactivated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_billing_cancel_account(account_id,created_at,id),
  INDEX idx_billing_cancel_reason(reason_code,created_at,id),
  CONSTRAINT fk_billing_cancel_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_cancel_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
