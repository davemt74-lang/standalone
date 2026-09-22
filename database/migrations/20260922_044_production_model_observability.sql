-- Annotated Phase 45 — Production Model Observability & Outcome Monitoring
-- Read/measure/alert layer for governed production model behavior.
-- This phase never changes model lifecycle, deployment stage, or AI routing.

ALTER TABLE ai_models
  ADD COLUMN IF NOT EXISTS input_cost_per_million_usd DECIMAL(14,6) NULL AFTER max_output_tokens,
  ADD COLUMN IF NOT EXISTS output_cost_per_million_usd DECIMAL(14,6) NULL AFTER input_cost_per_million_usd;

CREATE TABLE IF NOT EXISTS data_model_observations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  ai_run_id BIGINT UNSIGNED NOT NULL UNIQUE,
  deployment_id BIGINT UNSIGNED NULL,
  model_version_id BIGINT UNSIGNED NULL,
  ai_model_id BIGINT UNSIGNED NULL,
  route_key VARCHAR(64) NOT NULL,
  source_task_type VARCHAR(80) NOT NULL,
  rollout_stage VARCHAR(32) NULL,
  is_shadow TINYINT(1) NOT NULL DEFAULT 0,
  run_status VARCHAR(24) NOT NULL,
  initiated_by VARCHAR(24) NOT NULL,
  actor_plan_tier VARCHAR(24) NULL,
  latency_ms INT UNSIGNED NULL,
  input_tokens INT UNSIGNED NULL,
  output_tokens INT UNSIGNED NULL,
  estimated_cost_micros BIGINT UNSIGNED NULL,
  error_class VARCHAR(64) NULL,
  scope_type VARCHAR(64) NULL,
  scope_public_id VARCHAR(64) NULL,
  observation_hash CHAR(64) NOT NULL,
  observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_observation_deployment(deployment_id,route_key,observed_at),
  INDEX idx_model_observation_version(model_version_id,route_key,observed_at),
  INDEX idx_model_observation_runtime(ai_model_id,observed_at),
  INDEX idx_model_observation_status(run_status,observed_at),
  CONSTRAINT fk_model_observation_run FOREIGN KEY(ai_run_id) REFERENCES ai_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_observation_deployment FOREIGN KEY(deployment_id) REFERENCES data_model_deployments(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_observation_version FOREIGN KEY(model_version_id) REFERENCES data_model_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_observation_runtime FOREIGN KEY(ai_model_id) REFERENCES ai_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_outcome_signals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  observation_id BIGINT UNSIGNED NOT NULL,
  signal_type VARCHAR(64) NOT NULL,
  signal_value DECIMAL(8,4) NOT NULL,
  source_type VARCHAR(32) NOT NULL,
  source_public_id VARCHAR(64) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  note VARCHAR(2000) NULL,
  signal_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_outcome_source(observation_id,signal_type,source_type,source_public_id),
  INDEX idx_model_outcome_observation(observation_id,created_at),
  INDEX idx_model_outcome_type(signal_type,created_at),
  CONSTRAINT fk_model_outcome_observation FOREIGN KEY(observation_id) REFERENCES data_model_observations(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_outcome_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_monitoring_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deployment_id BIGINT UNSIGNED NOT NULL UNIQUE,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  window_minutes INT UNSIGNED NOT NULL DEFAULT 120,
  min_samples INT UNSIGNED NOT NULL DEFAULT 5,
  max_failure_rate DECIMAL(7,6) NOT NULL DEFAULT 0.100000,
  max_p95_latency_ms INT UNSIGNED NOT NULL DEFAULT 30000,
  max_failure_rate_delta DECIMAL(7,6) NULL DEFAULT 0.100000,
  max_p95_latency_delta_ms INT UNSIGNED NULL DEFAULT 10000,
  max_avg_input_tokens INT UNSIGNED NULL,
  max_avg_output_tokens INT UNSIGNED NULL,
  max_avg_cost_micros BIGINT UNSIGNED NULL,
  min_avg_outcome_score DECIMAL(8,4) NULL,
  policy_hash CHAR(64) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_model_monitor_policy_deployment FOREIGN KEY(deployment_id) REFERENCES data_model_deployments(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_monitor_policy_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_monitor_policy_updater FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_health_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  deployment_id BIGINT UNSIGNED NOT NULL,
  model_version_id BIGINT UNSIGNED NULL,
  route_key VARCHAR(64) NOT NULL,
  rollout_stage VARCHAR(32) NULL,
  window_start DATETIME NOT NULL,
  window_end DATETIME NOT NULL,
  sample_count INT UNSIGNED NOT NULL,
  health_status VARCHAR(24) NOT NULL,
  metrics_json JSON NOT NULL,
  metrics_hash CHAR(64) NOT NULL,
  policy_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_health_deployment(deployment_id,route_key,created_at),
  INDEX idx_model_health_status(health_status,created_at),
  CONSTRAINT fk_model_health_deployment FOREIGN KEY(deployment_id) REFERENCES data_model_deployments(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_health_version FOREIGN KEY(model_version_id) REFERENCES data_model_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_incidents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  deployment_id BIGINT UNSIGNED NOT NULL,
  model_version_id BIGINT UNSIGNED NULL,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  route_key VARCHAR(64) NOT NULL,
  incident_type VARCHAR(64) NOT NULL,
  severity VARCHAR(16) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  metric_name VARCHAR(64) NOT NULL,
  current_value DECIMAL(18,6) NOT NULL,
  threshold_value DECIMAL(18,6) NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary VARCHAR(2000) NULL,
  evidence_hash CHAR(64) NOT NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_by_user_id BIGINT UNSIGNED NULL,
  resolved_by_user_id BIGINT UNSIGNED NULL,
  review_note VARCHAR(3000) NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_model_incident_status(status,severity,last_seen_at),
  INDEX idx_model_incident_deployment(deployment_id,route_key,last_seen_at),
  CONSTRAINT fk_model_incident_deployment FOREIGN KEY(deployment_id) REFERENCES data_model_deployments(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_incident_version FOREIGN KEY(model_version_id) REFERENCES data_model_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_incident_snapshot FOREIGN KEY(snapshot_id) REFERENCES data_model_health_snapshots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_model_incident_ack FOREIGN KEY(acknowledged_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_incident_resolver FOREIGN KEY(resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_model_incident_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  incident_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(48) NOT NULL,
  from_status VARCHAR(24) NULL,
  to_status VARCHAR(24) NULL,
  note VARCHAR(3000) NULL,
  evidence_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_incident_event(incident_id,created_at),
  CONSTRAINT fk_model_incident_event_incident FOREIGN KEY(incident_id) REFERENCES data_model_incidents(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_incident_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
