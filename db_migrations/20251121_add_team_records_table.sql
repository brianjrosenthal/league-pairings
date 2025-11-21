-- Migration: Add team_records table
-- Date: 2025-11-21

CREATE TABLE IF NOT EXISTS team_records (
  team_id INT NOT NULL PRIMARY KEY,
  games_won INT NOT NULL DEFAULT 0,
  games_lost INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_team_records_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB;
