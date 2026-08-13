-- 2026-07 Refinements migration
-- Apply: mysql -u root -proot m26 < database/migrations/2026_07_refinements.sql

ALTER TABLE users
  ADD COLUMN shift_type ENUM('day','night') DEFAULT NULL AFTER monthly_salary;

ALTER TABLE maintenance_forms
  ADD COLUMN submit_distance_m INT DEFAULT NULL,
  ADD COLUMN submit_location_status ENUM('on_site','away','unknown') DEFAULT NULL;

ALTER TABLE payroll_settings
  ADD COLUMN onsite_radius_m INT NOT NULL DEFAULT 50,
  ADD COLUMN min_photos INT NOT NULL DEFAULT 1,
  ADD COLUMN autoclose_grace_mins INT NOT NULL DEFAULT 120;
