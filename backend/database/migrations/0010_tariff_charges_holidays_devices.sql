-- Kosovo regulated tariffs (ERO Decision V_2703_2025) need engaged-power and
-- reactive-energy charges. Also: company tariff link, reactive energy per bucket,
-- device key versioning for signed telemetry, and Kosovo public holidays 2026.

ALTER TABLE tariff_plans
  ADD COLUMN category VARCHAR(80) NULL AFTER voltage_level,
  ADD COLUMN demand_charge_eur_kw_month DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER fixed_monthly_eur,
  ADD COLUMN reactive_charge_eur_kvarh DECIMAL(8,6) NOT NULL DEFAULT 0 AFTER demand_charge_eur_kw_month,
  ADD COLUMN reactive_pf_threshold DECIMAL(4,3) NULL AFTER reactive_charge_eur_kvarh;

ALTER TABLE companies
  ADD COLUMN tariff_plan_id INT UNSIGNED NULL AFTER emission_factor_id;

ALTER TABLE readings_15m
  ADD COLUMN kvarh DECIMAL(10,4) NULL AFTER kwh;

-- The device secret is never stored: it is derived from APP_KEY + serial + key_version,
-- so rotating a device key only means bumping key_version.
ALTER TABLE devices
  ADD COLUMN key_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER key_hash;

-- Template tariff plans (company_id NULL). Source: ERO Decision V_2703_2025 of 16 April 2025,
-- retail tariffs for Universal Service customers, collected from 1 May 2025 until the next review.
-- ZRRE approved the 2026 tariffs without a price change. Values are excluding VAT.
INSERT INTO tariff_plans
  (company_id, name, supplier, mode, voltage_level, category, fixed_monthly_eur,
   demand_charge_eur_kw_month, reactive_charge_eur_kvarh, reactive_pf_threshold,
   vat_rate, valid_from, valid_to, source_note, is_active)
VALUES
  (NULL, 'KESCO universal service - 0.4 kV Category I (reactive-energy customers)', 'KESCO',
   'regulated_tou_blocks', '0.4kV', 'commercial_cat_1', 3.34, 3.87, 0.008700, 0.950,
   0.0800, '2025-05-01', NULL,
   'ERO Decision V_2703_2025 (16 Apr 2025), tariff group 3. Engaged power billed per kW. Reactive energy charged above cos phi 0.95. VAT 8 percent on electricity - verify.',
   1),
  (NULL, 'KESCO universal service - 0.4 kV Category II', 'KESCO',
   'regulated_tou_blocks', '0.4kV', 'commercial_cat_2', 3.87, 0, 0, NULL,
   0.0800, '2025-05-01', NULL,
   'ERO Decision V_2703_2025 (16 Apr 2025), tariff group 4 (two-rate meter). VAT 8 percent on electricity - verify.',
   1);

-- Seasonal high-tariff windows (same decision): 07:00-22:00 from 1 Oct to 31 Mar,
-- 08:00-23:00 from 1 Apr to 30 Sep. Every other hour is the low tariff.
INSERT INTO tariff_windows (tariff_plan_id, season_start_mmdd, season_end_mmdd, period, start_time, end_time)
SELECT id, '10-01', '03-31', 'high', '07:00:00', '22:00:00' FROM tariff_plans WHERE company_id IS NULL AND supplier = 'KESCO';

INSERT INTO tariff_windows (tariff_plan_id, season_start_mmdd, season_end_mmdd, period, start_time, end_time)
SELECT id, '04-01', '09-30', 'high', '08:00:00', '23:00:00' FROM tariff_plans WHERE company_id IS NULL AND supplier = 'KESCO';

INSERT INTO tariff_rates (tariff_plan_id, period, block_from_kwh, block_to_kwh, rate_eur_kwh)
SELECT id, 'high', 0, NULL, 0.08700 FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_1';
INSERT INTO tariff_rates (tariff_plan_id, period, block_from_kwh, block_to_kwh, rate_eur_kwh)
SELECT id, 'low', 0, NULL, 0.06460 FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_1';
INSERT INTO tariff_rates (tariff_plan_id, period, block_from_kwh, block_to_kwh, rate_eur_kwh)
SELECT id, 'high', 0, NULL, 0.13920 FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_2';
INSERT INTO tariff_rates (tariff_plan_id, period, block_from_kwh, block_to_kwh, rate_eur_kwh)
SELECT id, 'low', 0, NULL, 0.06900 FROM tariff_plans WHERE company_id IS NULL AND category = 'commercial_cat_2';

-- Kosovo public holidays 2026 (national, company_id NULL).
INSERT INTO calendar_days (company_id, country, date, kind, label) VALUES
  (NULL, 'XK', '2026-01-01', 'public_holiday', 'New Year'),
  (NULL, 'XK', '2026-01-02', 'public_holiday', 'New Year (second day)'),
  (NULL, 'XK', '2026-01-07', 'public_holiday', 'Orthodox Christmas'),
  (NULL, 'XK', '2026-02-17', 'public_holiday', 'Independence Day'),
  (NULL, 'XK', '2026-03-20', 'public_holiday', 'Eid al-Fitr'),
  (NULL, 'XK', '2026-04-06', 'public_holiday', 'Catholic Easter Monday'),
  (NULL, 'XK', '2026-04-09', 'public_holiday', 'Constitution Day'),
  (NULL, 'XK', '2026-04-13', 'public_holiday', 'Orthodox Easter Monday'),
  (NULL, 'XK', '2026-05-01', 'public_holiday', 'Labour Day'),
  (NULL, 'XK', '2026-05-11', 'public_holiday', 'Europe Day (moved from Saturday 9 May)'),
  (NULL, 'XK', '2026-05-27', 'public_holiday', 'Eid al-Adha'),
  (NULL, 'XK', '2026-12-25', 'public_holiday', 'Catholic Christmas');
