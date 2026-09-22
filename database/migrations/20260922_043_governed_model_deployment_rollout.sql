-- Annotated Phase 44 — Governed Model Deployment & Rollout
-- Explicit execution layer for signed Phase 43 Proceed decisions.
-- Human checkpoints are required between served rollout stages.

CREATE TABLE IF NOT EXISTS data_model_deployments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  release_decision_id BIGINT UNSIGNED NOT NULL,
  registry_id BIGINT UNSIGNED NOT NULL,
  model_version_id BIGINT UNSIGNED NOT NULL,
  rollback_version_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  paused_stage VARCHAR(24) NULL,
  route_keys_json JSON NOT NULL,
  route_keys_hash CHAR(64) NOT NULL,
  planned_traffic_percent INT UNSIGNED NOT NULL DEFAULT 10,
  current_traffic_percent INT UNSIGNED NOT NULL DEFAULT 0,
  monitoring_policy_json JSON NOT NULL,
  monitoring_policy_hash CHAR(64) NOT NULL,
  release_decision_hash CHAR(64) NOT NULL,
  release_signature_hash CHAR(64) NOT NULL,
  model_version_hash CHAR(64) NOT NULL,
  rollback_version_hash CHAR(64) NOT NULL,
  routing_before_json JSON NOT NULL,
  routing_before_hash CHAR(64) NOT NULL,
  routing_current_json JSON NOT NULL,
  routing_current_hash CHAR(64) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  preflight_at DATETIME NULL,
  started_at DATETIME NULL,
  stage_changed_at DATETIME NULL,
  paused_at DATETIME NULL,
  stopped_at DATETIME NULL,
  full_at DATETIME NULL,
  rolled_back_at DATETIME NULL,
  UNIQUE KEY uq_model_deployment_release_decision(release_decision_id),
  INDEX idx_model_deployment_status(status,updated_at),
  INDEX idx_model_deployment_registry(registry_id,status),
  INDEX idx_model_deployment_model(model_version_id,status),
  CONSTRAINT fk_model_deployment_release FOREIGN KEY(release_decision_id) REFERENCES data_model_release_decisions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_deployment_registry FOREIGN KEY(registry_id) REFERENCES data_model_registry(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_deployment_model FOREIGN KEY(model_version_id) REFERENCES data_model_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_deployment_rollback FOREIGN KEY(rollback_version_id) REFERENCES data_model_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_deployment_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_deployment_checkpoints (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deployment_id BIGINT UNSIGNED NOT NULL,
  stage VARCHAR(24) NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  recommendation VARCHAR(24) NOT NULL,
  note VARCHAR(3000) NULL,
  deployment_snapshot_hash CHAR(64) NOT NULL,
  signature_hash CHAR(64) NOT NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_deployment_checkpoint(deployment_id,stage,reviewer_user_id),
  INDEX idx_model_deployment_checkpoint_stage(deployment_id,stage,recommendation),
  CONSTRAINT fk_model_deployment_checkpoint_deployment FOREIGN KEY(deployment_id) REFERENCES data_model_deployments(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_deployment_checkpoint_reviewer FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_deployment_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deployment_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  stage VARCHAR(24) NULL,
  route_snapshot_hash CHAR(64) NULL,
  details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_deployment_event(deployment_id,created_at),
  CONSTRAINT fk_model_deployment_event_deployment FOREIGN KEY(deployment_id) REFERENCES data_model_deployments(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_deployment_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_routing_overrides (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deployment_id BIGINT UNSIGNED NOT NULL,
  route_key VARCHAR(64) NOT NULL UNIQUE,
  baseline_ai_model_id BIGINT UNSIGNED NOT NULL,
  candidate_ai_model_id BIGINT UNSIGNED NOT NULL,
  mode VARCHAR(24) NOT NULL,
  traffic_percent INT UNSIGNED NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_model_routing_override_deployment(deployment_id,enabled),
  CONSTRAINT fk_model_routing_override_deployment FOREIGN KEY(deployment_id) REFERENCES data_model_deployments(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_routing_override_baseline FOREIGN KEY(baseline_ai_model_id) REFERENCES ai_models(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_routing_override_candidate FOREIGN KEY(candidate_ai_model_id) REFERENCES ai_models(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
