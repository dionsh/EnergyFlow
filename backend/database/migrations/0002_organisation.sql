-- Sites, departments, working schedules, holidays, production overrides and output.

CREATE TABLE sites (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(200) NULL,
  city VARCHAR(80) NULL,
  latitude DECIMAL(8,5) NULL,
  longitude DECIMAL(8,5) NULL,
  floor_area_m2 INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sites_company (company_id),
  CONSTRAINT fk_sites_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  KEY idx_departments_company (company_id),
  CONSTRAINT fk_departments_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  KEY idx_schedules_company (company_id),
  CONSTRAINT fk_schedules_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- day_of_week: 1 = Monday ... 7 = Sunday (ISO). end_time < start_time means the window crosses midnight.
CREATE TABLE schedule_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  KEY idx_schedule_rules_schedule (schedule_id),
  CONSTRAINT fk_schedule_rules_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- company_id NULL = national public holiday for the country.
CREATE TABLE calendar_days (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  country CHAR(2) NOT NULL DEFAULT 'XK',
  date DATE NOT NULL,
  kind ENUM('public_holiday','closed','special') NOT NULL,
  label VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_calendar_days (company_id, country, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legitimate extra shifts, so planned overtime is never flagged as waste.
CREATE TABLE production_overrides (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  machine_id INT UNSIGNED NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reason VARCHAR(200) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_overrides_company_start (company_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE production_output (
  company_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  unit VARCHAR(20) NOT NULL,
  PRIMARY KEY (company_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
