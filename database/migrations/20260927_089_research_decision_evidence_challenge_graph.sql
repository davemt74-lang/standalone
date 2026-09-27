-- Annotated Phase 71 Section 3 — Decision Evidence & Challenge Graph
-- Explicit challenge/reversal-condition state layered onto the versioned Decision ledger.

CREATE TABLE IF NOT EXISTS research_decision_challenges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  decision_id BIGINT UNSIGNED NOT NULL,
  challenge_type ENUM('contradiction','assumption','uncertainty','alternative','reversal_condition','question') NOT NULL,
  title VARCHAR(255) NOT NULL,
  detail MEDIUMTEXT NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('open','resolved','accepted','dismissed') NOT NULL DEFAULT 'open',
  resolution MEDIUMTEXT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  resolved_by_user_id BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_decision_challenge(decision_id,status,severity,updated_at),
  CONSTRAINT fk_research_decision_challenge_decision FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_challenge_created_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_decision_challenge_resolved_user FOREIGN KEY(resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_decision_challenge_refs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  challenge_id BIGINT UNSIGNED NOT NULL,
  ref_type VARCHAR(64) NOT NULL,
  ref_public_id VARCHAR(160) NOT NULL,
  ref_role ENUM('supports_challenge','counters_challenge','context','source') NOT NULL DEFAULT 'context',
  strength DECIMAL(5,4) NULL,
  note VARCHAR(2000) NULL,
  added_by_user_id BIGINT UNSIGNED NULL,
  added_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_decision_challenge_ref(challenge_id,ref_type,ref_public_id,ref_role),
  INDEX idx_research_decision_challenge_ref_lookup(ref_type,ref_public_id),
  INDEX idx_research_decision_challenge_ref_challenge(challenge_id,ref_role,id),
  CONSTRAINT fk_research_decision_challenge_ref_challenge FOREIGN KEY(challenge_id) REFERENCES research_decision_challenges(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_challenge_ref_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_research_decision_challenge_ref_strength CHECK (strength IS NULL OR (strength>=0 AND strength<=1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
