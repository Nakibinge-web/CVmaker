-- CV Maker Database Schema
-- Creates the cv_records table for storing CV data.
--
-- Requirements: 3.3, 3.4, 3.5
--
-- Notes:
--   - cv_data uses the JSON type (requires MySQL 5.7.8+).
--     If your MySQL version is older, change JSON to LONGTEXT.
--   - The PRIMARY KEY on `id` implicitly creates a unique B-tree index,
--     satisfying the "index on id" requirement.
--   - created_at defaults to the current timestamp on INSERT.
--   - updated_at defaults to the current timestamp and is automatically
--     refreshed on every UPDATE via ON UPDATE CURRENT_TIMESTAMP.

CREATE DATABASE IF NOT EXISTS cv_maker
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE cv_maker;

CREATE TABLE IF NOT EXISTS cv_records (
    -- Primary key — auto-increment integer; the PK constraint creates an
    -- implicit index on this column (satisfies "Add index on id").
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Stores the full CV payload as a JSON document.
    -- Use LONGTEXT instead of JSON for MySQL < 5.7.8.
    cv_data     JSON            NOT NULL,

    -- Audit timestamps
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
