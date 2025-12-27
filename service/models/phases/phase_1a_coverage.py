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
        
        Processes teams by fewest total games first, trying to pair each with:
        1. Teams of similar strength who haven't played recently (best)
        2. Teams of similar strength (good)
        3. Any team who hasn't played recently (acceptable)
        4. Any available team (fallback)
        
        Args:
            division_id: Division ID
            teams_in_division: List of team dictionaries
            schedule: Current schedule
            week_num: Current week number
            
        Returns:
            Game dictionary if found, None otherwise
        """
        # Get teams without games this week, sorted by total game count (fewest first)
        teams_without_games = self._get_unscheduled_teams_sorted(
            teams_in_division, schedule, week_num
        )
        
        if len(teams_without_games) < 2:
            return None  # Need at least 2 teams to make a game
        
        # Try to find a game for each team (prioritizing those with fewer total games)
        for i, team1 in enumerate(teams_without_games):
            team1_id = team1['team_id']
            
            # Get remaining teams sorted by similar strength
            remaining_teams = teams_without_games[i+1:]
            opponents_by_strength = self._find_similar_strength_opponents(
                team1, remaining_teams, schedule, week_num
            )
            
            # First pass: Try similar strength teams not played recently (best)
            for team2 in opponents_by_strength:
                team2_id = team2['team_id']
                
                if not self._teams_played_recently(team1_id, team2_id, schedule, week_num, weeks_back=3):
                    game = self._try_to_find_game(team1_id, team2_id, schedule, week_num)
                    if game:
                        logger.info(f"      Found game (similar strength, not recent): {team1['name']} vs {team2['name']}")
                        return game
            
            # Second pass: Try similar strength teams allowing recent play (fallback)
            for team2 in opponents_by_strength:
                team2_id = team2['team_id']
                game = self._try_to_find_game(team1_id, team2_id, schedule, week_num)
                if game:
                    logger.info(f"      Found game (similar strength): {team1['name']} vs {team2['name']}")
                    return game
        
        return None
    
    def _find_similar_strength_opponents(
        self,
        focal_team: Dict,
        available_teams: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> List[Dict]:
        """
        Find opponents sorted by strength similarity to focal team.
        
        Returns opponents sorted by closeness in strength (most similar first).
        
        Args:
            focal_team: Team to find opponents for
            available_teams: List of available opponent teams
            schedule: Current schedule
            week_num: Current week number
            
        Returns:
            List of opponent teams sorted by strength similarity
        """
        focal_strength = self._calculate_team_strength_score(focal_team)
        
        opponent_scores = []
        for team in available_teams:
            team_strength = self._calculate_team_strength_score(team)
            distance = abs(team_strength - focal_strength)
            opponent_scores.append((team, distance))
        
        # Sort by distance (ascending = most similar first)
        opponent_scores.sort(key=lambda x: x[1])
        
        return [team for team, distance in opponent_scores]
    
    def _get_unscheduled_teams_sorted(
        self,
        teams: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> List[Dict]:
        """
        Get teams without games this week, sorted by total game count (fewest first).
        
        Prioritizes teams with fewer total games (previous + scheduled),
        helping teams "catch up" if they haven't played much.
        
        Args:
            teams: List of team dictionaries
            schedule: Current schedule
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
        
        # Calculate total game counts and sort (fewer games = higher priority)
        counted_teams = [(team, self._get_total_game_count(team['team_id'], schedule)) 
                         for team in unscheduled]
        counted_teams.sort(key=lambda x: x[1])  # Sort by game count ascending
        
        return [team for team, count in counted_teams]
    
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
