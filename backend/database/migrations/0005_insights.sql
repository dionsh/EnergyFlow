-- Detection, waste, alerts, fingerprints, ML registry, forecasts, notifications, scores.

CREATE TABLE machine_baselines (
  machine_id INT UNSIGNED NOT NULL,
  day_type ENUM('working','non_working') NOT NULL,
  hour_of_day TINYINT UNSIGNED NOT NULL,
  p50_kw DECIMAL(9,3) NOT NULL,
  mad_kw DECIMAL(9,3) NOT NULL,
  p50_kwh DECIMAL(10,4) NOT NULL,
  n_samples SMALLINT UNSIGNED NOT NULL,
  window_start DATE NOT NULL,
  window_end DATE NOT NULL,
  PRIMARY KEY (machine_id, day_type, hour_of_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE waste_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  machine_id INT UNSIGNED NOT NULL,
  type ENUM('after_hours','idle','excess_vs_baseline','spike','night_cycling','peak_coincidence') NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  energy_kwh DECIMAL(10,3) NOT NULL DEFAULT 0,
  cost_eur DECIMAL(10,2) NOT NULL DEFAULT 0,
  co2_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
  severity ENUM('info','warning','critical') NOT NULL,
  evidence JSON NOT NULL,
  action_status ENUM('none','suggested','acted','dismissed') NOT NULL DEFAULT 'none',
  recommendation_id INT UNSIGNED NULL,
  command_id INT UNSIGNED NULL,
  KEY idx_waste_company_time (company_id, started_at),
  KEY idx_waste_machine_time (machine_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alerts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  machine_id INT UNSIGNED NULL,
  device_id INT UNSIGNED NULL,
  type VARCHAR(30) NOT NULL,
  method ENUM('rule','statistical','pattern','ml') NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL,
  status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  dedupe_key VARCHAR(120) NOT NULL,
  title_key VARCHAR(80) NOT NULL,
  params JSON NOT NULL,
  evidence JSON NOT NULL,
  waste_event_id INT UNSIGNED NULL,
  opened_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  acknowledged_by INT UNSIGNED NULL,
  acknowledged_at DATETIME NULL,
  resolved_at DATETIME NULL,
  KEY idx_alerts_company_status (company_id, status, severity),
  UNIQUE KEY uq_alerts_dedupe (company_id, dedupe_key, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fingerprints (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  machine_id INT UNSIGNED NOT NULL,
  kind ENUM('reference','weekly') NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  features JSON NOT NULL,
  predicted_type VARCHAR(30) NULL,
  confidence DECIMAL(4,3) NULL,
  top_k JSON NULL,
  drift_score DECIMAL(5,2) NULL,
  model_id INT UNSIGNED NULL,
  KEY idx_fingerprints_machine (machine_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ml_models (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  task ENUM('fingerprint','anomaly','forecast') NOT NULL,
  name VARCHAR(80) NOT NULL,
  version VARCHAR(20) NOT NULL,
  method VARCHAR(80) NOT NULL,
  params JSON NOT NULL,
  metrics JSON NULL,
  training_data TEXT NULL,
  limitations TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  trained_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forecasts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  machine_id INT UNSIGNED NULL,
  horizon ENUM('day','week','month') NOT NULL,
  target_start DATE NOT NULL,
  target_end DATE NOT NULL,
  kwh_p10 DECIMAL(12,2) NULL,
  kwh_p50 DECIMAL(12,2) NOT NULL,
  kwh_p90 DECIMAL(12,2) NULL,
  cost_eur_p50 DECIMAL(12,2) NOT NULL,
  co2_kg_p50 DECIMAL(12,2) NOT NULL,
  hourly_profile JSON NULL,
  model_id INT UNSIGNED NULL,
  generated_at DATETIME NOT NULL,
  KEY idx_forecasts_company (company_id, horizon, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  category ENUM('critical','warning','insight','achievement') NOT NULL,
  title_key VARCHAR(80) NOT NULL,
  params JSON NOT NULL,
  link VARCHAR(200) NULL,
  source_type VARCHAR(30) NULL,
  source_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_notifications_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_reads (
  notification_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL,
  PRIMARY KEY (notification_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE score_snapshots (
  company_id INT UNSIGNED NOT NULL,
  kind ENUM('energyflow','esg_readiness') NOT NULL,
  computed_at DATETIME NOT NULL,
  score DECIMAL(5,1) NOT NULL,
  breakdown JSON NOT NULL,
  PRIMARY KEY (company_id, kind, computed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
