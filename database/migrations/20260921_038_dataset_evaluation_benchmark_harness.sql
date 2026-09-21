-- Annotated Phase 39 — Dataset Evaluation & Benchmark Harness
-- Reproducible benchmark suites and runs over frozen Phase 38 evaluation datasets.
-- Automated metrics are advisory; human review remains first-class.

CREATE TABLE IF NOT EXISTS data_evaluation_suites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  dataset_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  benchmark_type VARCHAR(24) NOT NULL DEFAULT 'retrieval',
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  description TEXT NULL,
  top_k INT UNSIGNED NOT NULL DEFAULT 5,
  model_id BIGINT UNSIGNED NULL,
  prompt_template TEXT NULL,
  config_json JSON NOT NULL,
  config_hash CHAR(64) NOT NULL,
  baseline_run_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_eval_suite_name_dataset(name,dataset_id),
  INDEX idx_data_eval_suite_status(status,benchmark_type,updated_at),
  INDEX idx_data_eval_suite_dataset(dataset_id,updated_at),
  CONSTRAINT fk_data_eval_suite_dataset FOREIGN KEY(dataset_id) REFERENCES data_datasets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_eval_suite_model FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_data_eval_suite_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_evaluation_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  suite_id BIGINT UNSIGNED NOT NULL,
  expected_dataset_item_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(180) NOT NULL,
  query_text TEXT NOT NULL,
  reference_answer TEXT NULL,
  weight DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
  tags_json JSON NULL,
  case_hash CHAR(64) NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_eval_case_suite_position(suite_id,position),
  INDEX idx_data_eval_case_suite(suite_id,id),
  CONSTRAINT fk_data_eval_case_suite FOREIGN KEY(suite_id) REFERENCES data_evaluation_suites(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_eval_case_expected FOREIGN KEY(expected_dataset_item_id) REFERENCES data_dataset_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_eval_case_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_evaluation_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  suite_id BIGINT UNSIGNED NOT NULL,
  dataset_id BIGINT UNSIGNED NOT NULL,
  benchmark_type VARCHAR(24) NOT NULL,
  model_id BIGINT UNSIGNED NULL,
  model_snapshot_json JSON NULL,
  dataset_manifest_hash CHAR(64) NOT NULL,
  suite_config_hash CHAR(64) NOT NULL,
  cases_hash CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'queued',
  runner VARCHAR(32) NOT NULL DEFAULT 'web',
  result_summary_json JSON NULL,
  result_summary_hash CHAR(64) NULL,
  error_text VARCHAR(1000) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_data_eval_run_suite(suite_id,created_at),
  INDEX idx_data_eval_run_status(status,created_at),
  CONSTRAINT fk_data_eval_run_suite FOREIGN KEY(suite_id) REFERENCES data_evaluation_suites(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_eval_run_dataset FOREIGN KEY(dataset_id) REFERENCES data_datasets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_eval_run_model FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  CONSTRAINT fk_data_eval_run_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_evaluation_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  case_id BIGINT UNSIGNED NOT NULL,
  rank_of_expected INT UNSIGNED NULL,
  retrieved_json JSON NULL,
  response_text MEDIUMTEXT NULL,
  response_hash CHAR(64) NULL,
  citations_json JSON NULL,
  metrics_json JSON NOT NULL,
  metrics_hash CHAR(64) NOT NULL,
  passed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_eval_result_run_case(run_id,case_id),
  INDEX idx_data_eval_result_pass(run_id,passed),
  CONSTRAINT fk_data_eval_result_run FOREIGN KEY(run_id) REFERENCES data_evaluation_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_eval_result_case FOREIGN KEY(case_id) REFERENCES data_evaluation_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_evaluation_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  result_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  decision VARCHAR(24) NOT NULL,
  relevance_score TINYINT UNSIGNED NULL,
  groundedness_score TINYINT UNSIGNED NULL,
  accuracy_score TINYINT UNSIGNED NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_eval_review_result_reviewer(result_id,reviewer_user_id),
  INDEX idx_data_eval_review_result(result_id,created_at),
  CONSTRAINT fk_data_eval_review_result FOREIGN KEY(result_id) REFERENCES data_evaluation_results(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_eval_review_reviewer FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_evaluation_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  suite_id BIGINT UNSIGNED NOT NULL,
  run_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_data_eval_event_suite(suite_id,created_at),
  INDEX idx_data_eval_event_run(run_id,created_at),
  CONSTRAINT fk_data_eval_event_suite FOREIGN KEY(suite_id) REFERENCES data_evaluation_suites(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_eval_event_run FOREIGN KEY(run_id) REFERENCES data_evaluation_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_eval_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
