-- Telemetry. readings_raw (10 s) is kept for 72 h, readings_15m is the analytics
-- source of truth and is kept forever (fits Aiven's 1 GB free disk).

CREATE TABLE readings_raw (
  machine_id INT UNSIGNED NOT NULL,
  ts DATETIME NOT NULL,
  device_id INT UNSIGNED NOT NULL,
  power_kw DECIMAL(9,3) NOT NULL,
  voltage_v DECIMAL(6,2) NULL,
  current_a DECIMAL(8,3) NULL,
  power_factor DECIMAL(4,3) NULL,
  frequency_hz DECIMAL(5,3) NULL,
  energy_kwh_counter DECIMAL(14,3) NULL,
  temperature_c DECIMAL(5,2) NULL,
  state TINYINT UNSIGNED NOT NULL,
  source ENUM('device','simulator') NOT NULL,
  PRIMARY KEY (machine_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE readings_15m (
  machine_id INT UNSIGNED NOT NULL,
  bucket_start DATETIME NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  kwh DECIMAL(10,4) NOT NULL,
  avg_kw DECIMAL(9,3) NULL,
  max_kw DECIMAL(9,3) NULL,
  min_kw DECIMAL(9,3) NULL,
  avg_pf DECIMAL(4,3) NULL,
  avg_v DECIMAL(6,2) NULL,
  max_temp_c DECIMAL(5,2) NULL,
  running_s SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  idle_s SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  off_s SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  start_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  cycle_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  tariff_period ENUM('high','low') NOT NULL,
  is_scheduled TINYINT(1) NOT NULL,
  source ENUM('rollup','sim_direct') NOT NULL,
  PRIMARY KEY (machine_id, bucket_start),
  KEY idx_readings_15m_company (company_id, bucket_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE machine_state_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  machine_id INT UNSIGNED NOT NULL,
  ts DATETIME NOT NULL,
  from_state TINYINT UNSIGNED NOT NULL,
  to_state TINYINT UNSIGNED NOT NULL,
  power_kw DECIMAL(9,3) NULL,
  peak_current_a DECIMAL(8,2) NULL,
  KEY idx_state_events_machine (machine_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE power_quality_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NOT NULL,
  ts DATETIME NOT NULL,
  kind ENUM('sag','swell','outage','frequency') NOT NULL,
  duration_s DECIMAL(8,2) NULL,
  min_v DECIMAL(6,2) NULL,
  max_v DECIMAL(6,2) NULL,
  KEY idx_pq_company (company_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Open-Meteo cache: real Pristina weather drives HVAC simulation and degree-day normalisation.
CREATE TABLE weather_hourly (
  site_id INT UNSIGNED NOT NULL,
  ts DATETIME NOT NULL,
  temp_c DECIMAL(4,1) NOT NULL,
  is_forecast TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (site_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
