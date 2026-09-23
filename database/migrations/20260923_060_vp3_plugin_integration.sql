-- Annotated Phase 63 — VP3 Plugin Integration
-- VP3 remains the host identity/plugin authority. Annotated stores only a durable
-- mapping to its local user plus replay-protection/audit receipts. No VP3 business
-- records, entitlements or Agent Brain tables are copied into Annotated.

CREATE TABLE IF NOT EXISTS vp3_host_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  host_key VARCHAR(80) NOT NULL DEFAULT 'vp3',
  host_user_id VARCHAR(190) NOT NULL,
  host_email VARCHAR(190) NULL,
  host_display_name VARCHAR(190) NULL,
  host_metadata_json JSON NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vp3_host_identity(host_key,host_user_id),
  UNIQUE KEY uq_vp3_user_host(user_id,host_key),
  CONSTRAINT fk_vp3_host_identity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vp3_host_nonces (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  host_key VARCHAR(80) NOT NULL DEFAULT 'vp3',
  purpose ENUM('launch','api') NOT NULL,
  nonce_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vp3_host_nonce(host_key,nonce_hash),
  INDEX idx_vp3_host_nonce_expiry(expires_at,purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vp3_host_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NULL,
  host_key VARCHAR(80) NOT NULL DEFAULT 'vp3',
  host_user_id VARCHAR(190) NULL,
  event_type VARCHAR(80) NOT NULL,
  request_id VARCHAR(120) NULL,
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vp3_host_audit_user(user_id,created_at),
  INDEX idx_vp3_host_audit_host(host_key,host_user_id,created_at),
  CONSTRAINT fk_vp3_host_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
