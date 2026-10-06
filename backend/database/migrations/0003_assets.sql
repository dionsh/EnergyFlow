-- Machines, EnergyFlow devices, CT channel mapping and the live state cache.

CREATE TABLE machine_types (
  code VARCHAR(30) NOT NULL PRIMARY KEY,
  label_en VARCHAR(60) NOT NULL,
  label_sq VARCHAR(60) NOT NULL,
  icon VARCHAR(30) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind = 'incomer' is the whole-site meter. It is excluded from machine lists and
-- sums, and used for coverage and the "unmonitored loads" branch.
CREATE TABLE machines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NULL,
  kind ENUM('machine','incomer') NOT NULL DEFAULT 'machine',
  code VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  type_code VARCHAR(30) NOT NULL,
  manufacturer VARCHAR(80) NULL,
  model VARCHAR(80) NULL,
  year_installed SMALLINT UNSIGNED NULL,
  rated_power_kw DECIMAL(8,2) NULL,
  phases TINYINT UNSIGNED NOT NULL DEFAULT 3,
  schedule_id INT UNSIGNED NULL,
  criticality ENUM('critical','normal','flexible') NOT NULL DEFAULT 'normal',
  control_mode ENUM('monitor','notify','approve','auto') NOT NULL DEFAULT 'notify',
  off_threshold_kw DECIMAL(7,3) NOT NULL DEFAULT 0.050,
  idle_threshold_kw DECIMAL(7,3) NULL,
  max_temp_c DECIMAL(5,1) NULL,
  sim_profile JSON NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_machines_code (company_id, code),
  KEY idx_machines_site (site_id),
  CONSTRAINT fk_machines_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_machines_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_machines_type FOREIGN KEY (type_code) REFERENCES machine_types(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  serial VARCHAR(30) NOT NULL,
  model ENUM('EF-Node-1P','EF-Node-3P','EF-Gateway','EF-Bridge') NOT NULL,
  firmware_version VARCHAR(20) NULL,
  key_hash CHAR(64) NOT NULL,
  is_simulated TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('provisioned','online','offline','retired') NOT NULL DEFAULT 'provisioned',
  last_seen_at DATETIME NULL,
  last_rssi SMALLINT NULL,
  last_uptime_s INT UNSIGNED NULL,
  buffered_count INT UNSIGNED NULL,
  installed_at DATETIME NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_devices_serial (serial),
  KEY idx_devices_company (company_id),
  CONSTRAINT fk_devices_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- valid_from / valid_to keep history correct when a CT is moved to another machine.
CREATE TABLE device_channels (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id INT UNSIGNED NOT NULL,
  channel_no TINYINT UNSIGNED NOT NULL,
  machine_id INT UNSIGNED NOT NULL,
  measurement_mode ENUM('single_phase','three_ct','single_ct_balanced','modbus_meter') NOT NULL,
  ct_rating_a SMALLINT UNSIGNED NULL,
  has_relay TINYINT(1) NOT NULL DEFAULT 0,
  valid_from DATETIME NOT NULL,
  valid_to DATETIME NULL,
  UNIQUE KEY uq_device_channel (device_id, channel_no, valid_from),
  KEY idx_device_channels_machine (machine_id),
  CONSTRAINT fk_device_channels_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_device_channels_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per machine: the latest reading and state, so live views are a single cheap query.
CREATE TABLE machine_live (
  machine_id INT UNSIGNED NOT NULL PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  ts DATETIME NOT NULL,
  state ENUM('off','idle','running','abnormal') NOT NULL,
  state_since DATETIME NOT NULL,
  power_kw DECIMAL(9,3) NULL,
  voltage_v DECIMAL(6,2) NULL,
  current_a DECIMAL(8,3) NULL,
  power_factor DECIMAL(4,3) NULL,
  frequency_hz DECIMAL(5,3) NULL,
  temperature_c DECIMAL(5,2) NULL,
  today_kwh DECIMAL(10,3) NOT NULL DEFAULT 0,
  KEY idx_machine_live_company (company_id),
  CONSTRAINT fk_machine_live_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
