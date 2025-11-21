-- Migration: Add team_availability table
-- Date: 2025-11-21

CREATE TABLE IF NOT EXISTS team_availability (
  team_id INT NOT NULL,
  timeslot_id INT NOT NULL,
  PRIMARY KEY (team_id, timeslot_id),
  CONSTRAINT fk_team_availability_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_availability_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_team_availability_team ON team_availability(team_id);
CREATE INDEX idx_team_availability_timeslot ON team_availability(timeslot_id);
