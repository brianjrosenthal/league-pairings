"""
Phase 2: Greedy Capacity Filling

Greedily fills remaining capacity up to weekly/daily limits.
Uses generator-based approach to ensure fair distribution across all teams.
"""

from typing import Dict, List, Optional, Generator
import logging
import random
from datetime import datetime

from .base_phase import BasePhase
from .schedule import Schedule

logger = logging.getLogger(__name__)


class Phase2Greedy(BasePhase):
    """
    Phase 2: Greedy capacity filling with fair distribution.
    
    Goal: Maximize TSL utilization while respecting weekly/daily limits.
    Method: Round-robin across divisions using generators to maintain position.
    """
    
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.division_generators = {}  # Track generators per (division_id, week_num)
    
    def get_phase_name(self) -> str:
        return "Phase 2: Greedy Filling"
    
    def schedule(
        self,
        schedule: Schedule,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> Schedule:
        """
        Execute Phase 2 scheduling using round-robin with generators.
        
        Args:
            schedule: Current schedule with constraint enforcement
            feasible_games: All feasible games (ignored - we build our own)
            week_num: Current week number
            timeout: Time limit (ignored for deterministic algorithm)
            
        Returns:
            Updated schedule with Phase 2 games added
        """
        logger.info(f"Phase 2 Week {week_num}: Starting greedy capacity filling")
        
        initial_game_count = len(schedule.get_games_for_week(week_num))
        
        # Round-robin: schedule one game per division per round until no more possible
        round_num = 0
        consecutive_empty_rounds = 0
        max_consecutive_empty = len(self.model.divisions) * 2  # Allow multiple passes
        
        while consecutive_empty_rounds < max_consecutive_empty:
            round_num += 1
            games_this_round = 0
            
            # Try to schedule one game for each division
            for division in self.model.divisions:
                division_id = division['id']
                division_name = division['name']
                
                game = self._find_next_game_for_division(division_id, schedule, week_num)
                
                if game:
                    if schedule.add_game(game):
                        games_this_round += 1
                    else:
                        logger.debug(f"Game rejected by constraints: {game['teamA_name']} vs {game['teamB_name']}")
            
            # Track consecutive empty rounds to ensure we exhaust all possibilities
            if games_this_round == 0:
                consecutive_empty_rounds += 1
            else:
                consecutive_empty_rounds = 0  # Reset counter when games are found
        
        games_added = len(schedule.get_games_for_week(week_num)) - initial_game_count
        
        logger.info(f"\nPhase 2 Week {week_num}: Scheduled {games_added} additional games in {round_num} rounds")
        
        # Run exhaustive final check to catch any missed opportunities
        additional_games = self._exhaustive_final_check(schedule, week_num)
        
        if additional_games > 0:
            logger.info(f"\n*** Phase 2 Final Check Week {week_num}: Found {additional_games} additional games! ***")
        
        # Clear generators for this week
        self._clear_week_generators(week_num)
        
        return schedule
    
    def _find_next_game_for_division(
        self,
        division_id: int,
        schedule: Schedule,
        week_num: int
    ) -> Optional[Dict]:
        """
        Find next game for division using generator to maintain position.
        
        Args:
            division_id: Division ID
            schedule: Current schedule
            week_num: Current week number
            
        Returns:
            Game dictionary if found, None otherwise
        """
        key = (division_id, week_num)
        
        # Create generator if not exists or was exhausted
        if key not in self.division_generators:
            self.division_generators[key] = self._division_game_generator(
                division_id, schedule, week_num
            )
        
        # Get next game from generator
        try:
            return next(self.division_generators[key])
        except StopIteration:
            # Generator exhausted, remove it
            if key in self.division_generators:
                del self.division_generators[key]
            return None
    
    def _division_game_generator(
        self,
        division_id: int,
        schedule: Schedule,
        week_num: int
    ) -> Generator[Dict, None, None]:
        """
        Generator that yields games for a division, maintaining position between calls.
        
        Uses two-pass approach:
        1. First pass: Avoid teams that played recently
        2. Second pass: Allow any pairing
        
        Args:
            division_id: Division ID
            schedule: Current schedule
            week_num: Current week number
            
        Yields:
            Game dictionaries
        """
        teams_in_division = self.model.teams_by_division.get(division_id, [])
        
        if not teams_in_division:
            return
        
        # Get available teams sorted by strength (for fair pairing)
        available_teams = self._get_available_teams_sorted(teams_in_division, schedule, week_num)
        
        # Pass 1: Avoid recent play (soft constraint)
        for i, team1 in enumerate(available_teams):
            # Re-check availability each iteration (schedule changes between yields)
            if not self._is_team_available(team1['team_id'], schedule, week_num):
                continue
            
            for team2 in available_teams[i+1:]:
                if not self._is_team_available(team2['team_id'], schedule, week_num):
                    continue
                
                team1_id = team1['team_id']
                team2_id = team2['team_id']
                
                # Soft constraint: avoid recent play
                if not self._teams_played_recently(team1_id, team2_id, schedule, week_num, weeks_back=3):
                    game = self._try_to_find_game(team1_id, team2_id, schedule, week_num)
                    if game:
                        yield game
        
        # Pass 2: Allow recent play (ignore soft constraint)
        for i, team1 in enumerate(available_teams):
            if not self._is_team_available(team1['team_id'], schedule, week_num):
                continue
            
            for team2 in available_teams[i+1:]:
                if not self._is_team_available(team2['team_id'], schedule, week_num):
                    continue
                
                team1_id = team1['team_id']
                team2_id = team2['team_id']
                
                game = self._try_to_find_game(team1_id, team2_id, schedule, week_num)
                if game:
                    yield game
    
    def _get_available_teams_sorted(
        self,
        teams: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> List[Dict]:
        """
        Get teams with < max_games_per_week, sorted by strength.
        
        Args:
            teams: List of team dictionaries
            schedule: Current schedule
            week_num: Current week number
            
        Returns:
            Sorted list of available teams
        """
        # Filter to teams under weekly limit
        available = []
        for team in teams:
            if self._is_team_available(team['team_id'], schedule, week_num):
                available.append(team)
        
        # Sort by strength (strongest first, for balanced pairing)
        scored_teams = [(team, self._calculate_team_strength_score(team)) for team in available]
        scored_teams.sort(key=lambda x: x[1])  # Sort by score ascending (lower = stronger)
        
        return [team for team, score in scored_teams]
    
    def _is_team_available(
        self,
        team_id: int,
        schedule: Schedule,
        week_num: int
    ) -> bool:
        """Check if team has < max_games_per_week this week."""
        team_games = schedule.get_team_games_in_week(team_id, week_num)
        return len(team_games) < self.max_games_per_week
    
    def _calculate_team_strength_score(self, team: Dict) -> float:
        """Calculate team strength score (lower = stronger)."""
        team_id = team['team_id']
        
        # Start with previous year ranking (1 = best)
        score = team.get('previous_year_ranking', 999) or 999
        
        # Adjust based on wins and losses
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
        
        score = score - wins + losses
        return score
    
    def _teams_played_recently(
        self,
        team1_id: int,
        team2_id: int,
        schedule: Schedule,
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
    
    def _try_to_find_game(
        self,
        team1_id: int,
        team2_id: int,
        schedule: Schedule,
        week_num: int
    ) -> Optional[Dict]:
        """
        Try to find a valid game between two teams.
        
        Note: Schedule class will handle all constraint validation including:
        - TSL uniqueness (no double-booking)
        - Daily game limits (max 1 per team per day)
        - Weekly game limits (max 3 per team per week)
        - Division matching
        - No self-play
        
        Args:
            team1_id: First team ID
            team2_id: Second team ID
            schedule: Current schedule
            week_num: Current week number
            
        Returns:
            Game dictionary if found, None otherwise
        """
        team1_timeslots = self.model.team_availability.get(team1_id, set())
        team2_timeslots = self.model.team_availability.get(team2_id, set())
        
        common_timeslots = team1_timeslots & team2_timeslots
        
        if not common_timeslots:
            return None
        
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
            
            # Use Schedule's efficient TSL check
            if schedule.is_tsl_used(tsl_id):
                continue
            
            available_tsls.append(tsl)
        
        if not available_tsls:
            return None
        
        sunday_tsls = [tsl for tsl in available_tsls if self._is_sunday_tsl(tsl)]
        chosen_tsl = random.choice(sunday_tsls) if sunday_tsls else random.choice(available_tsls)
        
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
    
    def _exhaustive_final_check(
        self,
        schedule: Schedule,
        week_num: int
    ) -> int:
        """
        Exhaustive final check to catch any games missed by greedy algorithm.
        
        Systematically checks:
        - For each division
        - For each team pair in that division
        - For each available timeslot-location
        - If constraints allow, schedule the game
        
        Args:
            schedule: Current schedule
            week_num: Current week number
            
        Returns:
            Number of additional games found and scheduled
        """
        games_found = 0
        
        # For each division
        for division in self.model.divisions:
            division_id = division['id']
            division_name = division['name']
            teams_in_division = self.model.teams_by_division.get(division_id, [])
            
            if len(teams_in_division) < 2:
                continue
            
            # For each team pair in this division
            for i, team1 in enumerate(teams_in_division):
                team1_id = team1['team_id']
                team1_name = team1['name']
                
                for team2 in teams_in_division[i+1:]:
                    team2_id = team2['team_id']
                    team2_name = team2['name']
                    
                    # Check if this pair has already played this week
                    if self._teams_played_this_week(team1_id, team2_id, schedule, week_num):
                        continue
                    
                    # Check if either team would exceed 3 games
                    team1_games = len(schedule.get_team_games_in_week(team1_id, week_num))
                    team2_games = len(schedule.get_team_games_in_week(team2_id, week_num))
                    
                    if team1_games >= self.max_games_per_week or team2_games >= self.max_games_per_week:
                        continue
                    
                    # Get common timeslots where both teams are available
                    team1_timeslots = self.model.team_availability.get(team1_id, set())
                    team2_timeslots = self.model.team_availability.get(team2_id, set())
                    common_timeslots = team1_timeslots & team2_timeslots
                    
                    if not common_timeslots:
                        continue
                    
                    # For each available timeslot-location in this week
                    for tsl in self.model.tsls:
                        tsl_id = tsl['tsl_id']
                        timeslot_id = tsl['timeslot_id']
                        location_id = tsl['location_id']
                        
                        # Check if this timeslot is in the current week
                        if timeslot_id not in self.model.week_mapping:
                            continue
                        
                        tsl_week = self.model.week_mapping[timeslot_id][0]
                        if tsl_week != week_num:
                            continue
                        
                        # Check if timeslot is available to both teams
                        if timeslot_id not in common_timeslots:
                            continue
                        
                        # Check if this TSL is already occupied
                        if schedule.is_tsl_used(tsl_id):
                            continue
                        
                        # Check daily game limits for both teams
                        tsl_date = tsl.get('date')
                        if tsl_date:
                            if self._team_has_game_on_date(team1_id, tsl_date, schedule):
                                continue
                            if self._team_has_game_on_date(team2_id, tsl_date, schedule):
                                continue
                        
                        # All constraints pass - create and add the game!
                        game = {
                            'teamA': team1_id,
                            'teamB': team2_id,
                            'teamA_name': team1_name,
                            'teamB_name': team2_name,
                            'division_id': division_id,
                            'division_name': division_name,
                            'timeslot_id': timeslot_id,
                            'location_id': location_id,
                            'location_name': tsl['location_name'],
                            'tsl_id': tsl_id,
                            'date': tsl_date,
                            'modifier': tsl.get('modifier')
                        }
                        
                        if schedule.add_game(game):
                            games_found += 1
                            logger.info(
                                f"Final check found game: {team1_name} vs {team2_name} "
                                f"at {tsl['location_name']} on {tsl_date}"
                            )
                            # Break after finding one game for this pair
                            # (they could potentially play in multiple timeslots, but we only schedule one)
                            break
        
        return games_found
    
    def _teams_played_this_week(
        self,
        team1_id: int,
        team2_id: int,
        schedule: Schedule,
        week_num: int
    ) -> bool:
        """
        Check if two teams have already played each other this week.
        
        Args:
            team1_id: First team ID
            team2_id: Second team ID
            schedule: Current schedule
            week_num: Current week number
            
        Returns:
            True if teams played this week, False otherwise
        """
        week_games = schedule.get_games_for_week(week_num)
        
        for game in week_games:
            game_teams = {game.get('teamA'), game.get('teamB')}
            if game_teams == {team1_id, team2_id}:
                return True
        
        return False
    
    def _team_has_game_on_date(
        self,
        team_id: int,
        date,
        schedule: Schedule
    ) -> bool:
        """
        Check if a team already has a game on a specific date.
        
        Args:
            team_id: Team ID
            date: Date to check (can be string or date object)
            schedule: Current schedule
            
        Returns:
            True if team has game on this date, False otherwise
        """
        if isinstance(date, str):
            date = datetime.strptime(date, '%Y-%m-%d').date()
        
        for game in schedule.games:
            if game.get('teamA') == team_id or game.get('teamB') == team_id:
                game_date = game.get('date')
                if game_date:
                    if isinstance(game_date, str):
                        game_date = datetime.strptime(game_date, '%Y-%m-%d').date()
                    
                    if game_date == date:
                        return True
        
        return False
    
    def _clear_week_generators(self, week_num: int):
        """Clear all generators for a specific week."""
        keys_to_remove = [key for key in self.division_generators.keys() if key[1] == week_num]
        for key in keys_to_remove:
            del self.division_generators[key]
