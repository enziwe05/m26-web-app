ALTER TABLE users
  ADD COLUMN employment_type ENUM('monthly','hourly') NOT NULL DEFAULT 'monthly' AFTER team,
  ADD COLUMN monthly_salary DECIMAL(10,2) DEFAULT NULL AFTER employment_type;

CREATE TABLE shift_assignments (
  assignment_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  shift_date DATE NOT NULL,
  shift_type ENUM('day','night') NOT NULL,
  site_id INT DEFAULT NULL,
  notes VARCHAR(200) DEFAULT NULL,
  created_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_date (user_id, shift_date),
  KEY ix_shift_date (shift_date),
  CONSTRAINT fk_sa_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_site FOREIGN KEY (site_id) REFERENCES sites(site_id) ON DELETE SET NULL,
  CONSTRAINT fk_sa_creator FOREIGN KEY (created_by_user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE time_entries (
  entry_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  assignment_id INT DEFAULT NULL,
  shift_type ENUM('day','night') NOT NULL DEFAULT 'day',
  clock_in_at DATETIME NOT NULL,
  clock_out_at DATETIME DEFAULT NULL,
  clock_in_lat VARCHAR(30) DEFAULT NULL,
  clock_in_lon VARCHAR(30) DEFAULT NULL,
  clock_in_accuracy VARCHAR(20) DEFAULT NULL,
  clock_out_lat VARCHAR(30) DEFAULT NULL,
  clock_out_lon VARCHAR(30) DEFAULT NULL,
  clock_out_accuracy VARCHAR(20) DEFAULT NULL,
  location_flag VARCHAR(40) DEFAULT NULL,
  worked_minutes INT DEFAULT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_te_user (user_id),
  KEY ix_te_in (clock_in_at),
  KEY ix_te_status (status),
  CONSTRAINT fk_te_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_te_assignment FOREIGN KEY (assignment_id) REFERENCES shift_assignments(assignment_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE maintenance_forms
  ADD COLUMN submit_lat VARCHAR(30) DEFAULT NULL,
  ADD COLUMN submit_lon VARCHAR(30) DEFAULT NULL,
  ADD COLUMN submit_accuracy VARCHAR(20) DEFAULT NULL,
  ADD COLUMN submit_captured_at DATETIME DEFAULT NULL;

CREATE TABLE public_holidays (
  holiday_id INT AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO public_holidays (holiday_date, name) VALUES
  ('2026-01-01','New Year''s Day'),
  ('2026-04-03','Good Friday'),
  ('2026-04-06','Easter Monday'),
  ('2026-04-19','King Mswati III Birthday'),
  ('2026-04-25','National Flag Day'),
  ('2026-05-01','Workers Day'),
  ('2026-05-14','Ascension Day'),
  ('2026-07-22','Birthday of Late King Sobhuza II'),
  ('2026-09-06','Somhlolo (Independence) Day'),
  ('2026-12-25','Christmas Day'),
  ('2026-12-26','Boxing Day');

CREATE TABLE payroll_settings (
  id TINYINT PRIMARY KEY,
  day_start TIME NOT NULL DEFAULT '08:00:00',
  day_end TIME NOT NULL DEFAULT '17:00:00',
  day_normal_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00,
  night_start TIME NOT NULL DEFAULT '20:00:00',
  night_end TIME NOT NULL DEFAULT '05:00:00',
  night_normal_hours DECIMAL(4,2) NOT NULL DEFAULT 9.00,
  monthly_hours_divisor DECIMAL(6,2) NOT NULL DEFAULT 208.00,
  overtime_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.50,
  sunday_multiplier DECIMAL(4,2) NOT NULL DEFAULT 2.00,
  holiday_multiplier DECIMAL(4,2) NOT NULL DEFAULT 2.00,
  night_allowance_enabled TINYINT(1) NOT NULL DEFAULT 0,
  night_allowance_type ENUM('percent','flat') NOT NULL DEFAULT 'percent',
  night_allowance_value DECIMAL(8,2) NOT NULL DEFAULT 15.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
INSERT INTO payroll_settings (id) VALUES (1);

CREATE TABLE payroll_runs (
  run_id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(60) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
  created_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finalized_at DATETIME DEFAULT NULL,
  CONSTRAINT fk_pr_creator FOREIGN KEY (created_by_user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE payroll_lines (
  line_id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  user_id INT NOT NULL,
  base_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
  hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  normal_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
  overtime_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
  overtime_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  sunday_holiday_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
  sunday_holiday_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  night_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
  night_allowance DECIMAL(10,2) NOT NULL DEFAULT 0,
  gross_pay DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT NULL,
  CONSTRAINT fk_pl_run FOREIGN KEY (run_id) REFERENCES payroll_runs(run_id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
