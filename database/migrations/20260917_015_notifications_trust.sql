-- Annotated V1.1 Phase 9: Notifications, Source Change Intelligence, Claims & Moderation.
-- Keep immutable once released.

ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(40) NULL AFTER id,
  ADD COLUMN IF NOT EXISTS category VARCHAR(32) NOT NULL DEFAULT 'social' AFTER notification_type,
  ADD COLUMN IF NOT EXISTS dedupe_key VARCHAR(190) NULL AFTER category,
  ADD COLUMN IF NOT EXISTS group_key VARCHAR(190) NULL AFTER dedupe_key,
  ADD COLUMN IF NOT EXISTS context_json JSON NULL AFTER body,
  ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER read_at;

UPDATE notifications SET public_id=CONCAT('legacy-notification-',id) WHERE public_id IS NULL OR public_id='';
ALTER TABLE notifications MODIFY COLUMN public_id VARCHAR(40) NOT NULL;
ALTER TABLE notifications ADD UNIQUE KEY IF NOT EXISTS uq_notification_public(public_id);
ALTER TABLE notifications ADD UNIQUE KEY IF NOT EXISTS uq_notification_dedupe(user_id,dedupe_key);
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_notification_category(user_id,category,read_at,created_at);
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_notification_group(user_id,group_key,created_at);

CREATE TABLE IF NOT EXISTS notification_mutes (
  user_id BIGINT UNSIGNED NOT NULL,
  scope_type ENUM('source','annotation','conversation','live_room','user') NOT NULL,
  scope_public_id VARCHAR(190) NOT NULL,
  category VARCHAR(32) NOT NULL DEFAULT 'all',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,scope_type,scope_public_id,category),
  INDEX idx_notification_mute_scope(scope_type,scope_public_id),
  CONSTRAINT fk_notification_mute_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_preferences
  ADD COLUMN IF NOT EXISTS notify_comments TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_social,
  ADD COLUMN IF NOT EXISTS notify_follows TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_comments,
  ADD COLUMN IF NOT EXISTS notify_team TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_research,
  ADD COLUMN IF NOT EXISTS notify_claims TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_live,
  ADD COLUMN IF NOT EXISTS notify_moderation TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_claims;

ALTER TABLE source_change_events
  ADD COLUMN IF NOT EXISTS impact_type ENUM('source_updated','passage_changed','passage_missing','source_unavailable','source_restored') NULL AFTER change_type,
  ADD COLUMN IF NOT EXISTS affected_annotation_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER target_changed,
  ADD COLUMN IF NOT EXISTS restored_from_event_id BIGINT UNSIGNED NULL AFTER affected_annotation_count;

CREATE TABLE IF NOT EXISTS source_annotation_impacts (
  source_change_event_id BIGINT UNSIGNED NOT NULL,
  annotation_id BIGINT UNSIGNED NOT NULL,
  impact_type ENUM('source_updated','passage_changed','passage_missing','source_unavailable','source_restored') NOT NULL,
  previous_excerpt TEXT NULL,
  current_excerpt TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(source_change_event_id,annotation_id),
  INDEX idx_source_annotation_impact_annotation(annotation_id,created_at),
  INDEX idx_source_annotation_impact_type(impact_type,created_at),
  CONSTRAINT fk_source_annotation_impact_event FOREIGN KEY(source_change_event_id) REFERENCES source_change_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_source_annotation_impact_annotation FOREIGN KEY(annotation_id) REFERENCES annotations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE rights_claims
  ADD COLUMN IF NOT EXISTS claimant_user_id BIGINT UNSIGNED NULL AFTER annotation_id,
  ADD COLUMN IF NOT EXISTS tracking_token_hash CHAR(64) NULL AFTER claimant_email,
  ADD COLUMN IF NOT EXISTS decision_summary TEXT NULL AFTER moderator_note,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE rights_claims MODIFY COLUMN status ENUM('submitted','under_review','resolved','rejected','restricted','appealed','reopened') NOT NULL DEFAULT 'submitted';
ALTER TABLE rights_claims ADD UNIQUE KEY IF NOT EXISTS uq_rights_tracking_token(tracking_token_hash);
ALTER TABLE rights_claims ADD INDEX IF NOT EXISTS idx_rights_claimant_user(claimant_user_id,status,updated_at);

ALTER TABLE moderation_reports
  MODIFY COLUMN object_type ENUM('annotation','comment','live_message','user','source') NOT NULL,
  ADD COLUMN IF NOT EXISTS moderator_note TEXT NULL AFTER description,
  ADD COLUMN IF NOT EXISTS resolution_action ENUM('none','restrict','remove','restore','warn') NOT NULL DEFAULT 'none' AFTER status,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE moderation_actions
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(40) NULL AFTER id,
  ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER reason;
UPDATE moderation_actions SET public_id=CONCAT('legacy-mod-action-',id) WHERE public_id IS NULL OR public_id='';
ALTER TABLE moderation_actions MODIFY COLUMN public_id VARCHAR(40) NOT NULL;
ALTER TABLE moderation_actions ADD UNIQUE KEY IF NOT EXISTS uq_moderation_action_public(public_id);

ALTER TABLE comments
  ADD COLUMN IF NOT EXISTS moderation_status ENUM('visible','restricted','removed') NOT NULL DEFAULT 'visible' AFTER body,
  ADD COLUMN IF NOT EXISTS removed_at DATETIME NULL AFTER moderation_status,
  ADD COLUMN IF NOT EXISTS removed_by_user_id BIGINT UNSIGNED NULL AFTER removed_at;
ALTER TABLE comments ADD INDEX IF NOT EXISTS idx_comment_moderation(annotation_id,moderation_status,created_at);
