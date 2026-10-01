-- Sponsored Project Workspace S3: append-only, permission-scoped progress/activity.
-- Reuses approved participation, assignments, submissions and canonical Agent workspaces.
-- No duplicate task, payment, document or Agent access engine.
CREATE TABLE IF NOT EXISTS sponsored_project_updates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  scope ENUM('project','participant') NOT NULL,
  participant_user_id BIGINT UNSIGNED NULL,
  milestone_position SMALLINT UNSIGNED NULL,
  progress_status ENUM('update','started','blocked','ready_for_review','completed') NOT NULL DEFAULT 'update',
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_project_updates_campaign(campaign_id,id),
  INDEX idx_sponsored_project_updates_participant(campaign_id,participant_user_id,id),
  CONSTRAINT fk_sponsored_project_updates_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_updates_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_updates_participant FOREIGN KEY(participant_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_sponsored_project_updates_scope CHECK (
    (scope='project' AND participant_user_id IS NULL)
    OR (scope='participant' AND participant_user_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
