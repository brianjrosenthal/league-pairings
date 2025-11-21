-- Migration: Add previous_games table
-- Date: 2025-11-21

CREATE TABLE IF NOT EXISTS previous_games (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  team_1_id INT NOT NULL,
  team_2_id INT NOT NULL,
  team_1_score INT NOT NULL DEFAULT 0,
  team_2_score INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_previous_games_team1 FOREIGN KEY (team_1_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_previous_games_team2 FOREIGN KEY (team_2_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_previous_games_date ON previous_games(date);
CREATE INDEX idx_previous_games_team1 ON previous_games(team_1_id);
CREATE INDEX idx_previous_games_team2 ON previous_games(team_2_id);
