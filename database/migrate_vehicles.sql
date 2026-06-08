-- ============================================================================
-- M26 — Vehicle / driver daily-inspection feature
-- Run once in phpMyAdmin (database: m26) to add the required tables.
-- Safe to re-run: uses IF NOT EXISTS.
-- ============================================================================

-- Company vehicles (the fleet)
CREATE TABLE IF NOT EXISTS `vehicles` (
  `vehicle_id`   int(11)      NOT NULL AUTO_INCREMENT,
  `make`         varchar(60)  DEFAULT NULL,          -- e.g. Toyota Hilux
  `fleet_number` varchar(40)  DEFAULT NULL,          -- internal fleet no.
  `registration` varchar(40)  NOT NULL,              -- number plate (unique)
  `status`       enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`vehicle_id`),
  UNIQUE KEY `uq_vehicle_reg` (`registration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- One row = one vehicle inspected on one day (before leaving the office).
-- The 28 checklist items live in `items_json` (see incl/vehicle_checklist.php).
CREATE TABLE IF NOT EXISTS `vehicle_inspections` (
  `inspection_id`   int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id`      int(11) NOT NULL,
  `driver_user_id`  int(11) NOT NULL,                -- who inspected (a field tech)
  `inspection_date` date    NOT NULL,
  `odometer_km`     int(11) DEFAULT NULL,
  `overall_status`  enum('ok','attention','critical') NOT NULL DEFAULT 'ok',
  `repair_request`  text,                            -- free-text repair / notes
  `items_json`      text,                            -- JSON: {section:{item:{status,remark}}}
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`inspection_id`),
  UNIQUE KEY `uq_vehicle_day` (`vehicle_id`, `inspection_date`),  -- one check/vehicle/day
  KEY `ix_vinsp_vehicle` (`vehicle_id`),
  KEY `ix_vinsp_driver`  (`driver_user_id`),
  KEY `ix_vinsp_date`    (`inspection_date`),
  KEY `ix_vinsp_status`  (`overall_status`),
  CONSTRAINT `fk_vinsp_vehicle` FOREIGN KEY (`vehicle_id`)     REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vinsp_driver`  FOREIGN KEY (`driver_user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
