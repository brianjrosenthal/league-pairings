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
    
    Goal: Maximize TSL utilization while respecting weekly/daily limits (max 2 games/week).
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
        Generator with fair team selection and strength-based matching.
        
        Prioritizes teams with fewer games, matches by similar strength.
        Uses attempt tracking to avoid infinite loops.
        
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
        
        attempted_teams = set()  # Track teams we've tried without success
        
        while True:
            # Select next team fairly (excluding already-attempted)
            focal_team = self._select_next_team_fairly(
                teams_in_division,
                schedule,
                week_num,
                exclude_team_ids=attempted_teams
            )
            
            if not focal_team:
                # No more teams available - generator exhausted
                break
            
            # Find opponents sorted by similar strength
            opponents = self._find_similar_strength_opponents(
                focal_team,
                teams_in_division,
                schedule,
                week_num
            )
            
            if not opponents:
                attempted_teams.add(focal_team['team_id'])
                continue
            
            # Try to schedule game (two passes: avoid recent, then allow)
            game_scheduled = False
            
            # Pass 1: Avoid recent play (soft constraint)
            for opponent in opponents:
                team1_id = focal_team['team_id']
                team2_id = opponent['team_id']
                
                if not self._teams_played_recently(team1_id, team2_id, schedule, week_num, weeks_back=3):
                    game = self._try_to_find_game(team1_id, team2_id, schedule, week_num)
                    if game:
                        yield game
                        game_scheduled = True
                        break
            
            # Pass 2: Allow recent play if necessary
            if not game_scheduled:
                for opponent in opponents:
                    team1_id = focal_team['team_id']
                    team2_id = opponent['team_id']
                    
                    game = self._try_to_find_game(team1_id, team2_id, schedule, week_num)
                    if game:
                        yield game
                        game_scheduled = True
                        break
            
            # If we couldn't schedule this team, exclude it
            if not game_scheduled:
                attempted_teams.add(focal_team['team_id'])
    
    def _get_total_game_count(self, team_id: int, schedule: Schedule) -> int:
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
    
    def _select_next_team_fairly(
        self,
        teams: List[Dict],
        schedule: Schedule,
        week_num: int,
        exclude_team_ids: set = None
    ) -> Optional[Dict]:
        """
        Select next team fairly, prioritizing teams with fewer TOTAL games.
        
        1. Groups teams by TOTAL game count (previous + scheduled so far)
        2. Selects from group with minimum games
        3. Randomly chooses within that group
        4. Excludes already-attempted teams
        5. Still respects weekly game limit
        
        Args:
            teams: List of team dictionaries
            schedule: Current schedule
            week_num: Current week number
            exclude_team_ids: Set of team IDs to exclude
            
        Returns:
            Selected team or None if no teams available
        """
        from collections import defaultdict
        
        exclude_team_ids = exclude_team_ids or set()
        
        # Calculate game counts for available teams
        team_game_counts = []
        for team in teams:
            if team['team_id'] in exclude_team_ids:
                continue
            
            # Check weekly limit (still enforced)
            weekly_game_count = len(schedule.get_team_games_in_week(team['team_id'], week_num))
            if weekly_game_count >= self.max_games_per_week:
                continue
            
            # Use TOTAL game count for prioritization (previous + all scheduled)
            total_game_count = self._get_total_game_count(team['team_id'], schedule)
            team_game_counts.append((team, total_game_count))
        
        if not team_game_counts:
            return None
        
        # Group by TOTAL game count
        by_count = defaultdict(list)
        for team, count in team_game_counts:
            by_count[count].append(team)
        
        # Get teams with minimum TOTAL game count
        min_count = min(by_count.keys())
        candidates = by_count[min_count]
        
        # Randomly select from candidates
        return random.choice(candidates)
    
    def _find_similar_strength_opponents(
        self,
        focal_team: Dict,
        teams: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> List[Dict]:
        """
        Find opponents sorted by strength similarity to focal team.
        
        Returns opponents sorted by closeness in strength (most similar first).
        
        Args:
            focal_team: Team to find opponents for
            teams: List of all teams in division
            schedule: Current schedule
            week_num: Current week number
            
        Returns:
            List of opponent teams sorted by strength similarity
        """
        focal_strength = self._calculate_team_strength_score(focal_team)
        
        opponent_scores = []
        for team in teams:
            # Skip focal team itself
            if team['team_id'] == focal_team['team_id']:
                continue
            
            # Skip teams that have hit weekly limit
            if not self._is_team_available(team['team_id'], schedule, week_num):
                continue
            
            team_strength = self._calculate_team_strength_score(team)
            distance = abs(team_strength - focal_strength)
            opponent_scores.append((team, distance))
        
        # Sort by distance (ascending = most similar first)
        opponent_scores.sort(key=lambda x: x[1])
        
        return [team for team, distance in opponent_scores]
    
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
        - Weekly game limits (max 2 per team per week)
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
        
        # Get team and division preferred locations
        team1 = self.model.team_lookup[team1_id]
        team2 = self.model.team_lookup[team2_id]
        division_id = team1['division_id']  # Both teams in same division
        
        team_pref_locs = set()
        if team1.get('preferred_location_id'):
            team_pref_locs.add(team1['preferred_location_id'])
        if team2.get('preferred_location_id'):
            team_pref_locs.add(team2['preferred_location_id'])
        
        div_pref_locs = self.model.division_preferred_locations.get(division_id, set())
        
        # Categorize TSLs with 6-tier priority
        team_pref_sunday = []
        div_pref_sunday = []
        sunday_any = []
        team_pref_any = []
        div_pref_any = []
        
        for tsl in available_tsls:
            loc_id = tsl['location_id']
            is_sunday = self._is_sunday_tsl(tsl)
            is_team_pref = loc_id in team_pref_locs
            is_div_pref = loc_id in div_pref_locs
            
            if is_team_pref and is_sunday:
                team_pref_sunday.append(tsl)
            elif is_div_pref and is_sunday:
                div_pref_sunday.append(tsl)
            elif is_sunday:
                sunday_any.append(tsl)
            elif is_team_pref:
                team_pref_any.append(tsl)
            elif is_div_pref:
                div_pref_any.append(tsl)
        
        # Choose TSL with cascading priority:
        # 1. Team Preferred location + Sunday (ideal!)
        # 2. Division Preferred location + Sunday
        # 3. Sunday (any location)
        # 4. Team Preferred location (any day)
        # 5. Division Preferred location (any day)
        # 6. Any available TSL (fallback)
        if team_pref_sunday:
            chosen_tsl = random.choice(team_pref_sunday)
        elif div_pref_sunday:
            chosen_tsl = random.choice(div_pref_sunday)
        elif sunday_any:
            chosen_tsl = random.choice(sunday_any)
        elif team_pref_any:
            chosen_tsl = random.choice(team_pref_any)
        elif div_pref_any:
            chosen_tsl = random.choice(div_pref_any)
        else:
            chosen_tsl = random.choice(available_tsls)
        
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
