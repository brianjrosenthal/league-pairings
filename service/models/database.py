"""
Database access layer for the scheduling service.
"""

import mysql.connector
from typing import Dict, List, Optional
from datetime import date, datetime
from utils.exceptions import DatabaseError


class Database:
    """
    Handles all database connections and queries.
    """
    
    def __init__(self, config: Dict):
        """
        Initialize database connection.
        
        Args:
            config: Database configuration dictionary
        """
        self.config = config
    
    def connect(self) -> mysql.connector.MySQLConnection:
        """
        Create and return a database connection.
        
        Returns:
            MySQL connection object
            
        Raises:
            DatabaseError: If connection fails
        """
        try:
            return mysql.connector.connect(**self.config)
        except mysql.connector.Error as e:
            raise DatabaseError(f"Failed to connect to database: {e}")
    
    def fetch_all_data(
        self, 
        start_date: date, 
        end_date: date
    ) -> Dict[str, List[Dict]]:
        """
        Fetch all data needed for scheduling within a date range.
        
        Args:
            start_date: Start of date range
            end_date: End of date range
            
        Returns:
            Dictionary containing all necessary data
            
        Raises:
            DatabaseError: If any query fails
        """
        try:
            conn = self.connect()
            cursor = conn.cursor(dictionary=True)
            
            # Fetch teams
            teams = self._fetch_teams(cursor)
            
            # Fetch divisions
            divisions = self._fetch_divisions(cursor)
            
            # Fetch timeslots in date range
            timeslots = self._fetch_timeslots(cursor, start_date, end_date)
            
            # Fetch locations
            locations = self._fetch_locations(cursor)
            
            # Fetch location availability (filtered by date range)
            location_availability = self._fetch_location_availability(
                cursor, start_date, end_date
            )
            
            # Fetch team availability (filtered by date range)
            team_availability = self._fetch_team_availability(
                cursor, start_date, end_date
            )
            
            # Fetch previous games (all historical data)
            previous_games = self._fetch_previous_games(cursor)
            
            conn.close()
            
            return {
                "teams": teams,
                "divisions": divisions,
                "timeslots": timeslots,
                "locations": locations,
                "location_availability": location_availability,
                "team_availability": team_availability,
                "previous_games": previous_games
            }
            
        except mysql.connector.Error as e:
            raise DatabaseError(f"Database query failed: {e}")
        except Exception as e:
            raise DatabaseError(f"Unexpected error during data fetch: {e}")
    
    def _fetch_teams(self, cursor) -> List[Dict]:
        """Fetch all teams."""
        cursor.execute("""
            SELECT 
                id AS team_id,
                division_id,
                name,
                description,
                previous_year_ranking,
                preferred_location_id
            FROM teams
            ORDER BY division_id, name
        """)
        return cursor.fetchall()
    
    def _fetch_divisions(self, cursor) -> List[Dict]:
        """Fetch all divisions."""
        cursor.execute("""
            SELECT 
                id,
                name
            FROM divisions
            ORDER BY name
        """)
        return cursor.fetchall()
    
    def _fetch_timeslots(
        self, 
        cursor, 
        start_date: date, 
        end_date: date
    ) -> List[Dict]:
        """Fetch timeslots within date range."""
        cursor.execute("""
            SELECT 
                id AS timeslot_id,
                date,
                modifier
            FROM timeslots
            WHERE date BETWEEN %s AND %s
            ORDER BY date, modifier
        """, (start_date, end_date))
        return cursor.fetchall()
    
    def _fetch_locations(self, cursor) -> List[Dict]:
        """Fetch all locations."""
        cursor.execute("""
            SELECT 
                id AS location_id,
                name,
                description
            FROM locations
            ORDER BY name
        """)
        return cursor.fetchall()
    
    def _fetch_location_availability(
        self, 
        cursor, 
        start_date: date, 
        end_date: date
    ) -> List[Dict]:
        """Fetch location availability filtered by date range."""
        cursor.execute("""
            SELECT 
                la.location_id,
                la.timeslot_id
            FROM location_availability la
            INNER JOIN timeslots ts ON ts.id = la.timeslot_id
            WHERE ts.date BETWEEN %s AND %s
            ORDER BY la.location_id, la.timeslot_id
        """, (start_date, end_date))
        return cursor.fetchall()
    
    def _fetch_team_availability(
        self, 
        cursor, 
        start_date: date, 
        end_date: date
    ) -> List[Dict]:
        """Fetch team availability filtered by date range."""
        cursor.execute("""
            SELECT 
                ta.team_id,
                ta.timeslot_id
            FROM team_availability ta
            INNER JOIN timeslots ts ON ts.id = ta.timeslot_id
            WHERE ts.date BETWEEN %s AND %s
            ORDER BY ta.team_id, ta.timeslot_id
        """, (start_date, end_date))
        return cursor.fetchall()
    
    def _fetch_previous_games(self, cursor) -> List[Dict]:
        """Fetch all previous games (for constraint checking)."""
        cursor.execute("""
            SELECT 
                id,
                date,
                team_1_id,
                team_2_id,
                team_1_score,
                team_2_score
            FROM previous_games
            ORDER BY date DESC
        """)
        return cursor.fetchall()
