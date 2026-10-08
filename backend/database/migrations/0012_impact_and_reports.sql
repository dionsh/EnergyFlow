-- Impact verification keeps one row per intervention (a policy or an implemented
-- recommendation), recomputed as the reporting period grows. Reports keep their
-- narrative separately from the frozen snapshot.

ALTER TABLE impact_verifications
  DROP INDEX uq_impact_policy_period,
  ADD COLUMN intervention_key VARCHAR(40) NULL AFTER company_id,
  ADD COLUMN reporting_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER reporting_end,
  ADD UNIQUE KEY uq_impact_intervention (company_id, intervention_key);

ALTER TABLE reports
  ADD COLUMN title VARCHAR(160) NULL AFTER type,
  ADD COLUMN narrative_source ENUM('ai','template','edited') NULL AFTER narrative;
