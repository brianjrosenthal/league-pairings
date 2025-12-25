"""
Phase 1A: Maximum Coverage using Greedy Algorithm

Assigns as many teams as possible to at most one game per week.
Uses a manual greedy algorithm that processes divisions and teams in order.
"""

from typing import Dict, List, Set, Tuple, Optional
from collections import defaultdict
import logging
import random
from datetime import datetime, timedelta

from .base_phase import BasePhase
from .schedule import Schedule

logger = logging.getLogger(__name__)


class Phase1ACoverage(BasePhase):
    """
    Phase 1A: Maximum coverage using greedy algorithm.
    
    Goal: Maximize number of teams that play at least once per week.
    Constraint: Each team plays at most once per week.
    Priority: Strongest teams first, prefer matchups not played recently.
    """
    
    def get_phase_name(self) -> str:
        return "Phase 1A: Maximum Coverage"
    
    def schedule(
        self,
        schedule: Schedule,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> Schedule:
        """
        Execute Phase 1A scheduling using greedy algorithm.
        
        Args:
            schedule: Current schedule with constraint enforcement
            feasible_games: All feasible games (ignored - we build our own)
            week_num: Current week number
            timeout: Time limit (ignored for deterministic algorithm)
            
        Returns:
            Updated schedule with Phase 1A games added
        """
        logger.info(f"Phase 1A Week {week_num}: Starting greedy scheduling (round-robin)")
        
        # Round-robin: schedule one game per division per round
        total_games_scheduled = 0
        round_num = 0
        
        while True:
            round_num += 1
            games_this_round = 0
            
            logger.info(f"\n  Round {round_num}:")
            
            # Try to schedule one game for each division
            for division in self.model.divisions:
                division_id = division['id']
                division_name = division['name']
                
                teams_in_division = self.model.teams_by_division.get(division_id, [])
                if not teams_in_division:
                    continue
                
                # Try to find one game for this division
                game = self._find_next_game_for_division(
                    division_id, teams_in_division, schedule, week_num
                )
                
                if game:
                    if schedule.add_game(game):
                        logger.info(f"    ✓ {division_name}: {game['teamA_name']} vs {game['teamB_name']}")
                        games_this_round += 1
                        total_games_scheduled += 1
                    else:
                        logger.warning(f"    ✗ Game rejected by constraints: {game['teamA_name']} vs {game['teamB_name']}")
            
            # If no games were scheduled in this round, we're done
            if games_this_round == 0:
                logger.info(f"  No games scheduled in round {round_num}, stopping")
                break
            
            logger.info(f"  Round {round_num} total: {games_this_round} games")
        
        games_added = len(schedule.get_games_for_week(week_num))
        logger.info(f"\nPhase 1A Week {week_num}: Scheduled {games_added} games")
        
        return schedule
    
    def _find_next_game_for_division(
        self,
        division_id: int,
        teams_in_division: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> Optional[Dict]:
        """
        Find the next game for a division.
        
        Processes teams strongest to weakest, trying to pair each with:
        1. Teams not played in last 3 weeks (if possible)
        2. Any available team (fallback)
        
        Args:
            division_id: Division ID
            teams_in_division: List of team dictionaries
            state: Current scheduling state
            week_num: Current week number
            
        Returns:
            Game dictionary if found, None otherwise
        """
        # Get teams without games this week, sorted by strength
        teams_without_games = self._get_unscheduled_teams_sorted(
            teams_in_division, schedule, week_num
        )
        
        if len(teams_without_games) < 2:
            return None  # Need at least 2 teams to make a game
        
        # Try to find a game for each team
        for i, team1 in enumerate(teams_without_games):
            team1_id = team1['team_id']
            
            # First pass: Try teams not played recently (last 3 weeks)
            for team2 in teams_without_games[i+1:]:
                team2_id = team2['team_id']
                
                if not self._teams_played_recently(team1_id, team2_id, schedule, week_num, weeks_back=3):
                    game = self._try_to_find_game(team1_id, team2_id, schedule, week_num)
                    if game:
                        logger.info(f"      Found game (recent check passed): {team1['name']} vs {team2['name']}")
                        return game
            
            # Second pass: Try any team (ignore recent play constraint)
            for team2 in teams_without_games[i+1:]:
                team2_id = team2['team_id']
                game = self._try_to_find_game(team1_id, team2_id, schedule, week_num)
                if game:
                    logger.info(f"      Found game (fallback): {team1['name']} vs {team2['name']}")
                    return game
        
        return None
    
    def _get_unscheduled_teams_sorted(
        self,
        teams: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> List[Dict]:
        """
        Get teams without games this week, sorted by strength (strongest first).
        
        Args:
            teams: List of team dictionaries
            state: Current scheduling state
            week_num: Current week number
            
        Returns:
            Sorted list of team dictionaries
        """
        # Find teams already scheduled this week
        scheduled_team_ids = set()
        for game in schedule.get_games_for_week(week_num):
            scheduled_team_ids.add(game['teamA'])
            scheduled_team_ids.add(game['teamB'])
        
        # Filter to unscheduled teams
        unscheduled = [t for t in teams if t['team_id'] not in scheduled_team_ids]
        
        # Calculate strength scores and sort (lower score = stronger)
        scored_teams = [(team, self._calculate_team_strength_score(team)) for team in unscheduled]
        scored_teams.sort(key=lambda x: x[1])  # Sort by score ascending
        
        return [team for team, score in scored_teams]
    
    def _calculate_team_strength_score(self, team: Dict) -> float:
        """
        Calculate team strength score (lower = stronger).
        
        Score = previous_year_ranking - wins + losses
        
        Args:
            team: Team dictionary
            
        Returns:
            Strength score (lower is better)
        """
        team_id = team['team_id']
        
        # Start with previous year ranking (1 = best)
        score = team.get('previous_year_ranking', 999) or 999
        
        # Adjust based on wins and losses from previous games
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
        
        # Subtract 1 for each win, add 1 for each loss
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
        # Note: We need to map previous game dates to week numbers
        for game in self.model.previous_games:
            if self._game_involves_both_teams(game, team1_id, team2_id):
                # Try to determine which week this game was in
                game_date = game.get('date')
                if game_date:
                    # Find corresponding week number
                    # This is approximate - we look for how many weeks back from current week
                    if isinstance(game_date, str):
                        game_date = datetime.strptime(game_date, '%Y-%m-%d').date()
                    
                    # Get current week's start date
                    if self.model.week_mapping:
                        # Find a timeslot in current week to get week dates
                        for ts_id, (week, week_start, week_end) in self.model.week_mapping.items():
                            if week == current_week:
                                # Calculate how many weeks ago this game was
                                days_diff = (week_start - game_date).days
                                weeks_ago = days_diff // 7
                                
                                if 0 < weeks_ago <= weeks_back:
                                    return True
                                break
        
        # Check currently scheduled games in this run
        for game in schedule.games:
            if self._game_involves_both_teams(game, team1_id, team2_id):
                return True
        
        return False
    
    def _game_involves_both_teams(self, game: Dict, team1_id: int, team2_id: int) -> bool:
        """Check if a game involves both specified teams."""
        game_teams = set()
        
        # Handle different game dictionary formats
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
            
            # Check if timeslot is available for both teams
            if timeslot_id not in common_timeslots:
                continue
            
            # Check if TSL is in current week
            if timeslot_id not in self.model.week_mapping:
                continue
            
            tsl_week = self.model.week_mapping[timeslot_id][0]
            if tsl_week != week_num:
                continue
            
            # Check if TSL is already used
            if schedule.is_tsl_used(tsl_id):
                continue
            
            available_tsls.append(tsl)
        
        if not available_tsls:
            return None
        
        # Get team preferred locations
        team1 = self.model.team_lookup[team1_id]
        team2 = self.model.team_lookup[team2_id]
        team1_pref_loc = team1.get('preferred_location_id')
        team2_pref_loc = team2.get('preferred_location_id')
        
        # Build preferred location set
        preferred_location_ids = set()
        if team1_pref_loc:
            preferred_location_ids.add(team1_pref_loc)
        if team2_pref_loc:
            preferred_location_ids.add(team2_pref_loc)
        
        # Categorize TSLs by priority
        preferred_sunday_tsls = []
        preferred_tsls = []
        sunday_tsls = []
        
        for tsl in available_tsls:
            is_preferred = tsl['location_id'] in preferred_location_ids
            is_sunday = self._is_sunday_tsl(tsl)
            
            if is_preferred and is_sunday:
                preferred_sunday_tsls.append(tsl)
            elif is_preferred:
                preferred_tsls.append(tsl)
            elif is_sunday:
                sunday_tsls.append(tsl)
        
        # Choose TSL with cascading priority:
        # 1. Preferred location + Sunday (best!)
        # 2. Preferred location (any day)
        # 3. Sunday (any location)
        # 4. Any available TSL (fallback)
        if preferred_sunday_tsls:
            chosen_tsl = random.choice(preferred_sunday_tsls)
        elif preferred_tsls:
            chosen_tsl = random.choice(preferred_tsls)
        elif sunday_tsls:
            chosen_tsl = random.choice(sunday_tsls)
        else:
            chosen_tsl = random.choice(available_tsls)
        
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
