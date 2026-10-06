-- Tariffs and bills, emission factors, the carbon ledger, Scope 1 activity data, ESG answers.

-- company_id NULL = a template plan (for example the KESCO universal-service tariff).
-- source_note is required: tariff values must come from the official ERO decision.
CREATE TABLE tariff_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  supplier VARCHAR(80) NULL,
  mode ENUM('regulated_tou_blocks','market_flat','market_tou') NOT NULL,
  voltage_level ENUM('0.4kV','10kV','35kV') NULL,
  fixed_monthly_eur DECIMAL(8,2) NOT NULL DEFAULT 0,
  vat_rate DECIMAL(5,4) NOT NULL,
  valid_from DATE NOT NULL,
  valid_to DATE NULL,
  source_note VARCHAR(300) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tariff_plans_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seasonal high and low windows, for example 10-01 to 03-31 high 07:00-22:00.
CREATE TABLE tariff_windows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tariff_plan_id INT UNSIGNED NOT NULL,
  season_start_mmdd CHAR(5) NOT NULL,
  season_end_mmdd CHAR(5) NOT NULL,
  period ENUM('high','low') NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  CONSTRAINT fk_tariff_windows_plan FOREIGN KEY (tariff_plan_id) REFERENCES tariff_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tariff_rates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tariff_plan_id INT UNSIGNED NOT NULL,
  period ENUM('high','low','flat') NOT NULL,
  block_from_kwh INT UNSIGNED NOT NULL DEFAULT 0,
  block_to_kwh INT UNSIGNED NULL,
  rate_eur_kwh DECIMAL(8,5) NOT NULL,
  CONSTRAINT fk_tariff_rates_plan FOREIGN KEY (tariff_plan_id) REFERENCES tariff_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE utility_bills (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  period_month DATE NOT NULL,
  kwh_high DECIMAL(10,1) NULL,
  kwh_low DECIMAL(10,1) NULL,
  kwh_total DECIMAL(10,1) NOT NULL,
  amount_eur DECIMAL(10,2) NOT NULL,
  UNIQUE KEY uq_utility_bills (company_id, period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- company_id NULL = a global default factor. Reports snapshot the factor they used.
CREATE TABLE emission_factors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  scope ENUM('scope1','scope2_location','scope2_market') NOT NULL,
  activity VARCHAR(40) NOT NULL,
  region CHAR(2) NULL,
  value DECIMAL(12,6) NOT NULL,
  unit VARCHAR(20) NOT NULL,
  reference_year SMALLINT UNSIGNED NOT NULL,
  valid_from DATE NOT NULL,
  valid_to DATE NULL,
  methodology ENUM('lifecycle','direct_combustion','residual_mix','supplier_specific') NOT NULL,
  source_name VARCHAR(200) NOT NULL,
  source_url VARCHAR(400) NULL,
  notes TEXT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emission_factors_lookup (activity, region, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily, immutable emissions ledger per machine.
CREATE TABLE carbon_records (
  company_id INT UNSIGNED NOT NULL,
  machine_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  kwh DECIMAL(12,3) NOT NULL,
  co2e_kg DECIMAL(12,3) NOT NULL,
  emission_factor_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (company_id, machine_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_data (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  period_month DATE NOT NULL,
  activity VARCHAR(40) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  unit VARCHAR(10) NOT NULL,
  emission_factor_id INT UNSIGNED NOT NULL,
  co2e_kg DECIMAL(12,3) NOT NULL,
  evidence_note VARCHAR(200) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_activity_company (company_id, period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE esg_answers (
  company_id INT UNSIGNED NOT NULL,
  item_key VARCHAR(60) NOT NULL,
  value JSON NOT NULL,
  updated_by INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (company_id, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
