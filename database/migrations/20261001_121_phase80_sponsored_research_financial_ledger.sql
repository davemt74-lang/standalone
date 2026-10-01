-- Phase 80 Section 6 — Compensation, Earnings & Financial Ledger

CREATE TABLE IF NOT EXISTS sponsored_research_finance_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  platform_fee_bps INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_sponsored_finance_fee_bps CHECK(platform_fee_bps<=10000),
  CONSTRAINT fk_sponsored_finance_settings_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sponsored_research_finance_settings(id,platform_fee_bps) VALUES(1,0)
ON DUPLICATE KEY UPDATE id=VALUES(id);

CREATE TABLE IF NOT EXISTS sponsored_research_financial_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  transaction_type ENUM('funding','reservation','reservation_release','acceptance_settlement','earning_hold','earning_release','reversal','refund','adjustment') NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NULL,
  review_case_id BIGINT UNSIGNED NULL,
  submission_id BIGINT UNSIGNED NULL,
  currency CHAR(3) NOT NULL,
  reference_type VARCHAR(80) NULL,
  reference_public_id VARCHAR(64) NULL,
  idempotency_key VARCHAR(190) NOT NULL UNIQUE,
  memo VARCHAR(1000) NULL,
  metadata_json JSON NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_fin_tx_campaign(campaign_id,created_at,id),
  INDEX idx_sponsored_fin_tx_researcher(researcher_user_id,created_at,id),
  INDEX idx_sponsored_fin_tx_review(review_case_id,created_at,id),
  CONSTRAINT fk_sponsored_fin_tx_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_fin_tx_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_fin_tx_review FOREIGN KEY(review_case_id) REFERENCES sponsored_research_review_cases(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_fin_tx_submission FOREIGN KEY(submission_id) REFERENCES sponsored_research_submissions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_fin_tx_actor FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_financial_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NULL,
  account_code ENUM('campaign_available','campaign_reserved','researcher_pending','researcher_held','platform_fee','platform_fee_held','sponsor_refundable') NOT NULL,
  amount_cents BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_fin_entry_campaign(campaign_id,account_code,currency,id),
  INDEX idx_sponsored_fin_entry_researcher(researcher_user_id,account_code,currency,id),
  CONSTRAINT fk_sponsored_fin_entry_tx FOREIGN KEY(transaction_id) REFERENCES sponsored_research_financial_transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_fin_entry_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_fin_entry_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_compensation_reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NOT NULL,
  gross_amount_cents BIGINT UNSIGNED NOT NULL,
  platform_fee_bps INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('reserved','settled','released','reversed') NOT NULL DEFAULT 'reserved',
  funding_transaction_id BIGINT UNSIGNED NULL,
  reserve_transaction_id BIGINT UNSIGNED NOT NULL,
  settlement_transaction_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at DATETIME NULL,
  released_at DATETIME NULL,
  reversed_at DATETIME NULL,
  UNIQUE KEY uq_sponsored_comp_reservation_submission(submission_id),
  INDEX idx_sponsored_comp_reservation_campaign(campaign_id,status,created_at),
  INDEX idx_sponsored_comp_reservation_researcher(researcher_user_id,status,created_at),
  CONSTRAINT chk_sponsored_comp_fee_bps CHECK(platform_fee_bps<=10000),
  CONSTRAINT fk_sponsored_comp_res_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_comp_res_submission FOREIGN KEY(submission_id) REFERENCES sponsored_research_submissions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_comp_res_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_comp_res_funding_tx FOREIGN KEY(funding_transaction_id) REFERENCES sponsored_research_financial_transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_comp_res_reserve_tx FOREIGN KEY(reserve_transaction_id) REFERENCES sponsored_research_financial_transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_comp_res_settlement_tx FOREIGN KEY(settlement_transaction_id) REFERENCES sponsored_research_financial_transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_comp_res_actor FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
