-- Create DB then use it
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Users table with exact structure specified
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) DEFAULT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL DEFAULT '',
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  password_reset_token_hash CHAR(64) DEFAULT NULL,
  password_reset_expires_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_users_email_verify_token ON users(email_verify_token);
CREATE INDEX idx_users_pwreset_expires ON users(password_reset_expires_at);

-- Settings key-value table
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(191) NOT NULL UNIQUE,
  value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default settings
INSERT INTO settings (key_name, value) VALUES
  ('site_title', 'Change This Title'),
  ('announcement', ''),
  ('timezone', 'America/New_York'),
  ('login_image_file_id', '')
ON DUPLICATE KEY UPDATE value=VALUES(value);

-- ===== Files Storage (DB-backed uploads) =====

-- Public files (profile photos)
CREATE TABLE public_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data LONGBLOB NOT NULL,
  content_type VARCHAR(100) DEFAULT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  byte_length INT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pf_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_pf_sha256 ON public_files(sha256);
CREATE INDEX idx_pf_created_by ON public_files(created_by_user_id);
CREATE INDEX idx_pf_created_at ON public_files(created_at);

-- Link columns (added via ALTER to avoid circular FK creation order)
ALTER TABLE users
  ADD COLUMN photo_public_file_id INT NULL;

ALTER TABLE users
  ADD CONSTRAINT fk_users_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

-- ===== Activity Log =====
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action_type VARCHAR(64) NOT NULL,
  json_metadata LONGTEXT NULL,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_al_created_at ON activity_log(created_at);
CREATE INDEX idx_al_user_id ON activity_log(user_id);
CREATE INDEX idx_al_action_type ON activity_log(action_type);

-- ===== Email Log =====
CREATE TABLE emails_sent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_by_user_id INT NULL,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(255) DEFAULT NULL,
  cc_email VARCHAR(255) DEFAULT NULL,
  subject VARCHAR(500) NOT NULL,
  body_html LONGTEXT NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT DEFAULT NULL,
  CONSTRAINT fk_emails_sent_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_emails_sent_created_at ON emails_sent(created_at);
CREATE INDEX idx_emails_sent_user_id ON emails_sent(sent_by_user_id);
CREATE INDEX idx_emails_sent_to_email ON emails_sent(to_email);
CREATE INDEX idx_emails_sent_success ON emails_sent(success);

-- ===== Divisions =====
CREATE TABLE divisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_divisions_name ON divisions(name);

-- ===== Teams =====
CREATE TABLE teams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  division_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  previous_year_ranking INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_teams_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_teams_division_id ON teams(division_id);
CREATE INDEX idx_teams_name ON teams(name);

-- ===== Locations =====
CREATE TABLE locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  description TEXT NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_locations_name ON locations(name);

-- ===== Timeslots =====
CREATE TABLE timeslots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  modifier VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_date_modifier (date, modifier)
) ENGINE=InnoDB;

CREATE INDEX idx_timeslots_date ON timeslots(date);

-- ===== Location Availability =====
CREATE TABLE location_availability (
  location_id INT NOT NULL,
  timeslot_id INT NOT NULL,
  PRIMARY KEY (location_id, timeslot_id),
  CONSTRAINT fk_location_availability_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_location_availability_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_location_availability_location ON location_availability(location_id);
CREATE INDEX idx_location_availability_timeslot ON location_availability(timeslot_id);

-- ===== Team Availability =====
CREATE TABLE team_availability (
  team_id INT NOT NULL,
  timeslot_id INT NOT NULL,
  PRIMARY KEY (team_id, timeslot_id),
  CONSTRAINT fk_team_availability_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_availability_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_team_availability_team ON team_availability(team_id);
CREATE INDEX idx_team_availability_timeslot ON team_availability(timeslot_id);

-- ===== Location Division Affinities =====
CREATE TABLE location_division_affinities (
  location_id INT NOT NULL,
  division_id INT NOT NULL,
  PRIMARY KEY (location_id, division_id),
  CONSTRAINT fk_location_division_affinities_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_location_division_affinities_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_location_division_affinities_location ON location_division_affinities(location_id);
CREATE INDEX idx_location_division_affinities_division ON location_division_affinities(division_id);

-- ===== Previous Games =====
CREATE TABLE previous_games (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  team_1_id INT NOT NULL,
  team_2_id INT NOT NULL,
  team_1_score INT NULL,
  team_2_score INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_previous_games_team1 FOREIGN KEY (team_1_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_previous_games_team2 FOREIGN KEY (team_2_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_previous_games_date ON previous_games(date);
CREATE INDEX idx_previous_games_team1 ON previous_games(team_1_id);
CREATE INDEX idx_previous_games_team2 ON previous_games(team_2_id);

-- Optional: seed an admin user (update email and password hash, then remove)
INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verified_at)
VALUES ('Jeff','Millman','millman.jeffry@gmail.com','$2y$10$9xH7Jq4v3o6s9k3y8i4rVOyWb0yBYZ5rW.0f9pZ.gG9K6l7lS6b2S',1,NOW());
INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verified_at)
VALUES ('Brian','Rosenthal','brian.rosenthal@gmail.com','$2y$10$9xH7Jq4v3o6s9k3y8i4rVOyWb0yBYZ5rW.0f9pZ.gG9K6l7lS6b2S',1,NOW());
