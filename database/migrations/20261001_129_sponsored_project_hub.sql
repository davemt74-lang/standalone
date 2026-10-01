-- Sponsored Research Section 3: governed per-campaign collaboration ledger.
-- Does not transfer Research Agent desktop/library ownership or duplicate Agent tasks.
CREATE TABLE IF NOT EXISTS sponsored_research_project_updates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 public_id VARCHAR(40) NOT NULL UNIQUE,
 campaign_id BIGINT UNSIGNED NOT NULL,
 author_user_id BIGINT UNSIGNED NOT NULL,
 audience ENUM('participants','sponsor_only') NOT NULL DEFAULT 'participants',
 update_type ENUM('progress','question','answer','announcement','decision') NOT NULL DEFAULT 'progress',
 subject VARCHAR(180) NOT NULL,
 body TEXT NOT NULL,
 campaign_revision INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_sponsored_project_updates_campaign(campaign_id,audience,created_at,id),
 INDEX idx_sponsored_project_updates_author(author_user_id,created_at,id),
 CONSTRAINT fk_sponsored_project_updates_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
 CONSTRAINT fk_sponsored_project_updates_actor FOREIGN KEY(author_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
