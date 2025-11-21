-- Migration: Add previous_year_ranking column to teams table
-- Date: 2025-11-21

ALTER TABLE teams ADD COLUMN previous_year_ranking INT NULL;
