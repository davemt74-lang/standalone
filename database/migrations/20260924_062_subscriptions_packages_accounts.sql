-- Admin & Accounts V1 — Subscriptions & Packages
-- Research Teams remain separate/manual. This migration creates commercial account/package state only.

CREATE TABLE IF NOT EXISTS subscription_packages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  slug VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  legacy_plan_tier ENUM('free','pro') NOT NULL DEFAULT 'free',
  billing_interval ENUM('monthly') NOT NULL DEFAULT 'monthly',
  monthly_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  monthly_ai_token_allowance BIGINT UNSIGNED NULL,
  member_limit INT UNSIGNED NOT NULL DEFAULT 1,
  trial_days INT UNSIGNED NOT NULL DEFAULT 0,
  feature_json JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_subscription_package_status(status,sort_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO subscription_packages(public_id,slug,name,description,status,is_public,legacy_plan_tier,billing_interval,monthly_price_cents,monthly_ai_token_allowance,member_limit,trial_days,feature_json,sort_order)
VALUES
('pkg-free-trial','free-trial','Free Trial','Starter account for evaluating Annotated. Pricing and monthly AI allowance are editable in Admin.','active',1,'free','monthly',0,NULL,1,14,'{"package_family":"individual","research_teams":"manual"}',10),
('pkg-basic-user','basic-user','Basic User','Individual paid package. Pricing and monthly AI allowance are editable in Admin.','active',1,'pro','monthly',0,NULL,1,0,'{"package_family":"individual","research_teams":"manual"}',20),
('pkg-team-builder','team-builder','Team Builder','Multi-member account package. Account membership is separate from manually managed Research Teams.','active',1,'pro','monthly',0,NULL,5,0,'{"package_family":"organization","research_teams":"manual"}',30)
ON DUPLICATE KEY UPDATE name=VALUES(name);

CREATE TABLE IF NOT EXISTS subscription_package_admin_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  package_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type ENUM('created','updated','archived','reactivated') NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_subscription_package_admin_event(package_id,created_at,id),
  INDEX idx_subscription_package_admin_actor(actor_user_id,created_at,id),
  CONSTRAINT fk_subscription_package_admin_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE CASCADE,
  CONSTRAINT fk_subscription_package_admin_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_type ENUM('personal','organization','internal') NOT NULL DEFAULT 'personal',
  name VARCHAR(190) NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  personal_user_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  subscription_status ENUM('trialing','active','paused','canceled') NOT NULL DEFAULT 'active',
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  trial_ends_at DATETIME NULL,
  package_assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_account_personal_user(personal_user_id),
  INDEX idx_account_owner(owner_user_id,status),
  INDEX idx_account_package(package_id,subscription_status,status),
  CONSTRAINT fk_account_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_account_personal_user FOREIGN KEY(personal_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_account_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_members (
  account_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  account_role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(account_id,user_id),
  INDEX idx_account_member_user(user_id,account_id),
  CONSTRAINT fk_account_member_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_account_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_package_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  previous_package_id BIGINT UNSIGNED NULL,
  new_package_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type ENUM('account_created','package_assigned','package_changed','package_reset','status_changed') NOT NULL,
  reason VARCHAR(500) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_subscription_event_account(account_id,created_at,id),
  INDEX idx_subscription_event_user(user_id,created_at,id),
  INDEX idx_subscription_event_actor(actor_user_id,created_at,id),
  CONSTRAINT fk_subscription_event_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_subscription_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_subscription_event_previous_package FOREIGN KEY(previous_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL,
  CONSTRAINT fk_subscription_event_new_package FOREIGN KEY(new_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL,
  CONSTRAINT fk_subscription_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status,created_at,updated_at)
SELECT
  LEFT(CONCAT('acct-',SHA2(CONCAT('personal:',u.id,':',u.public_id),256)),40),
  'personal',
  CONCAT(u.display_name,' Account'),
  u.id,
  u.id,
  CASE WHEN u.plan_tier='pro'
    THEN (SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1)
    ELSE (SELECT id FROM subscription_packages WHERE slug='free-trial' LIMIT 1)
  END,
  'active',
  CURRENT_DATE,
  DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),
  'active',
  u.created_at,
  CURRENT_TIMESTAMP
FROM users u;

INSERT IGNORE INTO account_members(account_id,user_id,account_role)
SELECT a.id,a.personal_user_id,'owner' FROM accounts a WHERE a.personal_user_id IS NOT NULL;

INSERT INTO subscription_package_events(public_id,account_id,user_id,new_package_id,actor_user_id,event_type,reason,metadata_json,created_at)
SELECT
  LEFT(CONCAT('sevt-',SHA2(CONCAT('backfill:',a.id),256)),40),
  a.id,
  a.personal_user_id,
  a.package_id,
  NULL,
  'account_created',
  'Backfilled from legacy Annotated user plan during subscriptions/packages migration.',
  JSON_OBJECT('source','migration_062'),
  CURRENT_TIMESTAMP
FROM accounts a
WHERE a.personal_user_id IS NOT NULL
  AND NOT EXISTS(SELECT 1 FROM subscription_package_events e WHERE e.account_id=a.id AND e.event_type='account_created');
