-- Migration: Add teams table
-- Date: 2025-11-21

CREATE TABLE IF NOT EXISTS teams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  division_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_teams_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_teams_division_id ON teams(division_id);
CREATE INDEX idx_teams_name ON teams(name);
