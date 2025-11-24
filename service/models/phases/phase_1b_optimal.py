"""
Phase 1B: Fill-in Scheduling for Unscheduled Teams

Schedules remaining unscheduled teams by pairing them with any available team in their division.
Uses a manual greedy algorithm focusing on individual unscheduled teams.
"""

from typing import Dict, List, Optional
import logging
import random
from datetime import datetime

from .base_phase import BasePhase
from .scheduling_state import SchedulingState

logger = logging.getLogger(__name__)


class Phase1BOptimal(BasePhase):
    """
    Phase 1B: Fill-in scheduling for remaining unscheduled teams.
    
    Goal: Schedule as many unscheduled teams as possible.
    Method: For each unscheduled team, try to pair with ANY team in division (max 3 games/week).
    Priority: Prefer matchups not played recently.
    """
    
    def get_phase_name(self) -> str:
        return "Phase 1B: Fill-in Scheduling"
    
    def schedule(
        self,
        state: SchedulingState,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> SchedulingState:
        """
        Execute Phase 1B scheduling.
        
        Args:
            state: Current scheduling state
            feasible_games: All feasible games (ignored - we build our own)
            week_num: Current week number
            timeout: Time limit (ignored for deterministic algorithm)
            
        Returns:
            New state with Phase 1B games added
        """
        logger.info(f"Phase 1B Week {week_num}: Starting fill-in scheduling")
        
        initial_game_count = len([g for g in state.scheduled_games 
                                  if self.model.week_mapping.get(g.get('timeslot_id'), [None])[0] == week_num])
        
        # Process each division
        for division in self.model.divisions:
            division_id = division['id']
            division_name = division['name']
            
            teams_in_division = self.model.teams_by_division.get(division_id, [])
            if not teams_in_division:
                continue
            
            # Get unscheduled teams for this week
            unscheduled_teams = self._get_unscheduled_teams_for_week(
                teams_in_division, state, week_num
            )
            
            if not unscheduled_teams:
                continue
            
            logger.info(f"\n  {division_name}: {len(unscheduled_teams)} unscheduled teams")
            
            # Try to schedule each unscheduled team
            for unscheduled_team in unscheduled_teams:
                game = self._try_to_schedule_team(
                    unscheduled_team, teams_in_division, state, week_num
                )
                
                if game:
                    state = state.add_game(game, self.model.week_mapping, self.model.day_mapping)
                    logger.info(f"    ✓ {unscheduled_team['name']}: Paired with {game['teamB_name'] if game['teamA'] == unscheduled_team['team_id'] else game['teamA_name']}")
        
        games_added = len([g for g in state.scheduled_games 
                          if self.model.week_mapping.get(g.get('timeslot_id'), [None])[0] == week_num]) - initial_game_count
        
        logger.info(f"\nPhase 1B Week {week_num}: Scheduled {games_added} additional games")
        
        return state
    
    def _get_unscheduled_teams_for_week(
        self,
        teams: List[Dict],
        state: SchedulingState,
        week_num: int
    ) -> List[Dict]:
        """
        Get teams that have no games scheduled this week.
        
        Args:
            teams: List of team dictionaries
            state: Current scheduling state
            week_num: Current week number
            
        Returns:
            List of unscheduled team dictionaries
        """
        scheduled_team_ids = set()
        
        for game in state.scheduled_games:
            timeslot_id = game.get('timeslot_id')
            if timeslot_id and timeslot_id in self.model.week_mapping:
                game_week = self.model.week_mapping[timeslot_id][0]
                if game_week == week_num:
                    scheduled_team_ids.add(game['teamA'])
                    scheduled_team_ids.add(game['teamB'])
        
        return [t for t in teams if t['team_id'] not in scheduled_team_ids]
    
    def _try_to_schedule_team(
        self,
        unscheduled_team: Dict,
        teams_in_division: List[Dict],
        state: SchedulingState,
        week_num: int
    ) -> Optional[Dict]:
        """
        Try to schedule a specific unscheduled team.
        
        Attempts to pair with ANY other team in division (scheduled or unscheduled),
        as long as that team has < 3 games this week.
        
        Two-pass approach:
        1. Try teams not played recently (last 3 weeks)
        2. Try any available team
        
        Args:
            unscheduled_team: Team to schedule
            teams_in_division: All teams in division
            state: Current scheduling state
            week_num: Current week number
            
        Returns:
            Game dictionary if found, None otherwise
        """
        unscheduled_team_id = unscheduled_team['team_id']
        
        # Get all other teams in division (excluding this team)
        other_teams = [t for t in teams_in_division if t['team_id'] != unscheduled_team_id]
        
        # Filter to teams with < 3 games this week
        available_teams = self._filter_teams_under_weekly_limit(
            other_teams, state, week_num
        )
        
        if not available_teams:
            return None
        
        # First pass: Try teams not played recently (last 3 weeks)
        for other_team in available_teams:
            other_team_id = other_team['team_id']
            
            if not self._teams_played_recently(unscheduled_team_id, other_team_id, state, week_num, weeks_back=3):
                game = self._try_to_find_game(unscheduled_team_id, other_team_id, state, week_num)
                if game:
                    return game
        
        # Second pass: Try any available team (ignore recent play constraint)
        for other_team in available_teams:
            other_team_id = other_team['team_id']
            game = self._try_to_find_game(unscheduled_team_id, other_team_id, state, week_num)
            if game:
                return game
        
        return None
    
    def _filter_teams_under_weekly_limit(
        self,
        teams: List[Dict],
        state: SchedulingState,
        week_num: int
    ) -> List[Dict]:
        """
        Filter teams to only those with < max_games_per_week this week.
        
        Args:
            teams: List of team dictionaries
            state: Current scheduling state
            week_num: Current week number
            
        Returns:
            Filtered list of teams
        """
        # Count games per team this week
        team_game_counts = {}
        for team in teams:
            team_game_counts[team['team_id']] = 0
        
        for game in state.scheduled_games:
            timeslot_id = game.get('timeslot_id')
            if timeslot_id and timeslot_id in self.model.week_mapping:
                game_week = self.model.week_mapping[timeslot_id][0]
                if game_week == week_num:
                    for team_id in [game['teamA'], game['teamB']]:
                        if team_id in team_game_counts:
                            team_game_counts[team_id] += 1
        
        # Filter to teams with < max_games_per_week
        return [t for t in teams if team_game_counts[t['team_id']] < self.max_games_per_week]
    
    def _teams_played_recently(
        self,
        team1_id: int,
        team2_id: int,
        state: SchedulingState,
        current_week: int,
        weeks_back: int = 3
    ) -> bool:
        """
        Check if two teams played each other in the last N weeks.
        
        Checks both previous games from database and currently scheduled games.
        
        Args:
            team1_id: First team ID
            team2_id: Second team ID
            state: Current scheduling state
            current_week: Current week number
            weeks_back: Number of weeks to look back
            
        Returns:
            True if teams played recently, False otherwise
        """
        # Check previous games from database
        for game in self.model.previous_games:
            if self._game_involves_both_teams(game, team1_id, team2_id):
                game_date = game.get('date')
                if game_date:
                    if isinstance(game_date, str):
                        game_date = datetime.strptime(game_date, '%Y-%m-%d').date()
                    
                    # Get current week's start date
                    if self.model.week_mapping:
                        for ts_id, (week, week_start, week_end) in self.model.week_mapping.items():
                            if week == current_week:
                                days_diff = (week_start - game_date).days
                                weeks_ago = days_diff // 7
                                
                                if 0 < weeks_ago <= weeks_back:
                                    return True
                                break
        
        # Check currently scheduled games in this run
        for game in state.scheduled_games:
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
    
    def _try_to_find_game(
        self,
        team1_id: int,
        team2_id: int,
        state: SchedulingState,
        week_num: int
    ) -> Optional[Dict]:
        """
        Try to find a valid game between two teams.
        
        Finds available TSLs where:
        - Both teams are available
        - TSL is in the current week
        - TSL hasn't been used yet
        
        Prefers Sunday TSLs if available.
        
        Args:
            team1_id: First team ID
            team2_id: Second team ID
            state: Current scheduling state
            week_num: Current week number
            
        Returns:
            Game dictionary if found, None otherwise
        """
        # Get team availability
        team1_timeslots = self.model.team_availability.get(team1_id, set())
        team2_timeslots = self.model.team_availability.get(team2_id, set())
        
        # Find overlapping timeslots
        common_timeslots = team1_timeslots & team2_timeslots
        
        if not common_timeslots:
            return None
        
        # Find available TSLs for these timeslots in this week
        available_tsls = []
        for tsl in self.model.tsls:
            tsl_id = tsl['tsl_id']
            timeslot_id = tsl['timeslot_id']
            
            if timeslot_id not in common_timeslots:
                continue
            
            if timeslot_id not in self.model.week_mapping:
                continue
            
            tsl_week = self.model.week_mapping[timeslot_id][0]
            if tsl_week != week_num:
                continue
            
            if tsl_id in state.used_tsls:
                continue
            
            available_tsls.append(tsl)
        
        if not available_tsls:
            return None
        
        # Prefer Sunday TSLs
        sunday_tsls = [tsl for tsl in available_tsls if self._is_sunday_tsl(tsl)]
        chosen_tsl = random.choice(sunday_tsls) if sunday_tsls else random.choice(available_tsls)
        
        # Create game dictionary
        team1 = self.model.team_lookup[team1_id]
        team2 = self.model.team_lookup[team2_id]
        
        return {
            'teamA': team1_id,
            'teamB': team2_id,
            'teamA_name': team1['name'],
            'teamB_name': team2['name'],
            'division_id': team1['division_id'],
            'division_name': self.model.get_division_name(team1['division_id']),
            'timeslot_id': chosen_tsl['timeslot_id'],
            'location_id': chosen_tsl['location_id'],
            'location_name': chosen_tsl['location_name'],
            'tsl_id': chosen_tsl['tsl_id'],
            'date': chosen_tsl['date'],
            'modifier': chosen_tsl['modifier']
        }
    
    def _is_sunday_tsl(self, tsl: Dict) -> bool:
        """Check if a TSL is on Sunday."""
        timeslot_id = tsl.get('timeslot_id')
        if not timeslot_id or timeslot_id not in self.model.day_mapping:
            return False
        
        day = self.model.day_mapping[timeslot_id]
        try:
            if isinstance(day, str):
                day = datetime.strptime(day, '%Y-%m-%d').date()
            return day.weekday() == 6  # Sunday = 6
        except (ValueError, TypeError):
            return False
