"""
Weight calculation utilities for game scheduling.
"""

from datetime import datetime, timedelta
from typing import Dict, List, Optional
from .constraints import ConstraintChecker


class WeightCalculator:
    """
    Calculates weights for potential game matchups.
    Higher weights indicate better/more desirable matchups.
    """
    
    def __init__(
        self, 
        teams: List[Dict],
        constraint_checker: ConstraintChecker,
        config: Dict
    ):
        """
        Initialize the weight calculator.
        
        Args:
            teams: List of team dictionaries with id, previous_year_ranking, etc.
            constraint_checker: Constraint checker for previous games
            config: Scheduling configuration parameters
        """
        self.teams = teams
        self.constraint_checker = constraint_checker
        self.config = config
        
        # Build team lookup
        self.team_lookup = {t['team_id']: t for t in teams}
    
    def calculate_weight(
        self,
        team_a_id: int,
        team_b_id: int,
        game_date: datetime
    ) -> float:
        """
        Calculate weight for a potential matchup.
        
        Args:
            team_a_id: First team ID
            team_b_id: Second team ID
            game_date: Proposed game date
            
        Returns:
            Weight value (higher is better, typically 0.0 to 1.0)
        """
        weight = 1.0
        
        # Factor 1: Ranking similarity (closer rankings = better matchup)
        weight *= self._ranking_weight(team_a_id, team_b_id)
        
        # Factor 2: Recent play penalty (played recently = lower weight)
        weight *= self._recency_weight(team_a_id, team_b_id, game_date)
        
        return weight
    
    def _ranking_weight(self, team_a_id: int, team_b_id: int) -> float:
        """
        Calculate weight based on ranking similarity.
        
        Teams with similar rankings make better matchups.
        """
        team_a = self.team_lookup.get(team_a_id, {})
        team_b = self.team_lookup.get(team_b_id, {})
        
        rank_a = team_a.get('previous_year_ranking')
        rank_b = team_b.get('previous_year_ranking')
        
        # If either team doesn't have a ranking, use neutral weight
        if rank_a is None or rank_b is None:
            return 0.5
        
        # Calculate ranking difference
        rank_diff = abs(rank_a - rank_b)
        
        # Ideal difference from config
        ideal_diff = self.config.get('ideal_ranking_diff', 5)
        
        # Weight decays as difference grows beyond ideal
        # Perfect match (same ranking) = 1.0
        # Ideal difference = 0.8
        # Large differences approach 0.1
        if rank_diff == 0:
            return 1.0
        elif rank_diff <= ideal_diff:
            return 1.0 - (rank_diff / ideal_diff) * 0.2
        else:
            # Exponential decay for larger differences
            return max(0.1, 0.8 * (ideal_diff / rank_diff))
    
    def _recency_weight(
        self, 
        team_a_id: int, 
        team_b_id: int,
        game_date: datetime
    ) -> float:
        """
        Calculate weight based on when teams last played.
        
        Recently played teams get lower weight.
        """
        # Check if teams played recently
        if self.constraint_checker.teams_played_recently(
            team_a_id, 
            team_b_id, 
            game_date
        ):
            # Apply penalty from config
            penalty = self.config.get('recent_game_penalty', 0.1)
            return penalty
        
        # Get last game date for graduated weighting
        last_game = self.constraint_checker.get_last_game_date(
            team_a_id, 
            team_b_id
        )
        
        if last_game is None:
            # Never played = highest weight for this factor
            return 1.0
        
        # Calculate weeks since last game
        if isinstance(game_date, datetime):
            game_date = game_date.date()
        if isinstance(last_game, datetime):
            last_game = last_game.date()
        
        weeks_since = (game_date - last_game).days / 7.0
        
        # Gradually increase weight as time passes
        recent_threshold = self.config.get('recent_games_weeks', 3)
        
        if weeks_since >= recent_threshold * 2:
            return 1.0  # Long time ago = full weight
        elif weeks_since >= recent_threshold:
            # Graduated between penalty and full weight
            progress = (weeks_since - recent_threshold) / recent_threshold
            penalty = self.config.get('recent_game_penalty', 0.1)
            return penalty + (1.0 - penalty) * progress
        else:
            # Should have been caught by teams_played_recently, but just in case
            return self.config.get('recent_game_penalty', 0.1)
    
    def apply_weights_to_games(self, games: List[Dict]) -> List[Dict]:
        """
        Apply weights to a list of feasible games.
        
        Args:
            games: List of game dictionaries with teamA, teamB, timeslot info
            
        Returns:
            Same list with 'weight' field added to each game
        """
        for game in games:
            # Get date from timeslot (will be added by data model)
            game_date = game.get('date')
            if game_date is None:
                # Fallback if date not in game dict
                game['weight'] = 0.5
                continue
            
            # Convert string date if needed
            if isinstance(game_date, str):
                game_date = datetime.strptime(game_date, '%Y-%m-%d')
            
            game['weight'] = self.calculate_weight(
                game['teamA'],
                game['teamB'],
                game_date
            )
        
        return games
