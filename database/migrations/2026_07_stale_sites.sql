-- 2026-07 Stale-site flagging migration
-- Adds the "not visited in N days" threshold used by stale_sites.php and the
-- monthly alert. Default 120 days = the 4-month maintenance cycle.
-- Apply: mysql -u root -proot m26 < database/migrations/2026_07_stale_sites.sql

ALTER TABLE payroll_settings
  ADD COLUMN stale_days INT NOT NULL DEFAULT 120;
