-- Add location_division_holdouts table
-- This table stores which divisions are held out from which locations
-- A division in a location's holdout list will NOT be scheduled at that location

CREATE TABLE location_division_holdouts (
  location_id INT NOT NULL,
  division_id INT NOT NULL,
  PRIMARY KEY (location_id, division_id),
  CONSTRAINT fk_location_division_holdouts_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_location_division_holdouts_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_location_division_holdouts_location ON location_division_holdouts(location_id);
CREATE INDEX idx_location_division_holdouts_division ON location_division_holdouts(division_id);
