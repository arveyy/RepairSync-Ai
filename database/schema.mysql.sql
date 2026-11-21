-- RepairSync AI - MySQL / MariaDB schema (XAMPP).
-- Import with phpMyAdmin (Import tab) or run: C:\xampp\mysql\bin\mysql -u root < database\schema.mysql.sql
-- Safe to run more than once. All timestamps are stored in UTC.

CREATE DATABASE IF NOT EXISTS repairsync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE repairsync;

CREATE TABLE IF NOT EXISTS shops (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  city VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  listed TINYINT NOT NULL DEFAULT 1,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  review_note VARCHAR(255) NULL,
  reviewed_at DATETIME NULL,
  reviewed_by INT NULL,
  logo_path VARCHAR(255) NULL,
  accent_color VARCHAR(16) NULL,
  address VARCHAR(255) NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  opening_hours TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  name VARCHAR(190) NOT NULL,
  address VARCHAR(255) NOT NULL,
  phone VARCHAR(40) NULL,
  hours VARCHAR(120) NULL,
  INDEX idx_branches_shop (shop_id),
  CONSTRAINT fk_branches_shop FOREIGN KEY (shop_id) REFERENCES shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(40) NULL,
  role VARCHAR(16) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  branch_id INT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_shop (shop_id),
  CONSTRAINT fk_users_shop FOREIGN KEY (shop_id) REFERENCES shops(id),
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  user_id INT NULL,
  name VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  consent_at DATETIME NULL,
  anonymized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customers_shop (shop_id),
  CONSTRAINT fk_customers_shop FOREIGN KEY (shop_id) REFERENCES shops(id),
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repairs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  code VARCHAR(40) NOT NULL UNIQUE,
  track_token VARCHAR(80) NOT NULL,
  customer_id INT NOT NULL,
  branch_id INT NOT NULL,
  device_type VARCHAR(16) NOT NULL,
  brand VARCHAR(120) NULL,
  model VARCHAR(120) NULL,
  serial_no VARCHAR(120) NULL,
  accessories VARCHAR(255) NULL,
  reported_symptoms TEXT NOT NULL,
  condition_notes TEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'booked',
  priority VARCHAR(10) NOT NULL DEFAULT 'normal',
  assigned_to INT NULL,
  findings TEXT NULL,
  verified_diagnosis TEXT NULL,
  repair_action TEXT NULL,
  est_ready_date DATE NULL,
  approval_state VARCHAR(10) NOT NULL DEFAULT 'none',
  qc_passed TINYINT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'portal',
  external_ref VARCHAR(60) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  released_at DATETIME NULL,
  INDEX idx_repairs_status (status),
  INDEX idx_repairs_customer (customer_id),
  INDEX idx_repairs_assigned (assigned_to),
  INDEX idx_repairs_shop (shop_id),
  CONSTRAINT fk_repairs_shop FOREIGN KEY (shop_id) REFERENCES shops(id),
  CONSTRAINT fk_repairs_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_repairs_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
  CONSTRAINT fk_repairs_assigned FOREIGN KEY (assigned_to) REFERENCES users(id),
  CONSTRAINT fk_repairs_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_repair_device CHECK (device_type IN ('smartphone','laptop','tablet','other')),
  CONSTRAINT chk_repair_priority CHECK (priority IN ('low','normal','high','urgent')),
  CONSTRAINT chk_repair_approval CHECK (approval_state IN ('none','pending','approved','declined'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repair_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  from_status VARCHAR(24) NULL,
  to_status VARCHAR(24) NOT NULL,
  actor_id INT NULL,
  actor_label VARCHAR(190) NULL,
  note TEXT NULL,
  visible_to_customer TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_repair (repair_id),
  CONSTRAINT fk_events_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repair_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DOUBLE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_items_repair (repair_id),
  CONSTRAINT fk_items_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE,
  CONSTRAINT chk_item_kind CHECK (kind IN ('labor','part','other')),
  CONSTRAINT chk_item_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  amount DOUBLE NOT NULL,
  method VARCHAR(20) NOT NULL DEFAULT 'cash',
  received_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_payments_repair (repair_id),
  INDEX idx_payments_created (created_at),
  CONSTRAINT fk_payments_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_user FOREIGN KEY (received_by) REFERENCES users(id),
  CONSTRAINT chk_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  device_type VARCHAR(16) NOT NULL,
  brand VARCHAR(120) NULL,
  model_hint VARCHAR(120) NULL,
  symptoms TEXT NOT NULL,
  diagnosis VARCHAR(255) NOT NULL,
  root_cause TEXT NULL,
  checks TEXT NULL,
  repair_action TEXT NULL,
  parts VARCHAR(255) NULL,
  cost_min DOUBLE NULL,
  cost_max DOUBLE NULL,
  hours DOUBLE NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'approved',
  source VARCHAR(20) NOT NULL DEFAULT 'shop',
  repair_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cases_shop (shop_id),
  INDEX idx_cases_status (status),
  CONSTRAINT fk_cases_shop FOREIGN KEY (shop_id) REFERENCES shops(id),
  CONSTRAINT fk_cases_repair FOREIGN KEY (repair_id) REFERENCES repairs(id),
  CONSTRAINT fk_cases_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_case_status CHECK (status IN ('approved','pending','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  query_text TEXT NOT NULL,
  result_json LONGTEXT NOT NULL,
  mode VARCHAR(12) NOT NULL DEFAULT 'local',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  feedback_diagnosis VARCHAR(255) NULL,
  feedback_rank INT NULL,
  feedback_accepted TINYINT NULL,
  feedback_note TEXT NULL,
  feedback_by INT NULL,
  feedback_at DATETIME NULL,
  INDEX idx_airuns_repair (repair_id),
  CONSTRAINT fk_airuns_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE,
  CONSTRAINT fk_airuns_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_airuns_fb_user FOREIGN KEY (feedback_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  channel VARCHAR(12) NOT NULL,
  to_addr VARCHAR(190) NULL,
  message TEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'simulated',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_repair (repair_id),
  CONSTRAINT fk_notif_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop_id INT NULL,
  actor_id INT NULL,
  actor_label VARCHAR(190) NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id VARCHAR(40) NULL,
  detail TEXT NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_shop (shop_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window rate limiter (PHP has no long-running process, so counters live in the database).
CREATE TABLE IF NOT EXISTS rate_limits (
  k VARCHAR(190) NOT NULL PRIMARY KEY,
  window_start BIGINT NOT NULL,
  n INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
