-- Admin V1.10 — canonical AI usage metering and token management.
-- Records immutable per-run usage against commercial accounts while keeping system/admin usage visible but non-chargeable.

CREATE TABLE IF NOT EXISTS ai_usage_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  ai_run_id BIGINT UNSIGNED NULL,
  account_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  provider_id BIGINT UNSIGNED NULL,
  model_id BIGINT UNSIGNED NULL,
  initiated_by VARCHAR(24) NOT NULL,
  usage_class ENUM('account','admin','system') NOT NULL DEFAULT 'system',
  chargeable TINYINT(1) NOT NULL DEFAULT 0,
  task_type VARCHAR(80) NOT NULL,
  token_source ENUM('provider','mixed','estimated') NOT NULL DEFAULT 'provider',
  input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  estimated_cost_micros BIGINT UNSIGNED NULL,
  period_start DATE NULL,
  period_end DATE NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_usage_run(ai_run_id),
  INDEX idx_ai_usage_account_period(account_id,period_start,period_end,chargeable,id),
  INDEX idx_ai_usage_user(user_id,created_at,id),
  INDEX idx_ai_usage_model(model_id,created_at,id),
  INDEX idx_ai_usage_class(usage_class,created_at,id),
  CONSTRAINT fk_ai_usage_run FOREIGN KEY(ai_run_id) REFERENCES ai_runs(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_usage_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_usage_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_usage_provider FOREIGN KEY(provider_id) REFERENCES ai_providers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_usage_model FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_usage_adjustments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  adjustment_type ENUM('credit','debit','correction') NOT NULL DEFAULT 'correction',
  token_delta BIGINT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  reason VARCHAR(500) NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_usage_adjust_account_period(account_id,period_start,period_end,id),
  INDEX idx_ai_usage_adjust_actor(actor_user_id,created_at,id),
  CONSTRAINT fk_ai_usage_adjust_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_usage_adjust_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
