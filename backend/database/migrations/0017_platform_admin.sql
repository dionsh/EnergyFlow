-- EnergyFlow's own staff: the platform admin panel (/admin). Granted only from the
-- command line (php bin/platform-admin.php grant <email>), never through the API.
ALTER TABLE users
  ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- What platform admins did, kept apart from each company's own audit_log (tenants
-- never see it) and outliving the deleted user or company it describes.
CREATE TABLE admin_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT UNSIGNED NULL,
  admin_email VARCHAR(190) NOT NULL,
  action VARCHAR(60) NOT NULL,
  target_type VARCHAR(40) NOT NULL,
  target_id INT UNSIGNED NULL,
  data JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin_actions_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
