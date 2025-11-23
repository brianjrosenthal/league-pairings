"""
Constraint checking utilities for the scheduling service.
"""

from datetime import datetime, timedelta
from typing import Dict, List, Optional, Set
from collections import defaultdict


class ConstraintChecker:
    """
    Checks various constraints for game scheduling.
    """
    
    def __init__(self, previous_games: List[Dict], config: Dict):
        """
        Initialize the constraint checker.
        
        Args:
            previous_games: List of previous games with date, team_1_id, team_2_id
            config: Scheduling configuration parameters
        """
        self.previous_games = previous_games
        self.recent_games_weeks = config.get('recent_games_weeks', 3)
        
        # Build lookup structure for quick access
        self._build_game_history()
    
    def _build_game_history(self):
        """Build a lookup structure for game history."""
        # Map (team_a, team_b) -> list of game dates
        self.game_history = defaultdict(list)
        
        for game in self.previous_games:
            team1 = game['team_1_id']
            team2 = game['team_2_id']
            date = game['date']
            
            # Store in both orders for easy lookup
            key1 = tuple(sorted([team1, team2]))
            self.game_history[key1].append(date)
    
    def teams_played_recently(
        self, 
        team_a_id: int, 
        team_b_id: int, 
        reference_date: datetime
    ) -> bool:
        """
        Check if two teams played each other recently.
        
        Args:
            team_a_id: First team ID
            team_b_id: Second team ID
            reference_date: Date to check against
            
        Returns:
            True if teams played within recent_games_weeks, False otherwise
        """
        key = tuple(sorted([team_a_id, team_b_id]))
        game_dates = self.game_history.get(key, [])
        
        if not game_dates:
            return False
        
        cutoff_date = reference_date - timedelta(weeks=self.recent_games_weeks)
        
        for game_date in game_dates:
            # Handle both date and datetime objects
            if isinstance(game_date, datetime):
                game_date = game_date.date()
            elif isinstance(game_date, str):
                game_date = datetime.strptime(game_date, '%Y-%m-%d').date()
            
            ref_date = reference_date.date() if isinstance(reference_date, datetime) else reference_date
            cutoff = cutoff_date.date() if isinstance(cutoff_date, datetime) else cutoff_date
            
            if game_date >= cutoff:
                return True
        
        return False
    
    def get_last_game_date(
        self, 
        team_a_id: int, 
        team_b_id: int
    ) -> Optional[datetime]:
        """
        Get the most recent date these teams played each other.
        
        Args:
            team_a_id: First team ID
            team_b_id: Second team ID
            
        Returns:
            Most recent game date or None if they never played
        """
        key = tuple(sorted([team_a_id, team_b_id]))
        game_dates = self.game_history.get(key, [])
        
        if not game_dates:
            return None
        
        # Convert to datetime if needed and find max
        dates = []
        for d in game_dates:
            if isinstance(d, str):
                dates.append(datetime.strptime(d, '%Y-%m-%d'))
            elif isinstance(d, datetime):
                dates.append(d)
            else:  # date object
                dates.append(datetime.combine(d, datetime.min.time()))
        
        return max(dates) if dates else None
    
    def get_team_game_count(
        self, 
        team_id: int, 
        start_date: datetime, 
        end_date: datetime
    ) -> int:
        """
        Count how many games a team has in a date range.
        
        Args:
            team_id: Team ID to check
            start_date: Start of date range
            end_date: End of date range
            
        Returns:
            Number of games the team has in the range
        """
        count = 0
        
        for game in self.previous_games:
            game_date = game['date']
            
            # Convert to comparable format
            if isinstance(game_date, str):
                game_date = datetime.strptime(game_date, '%Y-%m-%d')
            elif not isinstance(game_date, datetime):
                game_date = datetime.combine(game_date, datetime.min.time())
            
            if start_date <= game_date <= end_date:
                if game['team_1_id'] == team_id or game['team_2_id'] == team_id:
                    count += 1
        
        return count
