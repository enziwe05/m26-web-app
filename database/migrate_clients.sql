-- ============================================================================
-- M26 — Client portal feature (read-only operator logins, e.g. MTN)
-- Run once in phpMyAdmin (database: m26).
--
-- Site ownership is matched from the free-text `sites.notes` column using each
-- client's `match_keyword` (e.g. 'MTN' matches 'MTN Sharing', 'MTN sharing',
-- 'MTN Site Sharing', ...).  Case-insensitive.
-- ============================================================================

-- Operators / clients that can be given a read-only portal login
CREATE TABLE IF NOT EXISTS `clients` (
  `client_id`     int(11)      NOT NULL AUTO_INCREMENT,
  `name`          varchar(120) NOT NULL,            -- display name, e.g. "MTN Eswatini"
  `match_keyword` varchar(120) NOT NULL,            -- text to match in sites.notes, e.g. "MTN"
  `status`        enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Add the 'client' role and link client users to their client.
-- (Run once — MySQL has no "ADD COLUMN IF NOT EXISTS"; safe to ignore a
--  "duplicate column" error if you accidentally re-run.)
ALTER TABLE `users`
  MODIFY `role` enum('admin','supervisor','employee','client') NOT NULL;

ALTER TABLE `users`
  ADD COLUMN `client_id` int(11) DEFAULT NULL AFTER `supervisor_id`,
  ADD KEY `fk_users_client` (`client_id`),
  ADD CONSTRAINT `fk_users_client` FOREIGN KEY (`client_id`)
      REFERENCES `clients` (`client_id`) ON DELETE SET NULL;

-- Optional starter clients (edit/remove as needed):
-- INSERT INTO `clients` (name, match_keyword) VALUES
--   ('MTN Eswatini', 'MTN'),
--   ('Eswatini Mobile', 'Eswatini Mobile'),
--   ('EPTC', 'EPTC');
