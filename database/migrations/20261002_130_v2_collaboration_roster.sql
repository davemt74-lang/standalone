-- V2 Section 1: roster metadata only. No changes to research_agents.project_id uniqueness
-- and no grant of access to another Agent's project, memory or chat.
CREATE TABLE v2_collaboration_plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 public_id VARCHAR(40) NOT NULL UNIQUE,
 lead_agent_id BIGINT UNSIGNED NOT NULL UNIQUE,
 project_id BIGINT UNSIGNED NOT NULL UNIQUE,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_v2_collaboration_owner(owner_user_id,status),
 CONSTRAINT fk_v2_collaboration_lead FOREIGN KEY (lead_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
 CONSTRAINT fk_v2_collaboration_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_v2_collaboration_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE v2_collaboration_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 plan_id BIGINT UNSIGNED NOT NULL,
 agent_id BIGINT UNSIGNED NOT NULL,
 role ENUM('lead','evidence','analyst','verifier','publication_editor') NOT NULL,
 status ENUM('active','paused','removed') NOT NULL DEFAULT 'active',
 granted_by_user_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_v2_collaboration_member(plan_id,agent_id),
 INDEX idx_v2_collaboration_agent(agent_id,status),
 CONSTRAINT fk_v2_collaboration_member_plan FOREIGN KEY (plan_id) REFERENCES v2_collaboration_plans(id) ON DELETE CASCADE,
 CONSTRAINT fk_v2_collaboration_member_agent FOREIGN KEY (agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
 CONSTRAINT fk_v2_collaboration_member_grant FOREIGN KEY (granted_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE v2_collaboration_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 plan_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 event_type ENUM('created','assigned','role_changed','paused','resumed','removed','plan_paused','plan_resumed') NOT NULL,
 agent_id BIGINT UNSIGNED NULL,
 details_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_v2_collaboration_events(plan_id,id),
 CONSTRAINT fk_v2_collaboration_event_plan FOREIGN KEY (plan_id) REFERENCES v2_collaboration_plans(id) ON DELETE CASCADE,
 CONSTRAINT fk_v2_collaboration_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
 CONSTRAINT fk_v2_collaboration_event_agent FOREIGN KEY (agent_id) REFERENCES research_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
