"""
Scheduling state object.

Immutable state that gets passed between phases. Each phase returns a new state.
"""

from typing import Dict, List, Set
from collections import defaultdict
from dataclasses import dataclass, field


@dataclass
class SchedulingState:
    """
    Immutable scheduling state passed between phases.
    
    Each phase receives a state, performs scheduling, and returns a new state.
    """
    # List of scheduled games
    scheduled_games: List[Dict] = field(default_factory=list)
    
    # Set of TSL IDs that have been used
    used_tsls: Set[str] = field(default_factory=set)
    
    # Team weekly game counts: {week_num: {team_id: count}}
    team_weekly_games: Dict[int, Dict[int, int]] = field(default_factory=lambda: defaultdict(lambda: defaultdict(int)))
    
    # Team daily game counts: {day: {team_id: count}}
    team_daily_games: Dict[str, Dict[int, int]] = field(default_factory=lambda: defaultdict(lambda: defaultdict(int)))
    
    # Games per team across all weeks in this run: {team_id: count}
    games_this_run: Dict[int, int] = field(default_factory=lambda: defaultdict(int))
    
    def copy(self) -> 'SchedulingState':
        """Create a deep copy of this state for modification."""
        import copy
        
        # Properly copy nested defaultdicts
        new_weekly = defaultdict(lambda: defaultdict(int))
        for week, teams in self.team_weekly_games.items():
            for team, count in teams.items():
                new_weekly[week][team] = count
        
        new_daily = defaultdict(lambda: defaultdict(int))
        for day, teams in self.team_daily_games.items():
            for team, count in teams.items():
                new_daily[day][team] = count
        
        return SchedulingState(
            scheduled_games=copy.deepcopy(self.scheduled_games),
            used_tsls=self.used_tsls.copy(),
            team_weekly_games=new_weekly,
            team_daily_games=new_daily,
            games_this_run=self.games_this_run.copy()
        )
    
    def add_game(self, game: Dict, week_mapping: Dict, day_mapping: Dict) -> 'SchedulingState':
        """
        Create a new state with the game added.
        
        Args:
            game: Game to add
            week_mapping: Timeslot to week mapping
            day_mapping: Timeslot to day mapping
            
        Returns:
            New state with game added
        """
        new_state = self.copy()
        
        # Add to scheduled games
        new_state.scheduled_games.append(game)
        
        # Mark TSL as used
        new_state.used_tsls.add(game['tsl_id'])
        
        # Update weekly counts
        timeslot_id = game['timeslot_id']
        if timeslot_id in week_mapping:
            week_num = week_mapping[timeslot_id][0]
            new_state.team_weekly_games[week_num][game['teamA']] += 1
            new_state.team_weekly_games[week_num][game['teamB']] += 1
        
        # Update daily counts
        if timeslot_id in day_mapping:
            day = day_mapping[timeslot_id]
            new_state.team_daily_games[day][game['teamA']] += 1
            new_state.team_daily_games[day][game['teamB']] += 1
        
        # Update games this run
        new_state.games_this_run[game['teamA']] += 1
        new_state.games_this_run[game['teamB']] += 1
        
        return new_state
    
    def remove_game(self, game: Dict, week_mapping: Dict, day_mapping: Dict) -> 'SchedulingState':
        """
        Create a new state with the game removed (for displacement).
        
        Args:
            game: Game to remove
            week_mapping: Timeslot to week mapping
            day_mapping: Timeslot to day mapping
            
        Returns:
            New state with game removed
        """
        new_state = self.copy()
        
        # Remove from scheduled games
        new_state.scheduled_games = [g for g in new_state.scheduled_games if g != game]
        
        # Mark TSL as unused
        new_state.used_tsls.discard(game['tsl_id'])
        
        # Update weekly counts
        timeslot_id = game['timeslot_id']
        if timeslot_id in week_mapping:
            week_num = week_mapping[timeslot_id][0]
            new_state.team_weekly_games[week_num][game['teamA']] -= 1
            new_state.team_weekly_games[week_num][game['teamB']] -= 1
        
        # Update daily counts
        if timeslot_id in day_mapping:
            day = day_mapping[timeslot_id]
            new_state.team_daily_games[day][game['teamA']] -= 1
            new_state.team_daily_games[day][game['teamB']] -= 1
        
        # Update games this run
        new_state.games_this_run[game['teamA']] -= 1
        new_state.games_this_run[game['teamB']] -= 1
        
        return new_state
    
    def can_schedule_game(
        self,
        team_a: int,
        team_b: int,
        timeslot_id: int,
        week_mapping: Dict,
        day_mapping: Dict,
        max_games_per_week: int,
        max_games_per_day: int
    ) -> bool:
        """
        Check if a game can be scheduled without violating constraints.
        
        Args:
            team_a: First team ID
            team_b: Second team ID
            timeslot_id: Timeslot ID
            week_mapping: Timeslot to week mapping
            day_mapping: Timeslot to day mapping
            max_games_per_week: Maximum games per team per week
            max_games_per_day: Maximum games per team per day
            
        Returns:
            True if game can be scheduled
        """
        # Get week and day for this timeslot
        if timeslot_id not in week_mapping:
            return False
        
        week_num = week_mapping[timeslot_id][0]
        day = day_mapping.get(timeslot_id)
        
        if day is None:
            return False
        
        # Check weekly limits
        if self.team_weekly_games[week_num][team_a] >= max_games_per_week:
            return False
        if self.team_weekly_games[week_num][team_b] >= max_games_per_week:
            return False
        
        # Check daily limits
        if self.team_daily_games[day][team_a] >= max_games_per_day:
            return False
        if self.team_daily_games[day][team_b] >= max_games_per_day:
            return False
        
        return True
