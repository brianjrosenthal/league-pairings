-- Add location_division_affinities table
-- This table stores which divisions have an affinity with which locations

CREATE TABLE location_division_affinities (
  location_id INT NOT NULL,
  division_id INT NOT NULL,
  PRIMARY KEY (location_id, division_id),
  CONSTRAINT fk_location_division_affinities_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_location_division_affinities_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_location_division_affinities_location ON location_division_affinities(location_id);
CREATE INDEX idx_location_division_affinities_division ON location_division_affinities(division_id);
