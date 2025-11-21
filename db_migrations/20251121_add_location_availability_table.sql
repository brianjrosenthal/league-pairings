-- Migration: Add location_availability table
-- Date: 2025-11-21

CREATE TABLE IF NOT EXISTS location_availability (
  location_id INT NOT NULL,
  timeslot_id INT NOT NULL,
  PRIMARY KEY (location_id, timeslot_id),
  CONSTRAINT fk_location_availability_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_location_availability_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_location_availability_location ON location_availability(location_id);
CREATE INDEX idx_location_availability_timeslot ON location_availability(timeslot_id);
