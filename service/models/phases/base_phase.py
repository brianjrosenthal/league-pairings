"""
Base phase interface.

All scheduling phases must implement this interface.
"""

from abc import ABC, abstractmethod
from typing import Dict, List
import logging
from datetime import datetime

from .scheduling_state import SchedulingState

logger = logging.getLogger(__name__)


class BasePhase(ABC):
    """
    Abstract base class for scheduling phases.
    
    Each phase receives:
    - Current scheduling state
    - Feasible games for the week
    - DataModel with mappings
    - Configuration parameters
    - Timeout
    
    And returns:
    - New scheduling state with games added
    """
    
    def __init__(
        self,
        model,
        config: Dict,
        max_games_per_week: int,
        max_games_per_day: int
    ):
        """
        Initialize phase with shared context.
        
        Args:
            model: DataModel with week/day mappings and team data
            config: Scheduling configuration
            max_games_per_week: Maximum games per team per week
            max_games_per_day: Maximum games per team per day
        """
        self.model = model
        self.config = config
        self.max_games_per_week = max_games_per_week
        self.max_games_per_day = max_games_per_day
    
    @abstractmethod
    def schedule(
        self,
        state: SchedulingState,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> SchedulingState:
        """
        Execute this phase's scheduling algorithm.
        
        Args:
            state: Current scheduling state
            feasible_games: All feasible games for this week
            week_num: Current week number
            timeout: Time limit for this phase
            
        Returns:
            New scheduling state with games added by this phase
        """
        pass
    
    @abstractmethod
    def get_phase_name(self) -> str:
        """Get human-readable phase name for logging."""
        pass
    
    # Shared utility methods for all phases
    
    def _get_total_game_count(self, team_id: int, schedule) -> int:
        """
        Calculate total games for a team = previous games + all scheduled games.
        
        Args:
            team_id: Team ID
            schedule: Current schedule
            
        Returns:
            Total number of games (previous + scheduled so far)
        """
        # Get previous games from database (stored in model.team_season_games)
        previous_count = self.model.team_season_games.get(team_id, 0)
        
        # Count all games scheduled so far for this team (all weeks)
        scheduled_count = 0
        for game in schedule.games:
            if game.get('teamA') == team_id or game.get('teamB') == team_id:
                scheduled_count += 1
        
        return previous_count + scheduled_count
    
    def _has_sufficient_season_data(self) -> bool:
        """
        Check if previous_games table has >3 weeks of data.
        
        Returns:
            True if data spans more than 3 weeks
        """
        if not self.model.previous_games:
            return False
        
        # Get all game dates
        dates = []
        for game in self.model.previous_games:
            if game.get('date'):
                date = game['date']
                if isinstance(date, str):
                    date = datetime.strptime(date, '%Y-%m-%d').date()
                dates.append(date)
        
        if not dates:
            return False
        
        # Calculate span in weeks
        date_range = max(dates) - min(dates)
        weeks = date_range.days / 7
        
        return weeks > 3
    
    def _calculate_season_ranking(self, team_id: int) -> float:
        """
        Calculate ranking based on current season win/loss record.
        Lower score = stronger team.
        
        Returns:
            Score from 0-100 where:
            - 0 = 100% win rate (best team)
            - 100 = 0% win rate (worst team)
        """
        wins = 0
        losses = 0
        
        for game in self.model.previous_games:
            if game['team_1_id'] == team_id:
                if game['team_1_score'] is not None and game['team_2_score'] is not None:
                    if game['team_1_score'] > game['team_2_score']:
                        wins += 1
                    else:
                        losses += 1
            elif game['team_2_id'] == team_id:
                if game['team_1_score'] is not None and game['team_2_score'] is not None:
                    if game['team_2_score'] > game['team_1_score']:
                        wins += 1
                    else:
                        losses += 1
        
        total_games = wins + losses
        if total_games == 0:
            return 999  # No games played
        
        # Calculate win percentage, then invert and scale to 0-100
        win_pct = wins / total_games
        # Convert: 100% wins = 0 (best), 0% wins = 100 (worst)
        return (1.0 - win_pct) * 100
    
    def _calculate_team_strength_score(self, team: Dict) -> float:
        """
        Calculate team strength score (lower = stronger).
        
        Uses current season win/loss record if >3 weeks of data exists,
        otherwise falls back to previous year ranking.
        """
        team_id = team['team_id']
        
        # Check if we have sufficient current season data (>3 weeks)
        if self._has_sufficient_season_data():
            # Use current season rankings based on win/loss record
            return self._calculate_season_ranking(team_id)
        else:
            # Use previous year ranking
            return team.get('previous_year_ranking', 999) or 999
    
    def _teams_played_recently(
        self,
        team1_id: int,
        team2_id: int,
        schedule,
        current_week: int,
        weeks_back: int = 3
    ) -> bool:
        """Check if two teams played each other in the last N weeks."""
        # Check previous games from database
        for game in self.model.previous_games:
            if self._game_involves_both_teams(game, team1_id, team2_id):
                game_date = game.get('date')
                if game_date:
                    if isinstance(game_date, str):
                        game_date = datetime.strptime(game_date, '%Y-%m-%d').date()
                    
                    if self.model.week_mapping:
                        for ts_id, (week, week_start, week_end) in self.model.week_mapping.items():
                            if week == current_week:
                                days_diff = (week_start - game_date).days
                                weeks_ago = days_diff // 7
                                
                                if 0 < weeks_ago <= weeks_back:
                                    return True
                                break
        
        # Check currently scheduled games
        for game in schedule.games:
            if self._game_involves_both_teams(game, team1_id, team2_id):
                return True
        
        return False
    
    def _game_involves_both_teams(self, game: Dict, team1_id: int, team2_id: int) -> bool:
        """Check if a game involves both specified teams."""
        game_teams = set()
        
        if 'teamA' in game and 'teamB' in game:
            game_teams = {game['teamA'], game['teamB']}
        elif 'team_1_id' in game and 'team_2_id' in game:
            game_teams = {game['team_1_id'], game['team_2_id']}
        
        return {team1_id, team2_id} == game_teams
