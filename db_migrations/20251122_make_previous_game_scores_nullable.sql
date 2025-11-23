-- Make scores optional (nullable) in previous_games table
-- This allows importing games where scores may not be known yet

ALTER TABLE previous_games 
MODIFY COLUMN team_1_score INT NULL,
MODIFY COLUMN team_2_score INT NULL;
