-- Worker lease and retry scheduling hardening. Keep immutable once applied.
ALTER TABLE media_jobs ADD COLUMN IF NOT EXISTS claim_token CHAR(32) NULL AFTER attempts;
ALTER TABLE media_jobs ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME NULL AFTER claim_token;
ALTER TABLE media_jobs ADD COLUMN IF NOT EXISTS available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER lease_expires_at;
ALTER TABLE media_jobs ADD INDEX IF NOT EXISTS idx_media_jobs_claim(status,available_at,lease_expires_at,created_at);

ALTER TABLE transcription_jobs ADD COLUMN IF NOT EXISTS claim_token CHAR(32) NULL AFTER attempts;
ALTER TABLE transcription_jobs ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME NULL AFTER claim_token;
ALTER TABLE transcription_jobs ADD COLUMN IF NOT EXISTS available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER lease_expires_at;
ALTER TABLE transcription_jobs ADD INDEX IF NOT EXISTS idx_transcription_jobs_claim(status,available_at,lease_expires_at,created_at);

ALTER TABLE ai_jobs ADD COLUMN IF NOT EXISTS claim_token CHAR(32) NULL AFTER attempts;
ALTER TABLE ai_jobs ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME NULL AFTER claim_token;
ALTER TABLE ai_jobs ADD COLUMN IF NOT EXISTS available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER lease_expires_at;
ALTER TABLE ai_jobs ADD INDEX IF NOT EXISTS idx_ai_jobs_claim(status,available_at,priority,created_at);

ALTER TABLE source_monitor_jobs ADD COLUMN IF NOT EXISTS claim_token CHAR(32) NULL AFTER attempts;
ALTER TABLE source_monitor_jobs ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME NULL AFTER claim_token;
ALTER TABLE source_monitor_jobs ADD INDEX IF NOT EXISTS idx_source_monitor_claim(status,scheduled_at,lease_expires_at,priority,created_at);
