-- Add preferred_location_id to teams table

ALTER TABLE teams ADD COLUMN preferred_location_id INT NULL;

ALTER TABLE teams ADD CONSTRAINT fk_teams_preferred_location 
  FOREIGN KEY (preferred_location_id) REFERENCES locations(id) ON DELETE SET NULL;

CREATE INDEX idx_teams_preferred_location ON teams(preferred_location_id);
