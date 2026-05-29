-- =============================================================================
-- M26 Site Tracker — database schema + sample data
-- =============================================================================
-- How to use in MAMP:
--   1. Start MAMP, open phpMyAdmin (http://localhost/phpMyAdmin).
--   2. Click Import on the top tab, choose this file, click Go.
--   3. The database `m26_site_tracker` will be created from scratch.
--      If it already exists, IT WILL BE DROPPED. Re-import resets to clean state.
--
-- Conventions:
--   - InnoDB everywhere so foreign keys actually enforce.
--   - utf8mb4 charset so any text/emoji is safe.
--   - Passwords are bcrypt hashes via PHP's password_hash(), verified with
--     password_verify() at login. NO plaintext passwords anywhere.
--   - Sites have NO status column — sites are long-lived and have a history
--     of visits. Visit status lives on the visits table.
-- =============================================================================

DROP DATABASE IF EXISTS m26_site_tracker;
CREATE DATABASE m26_site_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE m26_site_tracker;


-- ---------------------------------------------------------------------------
-- users — admin (office) and employee (field tech) accounts
-- (Same shape as ShutterDreams' employees table, with a role column.)
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  user_id        INT AUTO_INCREMENT PRIMARY KEY,
  first_name     VARCHAR(60)  NOT NULL,
  last_name      VARCHAR(60)  NOT NULL,
  username       VARCHAR(40)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,         -- bcrypt via password_hash()
  role           ENUM('admin','employee') NOT NULL,
  phone          VARCHAR(30),
  email          VARCHAR(120),
  team           VARCHAR(40),                   -- e.g. "Field Team A"
  supervisor_id  INT,                           -- another user_id
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  status         ENUM('active','on_leave','inactive') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_supervisor
    FOREIGN KEY (supervisor_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------------
-- sites — the towers M26 maintains. Long-lived. No status here.
-- (Like ShutterDreams' customers table — referenced by many things.)
-- ---------------------------------------------------------------------------
CREATE TABLE sites (
  site_id     INT AUTO_INCREMENT PRIMARY KEY,
  site_code   VARCHAR(20) NOT NULL UNIQUE,     -- e.g. "WN012", "BS003"
  site_name   VARCHAR(120) NOT NULL,           -- e.g. "Asoc"
  location    VARCHAR(160),                    -- e.g. "Mbabane, Eswatini"
  latitude    DECIMAL(9,6),
  longitude   DECIMAL(9,6),
  notes       TEXT,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------------
-- visits — one maintenance visit at a site. Admin creates, tech executes.
-- (This is your ShutterDreams 'orders' table — same shape.)
-- ---------------------------------------------------------------------------
CREATE TABLE visits (
  visit_id              INT AUTO_INCREMENT PRIMARY KEY,
  site_id               INT NOT NULL,
  assigned_to_user_id   INT NOT NULL,                                    -- the tech
  created_by_user_id    INT NOT NULL,                                    -- the admin
  visit_type            VARCHAR(120) NOT NULL,                           -- "Generator service"
  description           TEXT,
  scheduled_date        DATE,
  status                ENUM('assigned','in_progress','completed') NOT NULL DEFAULT 'assigned',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at          DATETIME,
  CONSTRAINT fk_visit_site         FOREIGN KEY (site_id)             REFERENCES sites(site_id),
  CONSTRAINT fk_visit_assigned_to  FOREIGN KEY (assigned_to_user_id) REFERENCES users(user_id),
  CONSTRAINT fk_visit_created_by   FOREIGN KEY (created_by_user_id)  REFERENCES users(user_id),
  INDEX ix_visits_site   (site_id),
  INDEX ix_visits_tech   (assigned_to_user_id),
  INDEX ix_visits_status (status)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------------
-- visit_items — checklist items inside a visit. 1+ per visit.
-- (Child rows of a visit — exactly like ShutterDreams' order_details.)
-- ---------------------------------------------------------------------------
CREATE TABLE visit_items (
  item_id            INT AUTO_INCREMENT PRIMARY KEY,
  visit_id           INT NOT NULL,
  item_description   VARCHAR(255) NOT NULL,
  sort_order         INT NOT NULL DEFAULT 1,
  is_done            TINYINT(1) NOT NULL DEFAULT 0,
  completed_at       DATETIME,
  CONSTRAINT fk_item_visit FOREIGN KEY (visit_id) REFERENCES visits(visit_id) ON DELETE CASCADE,
  INDEX ix_items_visit (visit_id)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------------
-- visit_photos — before/after/other photos. Attach to visit OR a specific item.
-- (The one genuinely new pattern vs ShutterDreams: file uploads.)
-- ---------------------------------------------------------------------------
CREATE TABLE visit_photos (
  photo_id              INT AUTO_INCREMENT PRIMARY KEY,
  visit_id              INT NOT NULL,
  item_id               INT,                                          -- nullable: visit-level photo
  photo_filename        VARCHAR(255) NOT NULL,                        -- file in uploads/ folder
  photo_type            ENUM('before','after','other') NOT NULL,
  uploaded_by_user_id   INT NOT NULL,
  uploaded_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_photo_visit    FOREIGN KEY (visit_id)            REFERENCES visits(visit_id)      ON DELETE CASCADE,
  CONSTRAINT fk_photo_item     FOREIGN KEY (item_id)             REFERENCES visit_items(item_id)  ON DELETE CASCADE,
  CONSTRAINT fk_photo_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(user_id),
  INDEX ix_photos_visit (visit_id),
  INDEX ix_photos_item  (item_id)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------------
-- site_documents — contracts, drawings, etc. attached to a site (admin only).
-- ---------------------------------------------------------------------------
CREATE TABLE site_documents (
  document_id          INT AUTO_INCREMENT PRIMARY KEY,
  site_id              INT NOT NULL,
  doc_name             VARCHAR(160) NOT NULL,                         -- "Site drawing"
  doc_filename         VARCHAR(255) NOT NULL,                         -- file in uploads/ folder
  uploaded_by_user_id  INT NOT NULL,
  uploaded_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_site     FOREIGN KEY (site_id)             REFERENCES sites(site_id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(user_id),
  INDEX ix_docs_site (site_id)
) ENGINE=InnoDB;


-- =============================================================================
-- SAMPLE DATA
-- =============================================================================
-- Login credentials for testing:
--   admin    : username = mduduzi    password = password123
--   employee : username = thokozo    password = thokozo123
--   employee : username = nana       password = nana123
--   employee : username = mpendulo   password = mpendulo123
-- (Bcrypt hashes below were generated with PHP-compatible bcrypt rounds=10.)
-- =============================================================================

-- users: 1 admin, 1 supervisor, 3 field techs
INSERT INTO users (first_name, last_name, username, password_hash, role, phone, email, team, supervisor_id) VALUES
('Mduduzi',  'Dlamini',  'mduduzi',  '$2b$10$hOLbEJbxztGOMFokB/KGJen4a./8zREptaxX3xA5ck1CgqZRAisle', 'admin',    '+268 7602 6210', 'mduduzi@m26technologies.com', NULL,            NULL),
('Mmeli',    'Dlamini',  'mmeli',    '$2b$10$hOLbEJbxztGOMFokB/KGJen4a./8zREptaxX3xA5ck1CgqZRAisle', 'admin',    NULL,             NULL,                          'Field Team A',  NULL),
('Thokozo',  'Twala',    'thokozo',  '$2b$10$KrbhC3Fz39ggOPi.4kFOceYOCAtNtwsMslGXgPU2.kFA05bxQB6h.', 'employee', NULL,             NULL,                          'Field Team A',  2),
('Nana',     'Maziya',   'nana',     '$2b$10$JAYL7OEeUJARqCVyjFxje.Woe7ACsyQ2ghqtLAyVHS/Gy/N/7ft.W', 'employee', NULL,             NULL,                          'Field Team B',  2),
('Mpendulo', 'Shongwe',  'mpendulo', '$2b$10$7u15r9HOCIecwL9zrywfwewThXcHifRxQ3jkZRehu8ZC8GNmCNx2q', 'employee', NULL,             NULL,                          'Field Team A',  2);

-- sites: real codes from the M26 owned-towers list (Eswatini + Equatorial Guinea)
INSERT INTO sites (site_code, site_name, location, latitude, longitude, notes) VALUES
('WN012', 'Asoc',         'Mbabane, Eswatini',           -26.314000,  31.139000, 'Generator-powered, fenced. Quarterly service.'),
('BS003', 'Malabo 2',     'Malabo, Equatorial Guinea',     3.363900,   8.664900, 'Rooftop installation. Access via building manager.'),
('LT015', 'Siguifo',      'Bata, Equatorial Guinea',       1.418370,   9.616590, '80m tower. Remote site, plan a full day.'),
('KN006', 'ETECVEN',      'Ebebiyin, Equatorial Guinea',   1.896290,  11.253610, 'Joint with ETECVEN. Coordinate access in advance.'),
('REP002','Bicukbinihi',  'Equatorial Guinea',             1.638260,   9.680880, '100m tower.'),
('WN010', 'Oyala',        'Oyala, Equatorial Guinea',      1.585900,  10.824800, 'Greenfield site, fully owned.');


-- VISIT 1: completed historical visit at WN012 — battery check, both items done
INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, status, created_at, completed_at) VALUES
(1, 4, 1, 'Battery check', 'Routine battery health check, replace any failing cells.', '2026-05-14', 'completed', '2026-05-13 16:20:00', '2026-05-14 11:30:00');
SET @v1 = LAST_INSERT_ID();

INSERT INTO visit_items (visit_id, item_description, sort_order, is_done, completed_at) VALUES
(@v1, 'Test battery voltage on all cells', 1, 1, '2026-05-14 09:45:00'),
(@v1, 'Replace any failing cells',         2, 1, '2026-05-14 11:20:00');


-- VISIT 2: completed historical visit at WN012 — fence repair
INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, status, created_at, completed_at) VALUES
(1, 3, 1, 'Fence repair', 'Section of palisade fence damaged on east side, needs welding.', '2026-05-02', 'completed', '2026-05-01 09:00:00', '2026-05-02 15:10:00');
SET @v2 = LAST_INSERT_ID();

INSERT INTO visit_items (visit_id, item_description, sort_order, is_done, completed_at) VALUES
(@v2, 'Inspect damage and confirm scope', 1, 1, '2026-05-02 10:30:00'),
(@v2, 'Weld and reseat fence panels',     2, 1, '2026-05-02 14:50:00');


-- VISIT 3: IN PROGRESS today at WN012 — generator service (matches the demo screens)
INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, status, created_at, completed_at) VALUES
(1, 3, 1, 'Generator service',
   'Generator running rough, check oil, fuel filter, run test.',
   '2026-05-28', 'in_progress', '2026-05-27 17:45:00', NULL);
SET @v3 = LAST_INSERT_ID();

INSERT INTO visit_items (visit_id, item_description, sort_order, is_done, completed_at) VALUES
(@v3, 'Inspect generator engine',  1, 1, '2026-05-28 09:42:00'),  -- done
(@v3, 'Replace fuel filter',       2, 0, NULL),                   -- in progress
(@v3, 'Test run for 30 min',       3, 0, NULL);                   -- pending


-- VISIT 4: assigned for tomorrow at KN006 — battery check
INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, status, created_at) VALUES
(4, 3, 1, 'Battery check', 'Routine quarterly check.', '2026-05-29', 'assigned', '2026-05-28 10:00:00');
SET @v4 = LAST_INSERT_ID();

INSERT INTO visit_items (visit_id, item_description, sort_order, is_done) VALUES
(@v4, 'Voltage check on all cells', 1, 0),
(@v4, 'Replace failing cells',      2, 0);


-- VISIT 5: assigned for today at BS003 — battery swap (Nana, in progress)
INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, status, created_at) VALUES
(2, 4, 1, 'Battery swap', 'Swap out aged battery bank for new units already on site.', '2026-05-28', 'in_progress', '2026-05-27 14:00:00');
SET @v5 = LAST_INSERT_ID();

INSERT INTO visit_items (visit_id, item_description, sort_order, is_done) VALUES
(@v5, 'Disconnect and remove old battery bank', 1, 0),
(@v5, 'Install and connect new batteries',      2, 0),
(@v5, 'System test and load check',             3, 0);


-- VISIT 6: assigned for today at LT015 — routine inspection (Mpendulo)
INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, status, created_at) VALUES
(3, 5, 1, 'Routine inspection', 'Quarterly site walk-through, photograph anything unusual.', '2026-05-29', 'assigned', '2026-05-28 11:30:00');
SET @v6 = LAST_INSERT_ID();

INSERT INTO visit_items (visit_id, item_description, sort_order, is_done) VALUES
(@v6, 'General site walk-through and photo log', 1, 0);


-- Sample site documents (filenames are placeholders; you upload real files
-- via the app to populate uploads/ — these rows are just so the UI shows
-- something in the documents section for WN012 from day one)
INSERT INTO site_documents (site_id, doc_name, doc_filename, uploaded_by_user_id) VALUES
(1, 'Site drawing', 'sample_site_drawing.pdf', 1),
(1, 'Contract',     'sample_contract.pdf',     1),
(1, 'Lease',        'sample_lease.pdf',        1);


-- =============================================================================
-- Quick sanity-check queries (uncomment in phpMyAdmin if you want to test):
-- =============================================================================
-- SELECT user_id, username, role FROM users;
-- SELECT site_id, site_code, site_name, location FROM sites;
-- SELECT v.visit_id, s.site_code, v.visit_type, u.username AS tech, v.status
--   FROM visits v
--   JOIN sites s ON s.site_id = v.site_id
--   JOIN users u ON u.user_id = v.assigned_to_user_id
--   ORDER BY v.scheduled_date DESC;
