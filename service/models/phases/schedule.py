"""
Schedule class with built-in constraint enforcement.

Maintains a schedule of games with indexed data structures for efficient
constraint validation.
"""

from typing import Dict, List, Optional, Set, Tuple
from datetime import datetime
from collections import defaultdict
import logging

logger = logging.getLogger(__name__)


class Schedule:
    """
    Schedule with constraint enforcement.
    
    Maintains games with indexed lookups to enforce:
    1. Only one game per timeslot-location (TSL)
    2. Only one game per team per day
    3. Max games per team per week (configurable, default 2)
    4. Teams only play within their division
    5. Teams cannot play themselves
    """
    
    def __init__(self, model, max_games_per_week: int = 2):
        """
        Initialize schedule.
        
        Args:
            model: DataModel instance with divisions, teams, mappings, etc.
            max_games_per_week: Maximum games per team per week (default 2)
        """
        self.model = model
        self.max_games_per_week = max_games_per_week
        self.games = []  # All games in order
        
        # Indexes for efficient constraint checking
        self.games_by_tsl = {}  # tsl_id -> game
        self.games_by_team_day = defaultdict(list)  # (team_id, date) -> [games]
        self.games_by_team_week = defaultdict(list)  # (team_id, week_num) -> [games]
        
        # Additional tracking
        self.used_tsls = set()  # Set of used tsl_ids
    
    def add_game(self, game: Dict) -> bool:
        """
        Add game if it passes all constraint checks.
        
        Args:
            game: Game dictionary with teamA, teamB, tsl_id, timeslot_id, etc.
            
        Returns:
            True if game was added, False if it violated constraints
        """
        # Validate all constraints
        violations = self._check_constraints(game)
        
        if violations:
            logger.debug(f"Game rejected: {violations}")
            return False
        
        # Add game and update indexes
        self.games.append(game)
        self._update_indexes_add(game)
        
        return True
    
    def remove_game(self, game: Dict) -> bool:
        """
        Remove game from schedule.
        
        Args:
            game: Game dictionary to remove
            
        Returns:
            True if game was removed, False if not found
        """
        if game not in self.games:
            return False
        
        self.games.remove(game)
        self._update_indexes_remove(game)
        
        return True
    
    def _check_constraints(self, game: Dict) -> List[str]:
        """
        Check all constraints for a game.
        
        Args:
            game: Game dictionary
            
        Returns:
            List of constraint violation messages (empty if valid)
        """
        violations = []
        
        team_a = game.get('teamA')
        team_b = game.get('teamB')
        tsl_id = game.get('tsl_id')
        timeslot_id = game.get('timeslot_id')
        division_id = game.get('division_id')
        
        # Constraint 1: Only one game per TSL
        if tsl_id in self.games_by_tsl:
            violations.append(f"TSL {tsl_id} already used")
        
        # Constraint 2: Only one game per team per day
        if timeslot_id and timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            if isinstance(day, str):
                day = datetime.strptime(day, '%Y-%m-%d').date()
            
            if self.games_by_team_day.get((team_a, day)):
                violations.append(f"Team {team_a} already has game on {day}")
            if self.games_by_team_day.get((team_b, day)):
                violations.append(f"Team {team_b} already has game on {day}")
        
        # Constraint 3: Max games per team per week
        if timeslot_id and timeslot_id in self.model.week_mapping:
            week_num = self.model.week_mapping[timeslot_id][0]
            
            team_a_week_games = len(self.games_by_team_week.get((team_a, week_num), []))
            if team_a_week_games >= self.max_games_per_week:
                violations.append(f"Team {team_a} already has {self.max_games_per_week} games in week {week_num}")
            
            team_b_week_games = len(self.games_by_team_week.get((team_b, week_num), []))
            if team_b_week_games >= self.max_games_per_week:
                violations.append(f"Team {team_b} already has {self.max_games_per_week} games in week {week_num}")
        
        # Constraint 4: Teams only play within their division
        if team_a and team_b:
            team_a_division = self.model.team_lookup.get(team_a, {}).get('division_id')
            team_b_division = self.model.team_lookup.get(team_b, {}).get('division_id')
            
            if team_a_division != team_b_division:
                violations.append(f"Teams {team_a} and {team_b} in different divisions")
        
        # Constraint 5: Team cannot play itself
        if team_a == team_b:
            violations.append(f"Team {team_a} cannot play itself")
        
        return violations
    
    def _update_indexes_add(self, game: Dict):
        """Update all indexes when adding a game."""
        team_a = game.get('teamA')
        team_b = game.get('teamB')
        tsl_id = game.get('tsl_id')
        timeslot_id = game.get('timeslot_id')
        
        # Update TSL index
        if tsl_id:
            self.games_by_tsl[tsl_id] = game
            self.used_tsls.add(tsl_id)
        
        # Update team-day index
        if timeslot_id and timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            if isinstance(day, str):
                day = datetime.strptime(day, '%Y-%m-%d').date()
            
            self.games_by_team_day[(team_a, day)].append(game)
            self.games_by_team_day[(team_b, day)].append(game)
        
        # Update team-week index
        if timeslot_id and timeslot_id in self.model.week_mapping:
            week_num = self.model.week_mapping[timeslot_id][0]
            
            self.games_by_team_week[(team_a, week_num)].append(game)
            self.games_by_team_week[(team_b, week_num)].append(game)
    
    def _update_indexes_remove(self, game: Dict):
        """Update all indexes when removing a game."""
        team_a = game.get('teamA')
        team_b = game.get('teamB')
        tsl_id = game.get('tsl_id')
        timeslot_id = game.get('timeslot_id')
        
        # Update TSL index
        if tsl_id:
            if tsl_id in self.games_by_tsl:
                del self.games_by_tsl[tsl_id]
            if tsl_id in self.used_tsls:
                self.used_tsls.remove(tsl_id)
        
        # Update team-day index
        if timeslot_id and timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            if isinstance(day, str):
                day = datetime.strptime(day, '%Y-%m-%d').date()
            
            if game in self.games_by_team_day.get((team_a, day), []):
                self.games_by_team_day[(team_a, day)].remove(game)
            if game in self.games_by_team_day.get((team_b, day), []):
                self.games_by_team_day[(team_b, day)].remove(game)
        
        # Update team-week index
        if timeslot_id and timeslot_id in self.model.week_mapping:
            week_num = self.model.week_mapping[timeslot_id][0]
            
            if game in self.games_by_team_week.get((team_a, week_num), []):
                self.games_by_team_week[(team_a, week_num)].remove(game)
            if game in self.games_by_team_week.get((team_b, week_num), []):
                self.games_by_team_week[(team_b, week_num)].remove(game)
    
    def get_team_games_in_week(self, team_id: int, week_num: int) -> List[Dict]:
        """Get all games for a team in a specific week."""
        return self.games_by_team_week.get((team_id, week_num), [])
    
    def get_team_games_on_day(self, team_id: int, day) -> List[Dict]:
        """Get all games for a team on a specific day."""
        if isinstance(day, str):
            day = datetime.strptime(day, '%Y-%m-%d').date()
        return self.games_by_team_day.get((team_id, day), [])
    
    def is_tsl_used(self, tsl_id: int) -> bool:
        """Check if a TSL is already used."""
        return tsl_id in self.used_tsls
    
    def get_games_for_week(self, week_num: int) -> List[Dict]:
        """Get all games for a specific week."""
        week_games = []
        for game in self.games:
            timeslot_id = game.get('timeslot_id')
            if timeslot_id and timeslot_id in self.model.week_mapping:
                game_week = self.model.week_mapping[timeslot_id][0]
                if game_week == week_num:
                    week_games.append(game)
        return week_games
