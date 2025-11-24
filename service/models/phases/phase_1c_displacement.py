"""
Phase 1C: Strategic Displacement

Tries to swap unscheduled teams into existing games by displacing a scheduled team.
Only performs swaps if the displaced team can be immediately rescheduled.
"""

from typing import Dict, List, Optional, Tuple
import logging
import random
from datetime import datetime

from .base_phase import BasePhase
from .schedule import Schedule

logger = logging.getLogger(__name__)


class Phase1CDisplacement(BasePhase):
    """
    Phase 1C: Strategic displacement for unscheduled teams.
    
    Goal: Schedule unscheduled teams by substituting them into existing games.
    Method: For each unscheduled team, try swapping with teams in existing games,
            but only if the displaced team can be rescheduled.
    """
    
    def get_phase_name(self) -> str:
        return "Phase 1C: Strategic Displacement"
    
    def schedule(
        self,
        schedule: Schedule,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> Schedule:
        """
        Execute Phase 1C scheduling using team substitution.
        
        Args:
            schedule: Current schedule with constraint enforcement
            feasible_games: All feasible games (ignored - we build our own)
            week_num: Current week number
            timeout: Time limit (ignored for deterministic algorithm)
            
        Returns:
            Updated schedule with Phase 1C games added/modified
        """
        logger.info(f"Phase 1C Week {week_num}: Starting strategic displacement")
        
        initial_game_count = len(schedule.get_games_for_week(week_num))
        
        # Process each division
        for division in self.model.divisions:
            division_id = division['id']
            division_name = division['name']
            
            teams_in_division = self.model.teams_by_division.get(division_id, [])
            if not teams_in_division:
                continue
            
            # Get unscheduled teams for this week
            unscheduled_teams = self._get_unscheduled_teams_for_week(
                teams_in_division, schedule, week_num
            )
            
            if not unscheduled_teams:
                continue
            
            logger.info(f"\n  {division_name}: {len(unscheduled_teams)} unscheduled teams to try swapping")
            
            # Try to substitute each unscheduled team
            for unscheduled_team in unscheduled_teams:
                swap_result = self._try_team_substitution(
                    unscheduled_team, division_id, teams_in_division, schedule, week_num
                )
                
                if swap_result:
                    original_game, modified_game, new_game, displaced_team_name, new_partner_name = swap_result
                    schedule = self._apply_swap(schedule, original_game, modified_game, new_game)
                    logger.info(f"    ✓ Swapped {unscheduled_team['name']} in, rescheduled {displaced_team_name} with {new_partner_name}")
        
        games_after = len(schedule.get_games_for_week(week_num))
        net_change = games_after - initial_game_count
        
        logger.info(f"\nPhase 1C Week {week_num}: Net change {net_change} games (via substitution)")
        
        return schedule
    
    def _get_unscheduled_teams_for_week(
        self,
        teams: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> List[Dict]:
        """Get teams that have no games scheduled this week."""
        scheduled_team_ids = set()
        
        for game in schedule.get_games_for_week(week_num):
            scheduled_team_ids.add(game['teamA'])
            scheduled_team_ids.add(game['teamB'])
        
        return [t for t in teams if t['team_id'] not in scheduled_team_ids]
    
    def _try_team_substitution(
        self,
        unscheduled_team: Dict,
        division_id: int,
        teams_in_division: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> Optional[Tuple[Dict, Dict, Dict, str, str]]:
        """
        Try to substitute unscheduled team into an existing game.
        
        Returns tuple of (original_game, modified_game, new_game, displaced_team_name, new_partner_name) if successful.
        """
        unscheduled_team_id = unscheduled_team['team_id']
        
        # Get existing games for this division this week
        existing_games = self._get_division_games_this_week(division_id, schedule, week_num)
        
        if not existing_games:
            return None
        
        # Try substituting unscheduled_team for each team in each game
        for game in existing_games:
            team1_id = game['teamA']
            team2_id = game['teamB']
            
            # Try swapping unscheduled_team for team1
            result = self._try_swap(unscheduled_team, team1_id, team2_id, game, 
                                   teams_in_division, schedule, week_num)
            if result:
                return result
            
            # Try swapping unscheduled_team for team2
            result = self._try_swap(unscheduled_team, team2_id, team1_id, game,
                                   teams_in_division, schedule, week_num)
            if result:
                return result
        
        return None
    
    def _try_swap(
        self,
        unscheduled_team: Dict,
        displaced_team_id: int,
        remaining_team_id: int,
        original_game: Dict,
        teams_in_division: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> Optional[Tuple[Dict, Dict, Dict, str, str]]:
        """
        Try swapping unscheduled_team for displaced_team in original_game.
        
        Returns (original_game, modified_game, new_game, displaced_team_name, new_partner_name) if successful.
        """
        unscheduled_team_id = unscheduled_team['team_id']
        
        # Check if unscheduled_team is available for this game's TSL
        game_timeslot_id = original_game['timeslot_id']
        if game_timeslot_id not in self.model.team_availability.get(unscheduled_team_id, set()):
            return None
        
        # Try to find a new game for the displaced team
        # Exclude unscheduled_team and remaining_team from potential partners
        excluded_teams = {unscheduled_team_id, remaining_team_id}
        potential_partners = [t for t in teams_in_division if t['team_id'] not in excluded_teams]
        
        # Filter to teams with < 3 games this week
        available_partners = self._filter_teams_under_weekly_limit(
            potential_partners, schedule, week_num
        )
        
        if not available_partners:
            return None
        
        # Try to find a new game for displaced team
        for partner_team in available_partners:
            partner_team_id = partner_team['team_id']
            
            # Check if teams played recently
            if self._teams_played_recently(displaced_team_id, partner_team_id, schedule, week_num, weeks_back=3):
                continue
            
            new_game = self._try_to_find_game(displaced_team_id, partner_team_id, schedule, week_num)
            if new_game:
                # Create the modified game (unscheduled_team replaces displaced_team)
                modified_game = original_game.copy()
                if original_game['teamA'] == displaced_team_id:
                    modified_game['teamA'] = unscheduled_team_id
                    modified_game['teamA_name'] = unscheduled_team['name']
                else:
                    modified_game['teamB'] = unscheduled_team_id
                    modified_game['teamB_name'] = unscheduled_team['name']
                
                displaced_team = self.model.team_lookup[displaced_team_id]
                return (original_game, modified_game, new_game, displaced_team['name'], partner_team['name'])
        
        return None
    
    def _apply_swap(
        self,
        schedule: Schedule,
        original_game: Dict,
        modified_game: Dict,
        new_game: Dict
    ) -> Schedule:
        """
        Apply a team swap using Schedule's remove and add methods.
        
        1. Remove original game
        2. Add modified game (with substituted team)
        3. Add new game (for displaced team)
        """
        # Remove the original game
        if not schedule.remove_game(original_game):
            logger.warning(f"Failed to remove original game during swap")
            return schedule
        
        # Add the modified game
        if not schedule.add_game(modified_game):
            logger.warning(f"Failed to add modified game during swap, rolling back")
            schedule.add_game(original_game)  # Try to restore
            return schedule
        
        # Add the new game for displaced team
        if not schedule.add_game(new_game):
            logger.warning(f"Failed to add new game during swap, rolling back")
            schedule.remove_game(modified_game)
            schedule.add_game(original_game)  # Try to restore
            return schedule
        
        return schedule
    
    def _get_division_games_this_week(
        self,
        division_id: int,
        schedule: Schedule,
        week_num: int
    ) -> List[Dict]:
        """Get all games for a specific division this week."""
        games = []
        for game in schedule.get_games_for_week(week_num):
            if game.get('division_id') == division_id:
                games.append(game)
        return games
    
    def _filter_teams_under_weekly_limit(
        self,
        teams: List[Dict],
        schedule: Schedule,
        week_num: int
    ) -> List[Dict]:
        """Filter teams to only those with < max_games_per_week this week."""
        available = []
        for team in teams:
            team_games = schedule.get_team_games_in_week(team['team_id'], week_num)
            if len(team_games) < self.max_games_per_week:
                available.append(team)
        return available
    
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
        """Try to find a valid game between two teams."""
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
