-- Camera scan: utility bills checked against the tariff and the meters, utility
-- meter readings, machine nameplates, and Scope 1 fuel from receipts.

-- Scope 1 combustion factors, verified 8 Oct 2026 against the official workbook
-- "GHG Conversion Factors 2025, flat file" (UK DESNZ, published 10 June 2025),
-- sheet Fuels, UOM litres, GHG/Unit "kg CO2e". The 100% mineral variants are used:
-- the UK "average biofuel blend" reflects UK blending, which can't be assumed for
-- Kosovo. energy_kwh_per_unit = (kg CO2e/litre) ÷ (kg CO2e/kWh Net CV), both from
-- the same workbook, for the VSME B3 energy datapoint.
ALTER TABLE emission_factors
  ADD COLUMN energy_kwh_per_unit DECIMAL(10,4) NULL AFTER unit;

INSERT INTO emission_factors
  (company_id, scope, activity, region, value, unit, energy_kwh_per_unit, reference_year, valid_from, valid_to,
   methodology, source_name, source_url, notes, is_default)
VALUES
  (NULL, 'scope1', 'diesel', NULL, 2.661550, 'kgCO2e/l', 9.9282, 2025, '2025-01-01', NULL, 'direct_combustion',
   'UK DESNZ GHG Conversion Factors 2025 - Diesel (100% mineral diesel)',
   'https://www.gov.uk/government/publications/greenhouse-gas-reporting-conversion-factors-2025',
   'Row 1_101_1012_8_1: 2.66155 kg CO2e per litre. Energy 9.9282 kWh/l = 2.66155 / 0.26808 (kWh Net CV row 1_101_1012_7_1).', 1),
  (NULL, 'scope1', 'petrol', NULL, 2.339840, 'kgCO2e/l', 9.2008, 2025, '2025-01-01', NULL, 'direct_combustion',
   'UK DESNZ GHG Conversion Factors 2025 - Petrol (100% mineral petrol)',
   'https://www.gov.uk/government/publications/greenhouse-gas-reporting-conversion-factors-2025',
   'Row 1_101_1018_8_1: 2.33984 kg CO2e per litre. Energy 9.2008 kWh/l = 2.33984 / 0.25431 (row 1_101_1018_7_1).', 1),
  (NULL, 'scope1', 'gas_oil', NULL, 2.755410, 'kgCO2e/l', 10.0975, 2025, '2025-01-01', NULL, 'direct_combustion',
   'UK DESNZ GHG Conversion Factors 2025 - Gas oil',
   'https://www.gov.uk/government/publications/greenhouse-gas-reporting-conversion-factors-2025',
   'Row 1_101_1014_8_1: 2.75541 kg CO2e per litre (off-road diesel for generators and machinery). Energy 10.0975 kWh/l = 2.75541 / 0.27288 (row 1_101_1014_7_1).', 1),
  (NULL, 'scope1', 'lpg', NULL, 1.557130, 'kgCO2e/l', 6.7607, 2025, '2025-01-01', NULL, 'direct_combustion',
   'UK DESNZ GHG Conversion Factors 2025 - LPG',
   'https://www.gov.uk/government/publications/greenhouse-gas-reporting-conversion-factors-2025',
   'Row 1_100_1003_8_1: 1.55713 kg CO2e per litre. Energy 6.7607 kWh/l = 1.55713 / 0.23032 (row 1_100_1003_7_1).', 1),
  (NULL, 'scope1', 'heating_oil', NULL, 2.540160, 'kgCO2e/l', 9.7792, 2025, '2025-01-01', NULL, 'direct_combustion',
   'UK DESNZ GHG Conversion Factors 2025 - Burning oil',
   'https://www.gov.uk/government/publications/greenhouse-gas-reporting-conversion-factors-2025',
   'Row 1_101_1010_8_1: 2.54016 kg CO2e per litre (kerosene / heating oil). Energy 9.7792 kWh/l = 2.54016 / 0.25975 (row 1_101_1010_7_1).', 1);

-- Fuel lines keep their energy too (VSME B3 "fuels burned on site", MWh).
ALTER TABLE activity_data
  ADD COLUMN energy_kwh DECIMAL(12,1) NULL AFTER co2e_kg,
  ADD COLUMN source ENUM('manual','scan') NOT NULL DEFAULT 'manual' AFTER evidence_note;

-- A bill as printed, and what EnergyFlow's checks said about it.
ALTER TABLE utility_bills
  ADD COLUMN supplier VARCHAR(120) NULL AFTER period_month,
  ADD COLUMN issue_date DATE NULL AFTER supplier,
  ADD COLUMN period_start DATE NULL AFTER issue_date,
  ADD COLUMN period_end DATE NULL AFTER period_start,
  ADD COLUMN net_eur DECIMAL(10,2) NULL AFTER amount_eur,
  ADD COLUMN vat_eur DECIMAL(10,2) NULL AFTER net_eur,
  ADD COLUMN verdict ENUM('consistent','check','likely_fake') NULL AFTER vat_eur,
  ADD COLUMN checks JSON NULL AFTER verdict,
  ADD COLUMN source ENUM('manual','scan') NOT NULL DEFAULT 'manual' AFTER checks,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER source,
  ADD COLUMN created_at DATETIME NULL AFTER created_by;

-- Readings of the supplier's own meter, to compare with what EnergyFlow measured.
CREATE TABLE meter_readings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL,
  meter_serial VARCHAR(40) NULL,
  register_code VARCHAR(10) NOT NULL DEFAULT 'total',
  reading_kwh DECIMAL(14,2) NOT NULL,
  source ENUM('manual','scan') NOT NULL DEFAULT 'manual',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_meter_readings_company (company_id, register_code, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The rest of a machine's nameplate (voltage, current, cos φ, efficiency class…).
ALTER TABLE machines
  ADD COLUMN nameplate JSON NULL AFTER rated_power_kw;
