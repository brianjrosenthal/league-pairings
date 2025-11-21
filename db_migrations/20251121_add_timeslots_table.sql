-- Migration: Add timeslots table
-- Date: 2025-11-21

CREATE TABLE IF NOT EXISTS timeslots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  modifier VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_date_modifier (date, modifier)
) ENGINE=InnoDB;

CREATE INDEX idx_timeslots_date ON timeslots(date);
