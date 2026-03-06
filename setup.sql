-- ============================================================
--  VIT Smart Attendance Segregator — Database Setup
--  Run this once in phpMyAdmin or MySQL CLI
-- ============================================================

CREATE DATABASE IF NOT EXISTS attendance_segregator
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE attendance_segregator;

-- ------------------------------------------------------------
-- Table: events
-- Stores both single-day and multi-day events
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id                INT             AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(255)    NOT NULL,
    venue             VARCHAR(255)    NOT NULL,
    organising_team   VARCHAR(255)    DEFAULT '',       -- Faculty Coordinator name
    school            VARCHAR(255)    NOT NULL DEFAULT '',
    phone_number      CHAR(10)        NOT NULL DEFAULT '',  -- 10-digit, compulsory
    event_type        VARCHAR(100)    DEFAULT '',
    multiday          TINYINT(1)      NOT NULL DEFAULT 0,
    date              DATE            NOT NULL,          -- first day (or only day)
    end_date          DATE            DEFAULT NULL,      -- last day (multiday only)
    time              VARCHAR(100)    DEFAULT NULL,      -- single-day timing string
    days              JSON            DEFAULT NULL,      -- multiday: [{date,time}, ...]
    created_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Table: segregation_history
-- One row per segregation run (may include multiple events)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS segregation_history (
    id              INT             AUTO_INCREMENT PRIMARY KEY,
    run_date_range  VARCHAR(100)    DEFAULT '',        -- human-readable label
    date_from       DATE            DEFAULT NULL,
    date_to         DATE            DEFAULT NULL,
    segregated_on   DATETIME        NOT NULL,
    events          JSON            DEFAULT NULL,      -- array of {name,venue,organising_team,multiday,days}
    zips            JSON            DEFAULT NULL       -- array of zip filenames
);

-- ------------------------------------------------------------
-- Table: schools
-- School name → array of department codes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schools (
    id              INT             AUTO_INCREMENT PRIMARY KEY,
    school_name     VARCHAR(100)    NOT NULL UNIQUE,
    codes           JSON            NOT NULL           -- e.g. ["BVD","BEC","BML"]
);

-- ------------------------------------------------------------
-- Migration: Run these only if upgrading an existing database
-- (skip if creating fresh)
-- ------------------------------------------------------------
-- ALTER TABLE events ADD COLUMN event_type    VARCHAR(100) DEFAULT ''   AFTER organising_team;
-- ALTER TABLE events ADD COLUMN school        VARCHAR(255) NOT NULL DEFAULT '' AFTER organising_team;
-- ALTER TABLE events ADD COLUMN phone_number  CHAR(10)     NOT NULL DEFAULT '' AFTER school;

-- ------------------------------------------------------------
-- Seed: School → department codes
-- ------------------------------------------------------------
INSERT INTO schools (school_name, codes) VALUES
('SENSE',   '["BVD","BEC","BML"]'),
('SCOPE',   '["BBS","BDS","BCT","BCB","MIC","BAI","MID","BCI","BKT","BCE"]'),
('SCORE',   '["BIT","BCA","BCS","MCA","MAG","BYB","BDE","MIS"]'),
('SAS',     '["MDT","MSP"]'),
('SELECT',  '["BEE","BEL","BEI"]'),
('SMEC',    '["MMT","BMV","BST","BMA","BME","BMM"]'),
('SBST',    '["BBT","MSI"]'),
('SCE',     '["BCL"]'),
('SHINE',   '["BHT"]'),
('SCHEME',  '["BCM"]'),
('VAIAL',   '["BAG"]'),
('SSL',     '["BFN","BBC","BCC","BBP"]'),
('VITBS',   '["BBA"]'),
('HOT',     '["BHA"]'),
('VSMART',  '["BVC","BAM"]'),
('V-SIGN',  '["BID"]'),
('V-SPARC', '["BARC"]');