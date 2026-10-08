-- Detection and actions: stable episode keys for waste events (re-detection is an
-- upsert, never a duplicate), dismissal reasons, and command context.

ALTER TABLE waste_events
  ADD COLUMN dedupe_key VARCHAR(120) NULL AFTER machine_id,
  ADD COLUMN dismissed_reason VARCHAR(200) NULL AFTER action_status,
  ADD COLUMN updated_at DATETIME NULL AFTER command_id,
  ADD UNIQUE KEY uq_waste_dedupe (company_id, dedupe_key);

ALTER TABLE alerts
  ADD KEY idx_alerts_dedupe (company_id, dedupe_key);

-- Why a command was sent (shown in the command log) and which waste episode it ended.
ALTER TABLE device_commands
  ADD COLUMN reason VARCHAR(200) NULL AFTER requested_by,
  ADD COLUMN waste_event_id INT UNSIGNED NULL AFTER policy_id,
  ADD KEY idx_commands_machine (machine_id, requested_at);
