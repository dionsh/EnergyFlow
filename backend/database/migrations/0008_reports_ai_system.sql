-- Reports, the AI assistant, the demo simulator state and the job runner.

CREATE TABLE reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  type ENUM('monthly_sustainability','vsme_b3_annual','impact') NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('draft','final') NOT NULL DEFAULT 'draft',
  language VARCHAR(5) NOT NULL,
  snapshot JSON NOT NULL,
  narrative JSON NULL,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  finalized_by INT UNSIGNED NULL,
  finalized_at DATETIME NULL,
  KEY idx_reports_company (company_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_conversations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  KEY idx_ai_conversations_user (company_id, user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content TEXT NOT NULL,
  language VARCHAR(5) NULL,
  source ENUM('data','ai','refusal','fallback') NULL,
  intent VARCHAR(40) NULL,
  sources JSON NULL,
  model VARCHAR(60) NULL,
  tokens_in INT UNSIGNED NULL,
  tokens_out INT UNSIGNED NULL,
  latency_ms INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_ai_messages_conversation (conversation_id),
  CONSTRAINT fk_ai_messages_conversation FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demo company only: virtual now = real now + clock_offset_s.
CREATE TABLE sim_state (
  company_id INT UNSIGNED NOT NULL PRIMARY KEY,
  seed INT UNSIGNED NOT NULL,
  clock_offset_s BIGINT NOT NULL DEFAULT 0,
  speed DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  last_generated_at DATETIME NOT NULL,
  story_anchor DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sim_scenarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  scenario VARCHAR(40) NOT NULL,
  machine_id INT UNSIGNED NULL,
  params JSON NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  KEY idx_sim_scenarios_company (company_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_runs (
  job_key VARCHAR(40) NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  watermark DATETIME NULL,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_status ENUM('ok','error','running') NULL,
  last_error TEXT NULL,
  duration_ms INT UNSIGNED NULL,
  PRIMARY KEY (job_key, company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
