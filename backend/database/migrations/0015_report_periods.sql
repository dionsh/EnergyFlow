ALTER TABLE reports
  MODIFY type ENUM('monthly_sustainability','daily_sustainability','weekly_sustainability','vsme_b3_annual','impact') NOT NULL;
