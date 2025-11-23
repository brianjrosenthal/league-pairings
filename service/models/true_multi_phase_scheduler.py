"""
True Multi-Phase Scheduler

Phase 1A: Pure Coverage (OR-Tools CP-SAT)
- Maximizes number of teams that play (ignores weights)
- Pure coverage optimization
- Allocates ~10% of timeout

Phase 1B: Strategic Displacement
- For unscheduled teams, try swapping with scheduled teams
- Targeted within-division swaps
- Allocates ~10% of timeout

Phase 1C: Optimal Remaining Coverage (OR-Tools CP-SAT)
- Schedules remaining unscheduled teams optimally
- Uses weights for quality
- Allocates ~10% of timeout

Phase 2: Greedy Capacity Filling
- Fills remaining capacity up to 3 games/week, 1 game/day
- Fast greedy algorithm
- Allocates ~70% of timeout
"""

from typing import Dict, List, Set, Tuple
from collections import defaultdict
import logging

from .data_model import DataModel

logger = logging.getLogger(__name__)


class TrueMultiPhaseScheduler:
    """
    Two-phase scheduler: Coverage optimization + Greedy capacity filling.
    """
    
    def __init__(self, model: DataModel, config: Dict, timeout: int = 120):
        """
        Initialize multi-phase scheduler.
        
        Args:
            model: DataModel with week/day mappings
            config: Scheduling configuration
            timeout: Maximum time in seconds for solver
        """
        self.model = model
        self.config = config
        self.timeout = timeout
        
        # Extract config parameters
        self.min_games_per_week = config.get('min_games_per_week', 1)
        self.max_games_per_week = config.get('max_games_per_week', 3)
        self.max_games_per_day = config.get('max_games_per_day', 1)
        
        # Track scheduled games and TSL usage
        self.scheduled_games = []
        self.used_tsls = set()
        self.team_weekly_games = defaultdict(lambda: defaultdict(int))  # {week: {team: count}}
        self.team_daily_games = defaultdict(lambda: defaultdict(int))   # {day: {team: count}}
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Generate schedule using week-by-week three-phase approach.
        Each week runs all three phases, with cross-week memory.
        
        Args:
            feasible_games: List of feasible games (one per team pairing)
            
        Returns:
            Selected games forming optimal schedule
        """
        if not feasible_games:
            raise RuntimeError("No feasible games provided")
        
        logger.info(f"True Multi-Phase: Starting with {len(feasible_games)} feasible games")
        
        # Group games by week (using first available TSL's week)
        games_by_week = defaultdict(list)
        for game in feasible_games:
            available_tsls = game.get('available_tsls', [game])
            if available_tsls:
                # Use first available TSL to determine week
                first_tsl = available_tsls[0]
                timeslot_id = first_tsl.get('timeslot_id')
                if timeslot_id and timeslot_id in self.model.week_mapping:
                    week_num = self.model.week_mapping[timeslot_id][0]
                    games_by_week[week_num].append(game)
        
        if not games_by_week:
            logger.warning("No games could be mapped to weeks")
            return []
        
        all_weeks = sorted(games_by_week.keys())
        logger.info(f"Scheduling across {len(all_weeks)} weeks: {all_weeks}")
        
        # Track games scheduled THIS RUN for cross-week memory
        self.games_this_run = defaultdict(int)  # {team_id: count}
        
        # Allocate time per week
        time_per_week = self.timeout / len(all_weeks) if all_weeks else self.timeout
        
        all_scheduled_games = []
        
        for week_num in all_weeks:
            week_games = games_by_week[week_num]
            logger.info(f"\n=== WEEK {week_num} ({len(week_games)} candidate games) ===")
            
            # Phase 1A: Pure coverage (10% of week time)
            phase1a_timeout = int(time_per_week * 0.10)
            logger.info(f"Phase 1A: Pure Coverage ({phase1a_timeout}s)")
            phase1a_games = self._phase1a_pure_coverage(week_games, phase1a_timeout)
            all_scheduled_games.extend(phase1a_games)
            
            logger.info(f"Phase 1A: Scheduled {len(phase1a_games)} games")
            
            # Phase 1B: Strategic displacement (10% of week time)
            phase1b_timeout = int(time_per_week * 0.10)
            logger.info(f"Phase 1B: Strategic Displacement ({phase1b_timeout}s)")
            phase1b_games = self._phase1b_strategic_displacement(week_num, week_games, phase1b_timeout)
            all_scheduled_games.extend(phase1b_games)
            
            logger.info(f"Phase 1B: Scheduled {len(phase1b_games)} games via displacement")
            
            # Phase 1C: Optimal remaining (10% of week time)
            phase1c_timeout = int(time_per_week * 0.10)
            logger.info(f"Phase 1C: Optimal Remaining ({phase1c_timeout}s)")
            phase1c_games = self._phase1c_optimal_remaining(week_games, phase1c_timeout)
            all_scheduled_games.extend(phase1c_games)
            
            logger.info(f"Phase 1C: Scheduled {len(phase1c_games)} additional games")
            
            # Diagnose unscheduled teams after all Phase 1 stages
            self._diagnose_unscheduled_teams(week_num, week_games)
            
            # Phase 2: Greedy filling (70% of week time)
            phase2_timeout = int(time_per_week * 0.70)
            logger.info(f"Phase 2: Greedy Filling ({phase2_timeout}s)")
            phase2_games = self._phase2_greedy_filling(week_games, phase2_timeout)
            all_scheduled_games.extend(phase2_games)
            
            logger.info(f"Phase 2: Scheduled {len(phase2_games)} additional games")
            logger.info(f"Week {week_num} total: {len(phase1a_games) + len(phase1b_games) + len(phase1c_games) + len(phase2_games)} games")
        
        logger.info(f"\nTotal games scheduled: {len(all_scheduled_games)}")
        return all_scheduled_games
    
    def _phase1a_pure_coverage(
        self, 
        feasible_games: List[Dict],
        timeout: int
    ) -> List[Dict]:
        """
        Phase 1A: Pure coverage optimization - maximize teams that play.
        Ignores all game weights, only cares about covering teams.
        
        Args:
            feasible_games: All feasible games
            timeout: Time limit for this phase
            
        Returns:
            Games selected for pure coverage
        """
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            raise RuntimeError("OR-Tools not installed")
        
        # DEBUG: Analyze feasible games
        all_teams = set()
        for game in feasible_games:
            all_teams.add(game['teamA'])
            all_teams.add(game['teamB'])
        
        logger.info(f"Phase 1A: {len(feasible_games)} feasible games for {len(all_teams)} teams")
        
        # DEBUG: Log team availability
        team_game_count = defaultdict(int)
        team_tsl_options = defaultdict(set)
        for game in feasible_games:
            team_game_count[game['teamA']] += 1
            team_game_count[game['teamB']] += 1
            for tsl in game.get('available_tsls', [game]):
                team_tsl_options[game['teamA']].add(tsl.get('tsl_id'))
                team_tsl_options[game['teamB']].add(tsl.get('tsl_id'))
        
        for team_id in sorted(all_teams):
            games_this_run = self.games_this_run.get(team_id, 0)
            logger.info(f"  Team {team_id}: {team_game_count[team_id]} feasible games, "
                       f"{len(team_tsl_options[team_id])} TSL options, "
                       f"{games_this_run} games this run")
        
        model = cp_model.CpModel()
        
        # Decision variables
        game_vars = {}
        for i, game in enumerate(feasible_games):
            game_vars[i] = model.NewBoolVar(f'game_{i}')
        
        # Constraint: Each team plays at most once in this phase
        team_games = defaultdict(list)
        for i, game in enumerate(feasible_games):
            team_games[game['teamA']].append(i)
            team_games[game['teamB']].append(i)
        
        for team_id, game_indices in team_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
        # Constraint: Respect weekly game limits
        # Group games by week for each team
        team_week_games = defaultdict(lambda: defaultdict(list))
        for i, game in enumerate(feasible_games):
            for tsl in game.get('available_tsls', [game]):
                timeslot_id = tsl.get('timeslot_id')
                if timeslot_id and timeslot_id in self.model.week_mapping:
                    week_num = self.model.week_mapping[timeslot_id][0]
                    team_week_games[game['teamA']][week_num].append(i)
                    team_week_games[game['teamB']][week_num].append(i)
        
        # For each team and week, limit games
        for team_id in team_games.keys():
            for week_num, game_indices in team_week_games[team_id].items():
                # Current games already scheduled for this team in this week
                current_count = self.team_weekly_games[week_num].get(team_id, 0)
                remaining_capacity = self.max_games_per_week - current_count
                
                if remaining_capacity > 0:
                    # Team can play at most remaining_capacity more games this week
                    model.Add(sum(game_vars[i] for i in game_indices) <= remaining_capacity)
        
        # TSL assignment: Expand games to consider all available TSLs
        # For each game, we need to choose which TSL to use
        tsl_assignment = {}
        tsl_usage = defaultdict(list)
        
        for i, game in enumerate(feasible_games):
            available_tsls = game.get('available_tsls', [game])  # Fallback to single TSL
            
            # Create variables for each TSL option for this game
            tsl_vars = {}
            for tsl in available_tsls:
                tsl_id = tsl['tsl_id']
                var_name = f'game_{i}_tsl_{tsl_id}'
                tsl_var = model.NewBoolVar(var_name)
                tsl_vars[tsl_id] = tsl_var
                tsl_usage[tsl_id].append((i, tsl_var))
            
            tsl_assignment[i] = (tsl_vars, available_tsls)
            
            # If game is selected, exactly one TSL must be chosen
            model.Add(sum(tsl_vars.values()) == 1).OnlyEnforceIf(game_vars[i])
            model.Add(sum(tsl_vars.values()) == 0).OnlyEnforceIf(game_vars[i].Not())
        
        # Constraint: Each TSL used at most once
        for tsl_id, assignments in tsl_usage.items():
            model.Add(sum(var for _, var in assignments) <= 1)
        
        # Objective: Maximize coverage (teams that play)
        team_coverage = {}
        for team_id, game_indices in team_games.items():
            coverage_var = model.NewBoolVar(f'coverage_t{team_id}')
            team_coverage[team_id] = coverage_var
            
            # Coverage = 1 if team plays at least once
            model.Add(sum(game_vars[i] for i in game_indices) >= 1).OnlyEnforceIf(coverage_var)
            model.Add(sum(game_vars[i] for i in game_indices) == 0).OnlyEnforceIf(coverage_var.Not())
        
        # Maximize: Team coverage with priority for teams that haven't played THIS RUN
        objective_terms = []
        
        # Prioritize teams based on games THIS RUN
        for team_id, coverage_var in team_coverage.items():
            games_this_run = self.games_this_run.get(team_id, 0)
            
            if games_this_run == 0:
                # Never played in this run = highest priority
                objective_terms.append(1000 * coverage_var)
            else:
                # Already played = lower priority (but still try to cover)
                objective_terms.append(coverage_var)
        
        model.Maximize(sum(objective_terms))
        
        # Solve
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = timeout
        solver.parameters.log_search_progress = False
        
        status = solver.Solve(model)
        
        if status not in [cp_model.OPTIMAL, cp_model.FEASIBLE]:
            logger.warning(f"Phase 1A failed with status: {solver.StatusName(status)}")
            return []
        
        # DEBUG: Analyze solution
        scheduled_teams = set()
        unscheduled_teams = set(all_teams)
        used_tsls_in_solution = set()
        
        logger.info(f"Phase 1A Solver: {solver.StatusName(status)}, "
                   f"Objective: {solver.ObjectiveValue()}, "
                   f"Time: {solver.WallTime()}s")
        
        # Extract solution
        selected_games = []
        for i, game in enumerate(feasible_games):
            if solver.Value(game_vars[i]) == 1:
                # Find which TSL was assigned
                tsl_vars, available_tsls = tsl_assignment[i]
                assigned_tsl = None
                
                for tsl in available_tsls:
                    tsl_id = tsl['tsl_id']
                    if tsl_id in tsl_vars and solver.Value(tsl_vars[tsl_id]) == 1:
                        assigned_tsl = tsl
                        break
                
                if assigned_tsl:
                    # Update game with assigned TSL
                    game_copy = game.copy()
                    game_copy['timeslot_id'] = assigned_tsl['timeslot_id']
                    game_copy['location_id'] = assigned_tsl['location_id']
                    game_copy['location_name'] = assigned_tsl['location_name']
                    game_copy['tsl_id'] = assigned_tsl['tsl_id']
                    game_copy['date'] = assigned_tsl['date']
                    game_copy['modifier'] = assigned_tsl['modifier']
                    
                    selected_games.append(game_copy)
                    
                    # DEBUG: Track which teams were scheduled
                    scheduled_teams.add(game['teamA'])
                    scheduled_teams.add(game['teamB'])
                    unscheduled_teams.discard(game['teamA'])
                    unscheduled_teams.discard(game['teamB'])
                    used_tsls_in_solution.add(assigned_tsl['tsl_id'])
                    
                    # Track usage
                    self.used_tsls.add(assigned_tsl['tsl_id'])
                    self._track_game(game_copy)
        
        # DEBUG: Report unscheduled teams and analyze why
        if unscheduled_teams:
            logger.warning(f"Phase 1A left {len(unscheduled_teams)} teams unscheduled: {sorted(unscheduled_teams)}")
            
            # For each unscheduled team, analyze what went wrong
            for team_id in sorted(unscheduled_teams):
                team_feasible_games = [g for g in feasible_games if g['teamA'] == team_id or g['teamB'] == team_id]
                logger.info(f"  Team {team_id} had {len(team_feasible_games)} feasible games")
                
                # Check if TSLs for their games were taken
                team_tsls = set()
                for game in team_feasible_games:
                    for tsl in game.get('available_tsls', [game]):
                        team_tsls.add(tsl['tsl_id'])
                
                taken_tsls = team_tsls & used_tsls_in_solution
                logger.info(f"    {len(taken_tsls)} of their {len(team_tsls)} TSL options were used by other teams")
        else:
            logger.info("Phase 1A: All teams scheduled successfully!")
        
        return selected_games
    
    def _phase1b_strategic_displacement(
        self,
        week_num: int,
        feasible_games: List[Dict],
        timeout: int
    ) -> List[Dict]:
        """
        Phase 1B: Strategic displacement - try to place unscheduled teams by swapping.
        
        For each unscheduled team A:
        - Find scheduled teams in same division that occupy TSLs where A is available
        - Try swapping A with team B (remove B's game, schedule A vs B's opponent)
        - Check if B can be immediately rescheduled elsewhere
        - If yes, make the swap; if no, try next option
        
        Args:
            week_num: Current week number
            feasible_games: All feasible games for this week
            timeout: Time limit for this phase
            
        Returns:
            Games scheduled via displacement
        """
        import sys
        import time
        start_time = time.time()
        
        # Get teams scheduled this week
        scheduled_this_week = set()
        for game in self.scheduled_games:
            if game['timeslot_id'] in self.model.week_mapping:
                game_week = self.model.week_mapping[game['timeslot_id']][0]
                if game_week == week_num:
                    scheduled_this_week.add(game['teamA'])
                    scheduled_this_week.add(game['teamB'])
        
        # Get unscheduled teams
        all_teams = set()
        for game in feasible_games:
            all_teams.add(game['teamA'])
            all_teams.add(game['teamB'])
        
        unscheduled = all_teams - scheduled_this_week
        
        if not unscheduled:
            logger.info("Phase 1B: No unscheduled teams to displace")
            return []
        
        logger.info(f"Phase 1B: Attempting displacement for {len(unscheduled)} unscheduled teams")
        sys.stderr.write(f"\n--- Phase 1B: Strategic Displacement ---\n")
        sys.stderr.flush()
        
        displaced_games = []
        
        for team_a_id in sorted(unscheduled):
            if time.time() - start_time > timeout:
                logger.info("Phase 1B: Timeout reached")
                break
            
            team_a_name = self.model.get_team_name(team_a_id)
            
            # Get team A's division
            team_a_division = None
            for game in feasible_games:
                if game['teamA'] == team_a_id or game['teamB'] == team_a_id:
                    team_a_division = game['division_id']
                    break
            
            if team_a_division is None:
                continue
            
            # Get team A's available TSLs for this week
            team_a_tsls = set()
            for game in feasible_games:
                if game['teamA'] == team_a_id or game['teamB'] == team_a_id:
                    for tsl in game.get('available_tsls', [game]):
                        timeslot_id = tsl.get('timeslot_id')
                        if timeslot_id and timeslot_id in self.model.week_mapping:
                            tsl_week = self.model.week_mapping[timeslot_id][0]
                            if tsl_week == week_num:
                                team_a_tsls.add((tsl['tsl_id'], timeslot_id))
            
            # Find scheduled games in same division at TSLs where A is available
            displacement_candidates = []
            for game in self.scheduled_games:
                if game['division_id'] != team_a_division:
                    continue
                
                game_week = self.model.week_mapping.get(game['timeslot_id'], [None])[0]
                if game_week != week_num:
                    continue
                
                # Check if this TSL is where team A is available
                tsl_pair = (game['tsl_id'], game['timeslot_id'])
                if tsl_pair in team_a_tsls:
                    displacement_candidates.append(game)
            
            if not displacement_candidates:
                continue
            
            sys.stderr.write(f"\nTeam {team_a_id} ({team_a_name}): Trying {len(displacement_candidates)} displacement options...\n")
            sys.stderr.flush()
            
            # Try each displacement candidate
            for candidate_game in displacement_candidates:
                team_b_id = candidate_game['teamA'] if candidate_game['teamA'] != team_a_id else candidate_game['teamB']
                opponent_id = candidate_game['teamB'] if candidate_game['teamA'] != team_b_id else candidate_game['teamA']
                team_b_name = self.model.get_team_name(team_b_id)
                opponent_name = self.model.get_team_name(opponent_id)
                
                # Step 1: Check if we can schedule A vs opponent at candidate's TSL
                if not self._can_schedule_game(team_a_id, opponent_id, candidate_game['timeslot_id']):
                    continue
                
                # Step 2: Find if team B can be rescheduled elsewhere
                team_b_reschedule = None
                for game in feasible_games:
                    if game['teamA'] != team_b_id and game['teamB'] != team_b_id:
                        continue
                    
                    other_team = game['teamB'] if game['teamA'] == team_b_id else game['teamA']
                    
                    # Try each available TSL for this game
                    for tsl in game.get('available_tsls', [game]):
                        timeslot_id = tsl.get('timeslot_id')
                        tsl_id = tsl.get('tsl_id')
                        
                        # Skip if TSL already used
                        if tsl_id in self.used_tsls:
                            continue
                        
                        # Check if this TSL is in the right week
                        if timeslot_id not in self.model.week_mapping:
                            continue
                        tsl_week = self.model.week_mapping[timeslot_id][0]
                        if tsl_week != week_num:
                            continue
                        
                        # Check constraints for rescheduling B
                        if self._can_schedule_game(team_b_id, other_team, timeslot_id):
                            team_b_reschedule = {
                                'game': game,
                                'tsl': tsl,
                                'opponent': other_team
                            }
                            break
                    
                    if team_b_reschedule:
                        break
                
                if not team_b_reschedule:
                    continue
                
                # SUCCESS! We can make the swap
                sys.stderr.write(f"  ✓ SWAP: Removing {team_b_name} vs {opponent_name}, ")
                sys.stderr.write(f"scheduling {team_a_name} vs {opponent_name}, ")
                sys.stderr.write(f"rescheduling {team_b_name} vs {self.model.get_team_name(team_b_reschedule['opponent'])}\n")
                sys.stderr.flush()
                
                # Remove the old game
                self.scheduled_games.remove(candidate_game)
                self.used_tsls.remove(candidate_game['tsl_id'])
                self._untrack_game(candidate_game)
                
                # Schedule team A vs opponent at the freed TSL
                new_game_a = {
                    'teamA': team_a_id,
                    'teamB': opponent_id,
                    'teamA_name': team_a_name,
                    'teamB_name': opponent_name,
                    'division_id': team_a_division,
                    'division_name': self.model.get_division_name(team_a_division),
                    'timeslot_id': candidate_game['timeslot_id'],
                    'location_id': candidate_game['location_id'],
                    'location_name': candidate_game['location_name'],
                    'tsl_id': candidate_game['tsl_id'],
                    'date': candidate_game['date'],
                    'modifier': candidate_game['modifier'],
                    'weight': None
                }
                
                displaced_games.append(new_game_a)
                self.used_tsls.add(new_game_a['tsl_id'])
                self._track_game(new_game_a)
                
                # Schedule team B's new game
                reschedule_tsl = team_b_reschedule['tsl']
                new_game_b = team_b_reschedule['game'].copy()
                new_game_b['timeslot_id'] = reschedule_tsl['timeslot_id']
                new_game_b['location_id'] = reschedule_tsl['location_id']
                new_game_b['location_name'] = reschedule_tsl['location_name']
                new_game_b['tsl_id'] = reschedule_tsl['tsl_id']
                new_game_b['date'] = reschedule_tsl['date']
                new_game_b['modifier'] = reschedule_tsl['modifier']
                
                displaced_games.append(new_game_b)
                self.used_tsls.add(new_game_b['tsl_id'])
                self._track_game(new_game_b)
                
                # Team A is now scheduled, move to next unscheduled team
                break
        
        sys.stderr.write(f"\nPhase 1B: Successfully displaced {len(displaced_games)} games\n\n")
        sys.stderr.flush()
        
        return displaced_games
    
    def _phase1c_optimal_remaining(
        self,
        feasible_games: List[Dict],
        timeout: int
    ) -> List[Dict]:
        """
        Phase 1B: Optimal remaining coverage - schedule unscheduled teams.
        Only considers games involving teams not yet scheduled.
        Uses weights for quality.
        
        Args:
            feasible_games: All feasible games
            timeout: Time limit for this phase
            
        Returns:
            Games selected for remaining coverage
        """
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            raise RuntimeError("OR-Tools not installed")
        
        # Determine which teams still need scheduling
        scheduled_teams = set()
        for game in self.scheduled_games:
            scheduled_teams.add(game['teamA'])
            scheduled_teams.add(game['teamB'])
        
        # Filter to only games with at least one unscheduled team
        remaining_games = []
        for game in feasible_games:
            team_a = game['teamA']
            team_b = game['teamB']
            
            # Only consider if at least one team hasn't played
            if team_a not in scheduled_teams or team_b not in scheduled_teams:
                remaining_games.append(game)
        
        if not remaining_games:
            logger.info("Phase 1C: All teams already scheduled")
            return []
        
        logger.info(f"Phase 1C: Considering {len(remaining_games)} games for {len([t for g in remaining_games for t in [g['teamA'], g['teamB']] if t not in scheduled_teams])} unscheduled teams")
        
        model = cp_model.CpModel()
        
        # Decision variables
        game_vars = {}
        for i, game in enumerate(remaining_games):
            game_vars[i] = model.NewBoolVar(f'game_{i}')
        
        # Constraint: Each team plays at most once in Phase 1B
        team_games = defaultdict(list)
        for i, game in enumerate(remaining_games):
            team_games[game['teamA']].append(i)
            team_games[game['teamB']].append(i)
        
        for team_id, game_indices in team_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
        # Constraint: Respect weekly game limits
        # Group games by week for each team
        team_week_games = defaultdict(lambda: defaultdict(list))
        for i, game in enumerate(remaining_games):
            for tsl in game.get('available_tsls', [game]):
                timeslot_id = tsl.get('timeslot_id')
                if timeslot_id and timeslot_id in self.model.week_mapping:
                    week_num = self.model.week_mapping[timeslot_id][0]
                    team_week_games[game['teamA']][week_num].append(i)
                    team_week_games[game['teamB']][week_num].append(i)
        
        # For each team and week, limit games
        for team_id in team_games.keys():
            for week_num, game_indices in team_week_games[team_id].items():
                # Current games already scheduled for this team in this week
                current_count = self.team_weekly_games[week_num].get(team_id, 0)
                remaining_capacity = self.max_games_per_week - current_count
                
                if remaining_capacity > 0:
                    # Team can play at most remaining_capacity more games this week
                    model.Add(sum(game_vars[i] for i in game_indices) <= remaining_capacity)
        
        # TSL assignment
        tsl_assignment = {}
        tsl_usage = defaultdict(list)
        
        for i, game in enumerate(remaining_games):
            available_tsls = game.get('available_tsls', [game])
            
            # Filter out TSLs already used
            available_tsls = [tsl for tsl in available_tsls if tsl['tsl_id'] not in self.used_tsls]
            
            if not available_tsls:
                continue
            
            tsl_vars = {}
            for tsl in available_tsls:
                tsl_id = tsl['tsl_id']
                var_name = f'game_{i}_tsl_{tsl_id}'
                tsl_var = model.NewBoolVar(var_name)
                tsl_vars[tsl_id] = tsl_var
                tsl_usage[tsl_id].append((i, tsl_var))
            
            tsl_assignment[i] = (tsl_vars, available_tsls)
            
            model.Add(sum(tsl_vars.values()) == 1).OnlyEnforceIf(game_vars[i])
            model.Add(sum(tsl_vars.values()) == 0).OnlyEnforceIf(game_vars[i].Not())
        
        # Constraint: Each TSL used at most once
        for tsl_id, assignments in tsl_usage.items():
            model.Add(sum(var for _, var in assignments) <= 1)
        
        # Objective: Coverage of unscheduled teams + game quality
        team_coverage = {}
        for team_id, game_indices in team_games.items():
            if team_id not in scheduled_teams:
                coverage_var = model.NewBoolVar(f'coverage_t{team_id}')
                team_coverage[team_id] = coverage_var
                
                model.Add(sum(game_vars[i] for i in game_indices) >= 1).OnlyEnforceIf(coverage_var)
                model.Add(sum(game_vars[i] for i in game_indices) == 0).OnlyEnforceIf(coverage_var.Not())
        
        objective_terms = []
        
        # High priority: unscheduled team coverage
        for coverage_var in team_coverage.values():
            objective_terms.append(10000 * coverage_var)
        
        # Secondary: game quality
        for i, game in enumerate(remaining_games):
            weight = game.get('weight', 0)
            scaled_weight = int(weight * 1000)
            objective_terms.append(scaled_weight * game_vars[i])
        
        model.Maximize(sum(objective_terms))
        
        # Solve
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = timeout
        solver.parameters.log_search_progress = False
        
        status = solver.Solve(model)
        
        if status not in [cp_model.OPTIMAL, cp_model.FEASIBLE]:
            logger.warning(f"Phase 1C failed with status: {solver.StatusName(status)}")
            return []
        
        # Extract solution
        selected_games = []
        for i, game in enumerate(remaining_games):
            if solver.Value(game_vars[i]) == 1:
                if i not in tsl_assignment:
                    continue
                
                tsl_vars, available_tsls = tsl_assignment[i]
                assigned_tsl = None
                
                for tsl in available_tsls:
                    tsl_id = tsl['tsl_id']
                    if tsl_id in tsl_vars and solver.Value(tsl_vars[tsl_id]) == 1:
                        assigned_tsl = tsl
                        break
                
                if assigned_tsl:
                    # Check if we can still schedule (constraints may have changed)
                    if not self._can_schedule_game(game['teamA'], game['teamB'], assigned_tsl['timeslot_id']):
                        continue
                    
                    game_copy = game.copy()
                    game_copy['timeslot_id'] = assigned_tsl['timeslot_id']
                    game_copy['location_id'] = assigned_tsl['location_id']
                    game_copy['location_name'] = assigned_tsl['location_name']
                    game_copy['tsl_id'] = assigned_tsl['tsl_id']
                    game_copy['date'] = assigned_tsl['date']
                    game_copy['modifier'] = assigned_tsl['modifier']
                    
                    selected_games.append(game_copy)
                    self.used_tsls.add(assigned_tsl['tsl_id'])
                    self._track_game(game_copy)
        
        return selected_games
    
    def _phase2_greedy_filling(
        self,
        feasible_games: List[Dict],
        timeout: int
    ) -> List[Dict]:
        """
        Phase 2: Greedily fill remaining capacity.
        
        Args:
            feasible_games: All feasible games
            timeout: Time limit (not strictly enforced for greedy)
            
        Returns:
            Additional games selected
        """
        import time
        start_time = time.time()
        
        # Filter out games with teams that already played
        scheduled_teams = set()
        for game in self.scheduled_games:
            scheduled_teams.add(game['teamA'])
            scheduled_teams.add(game['teamB'])
        
        # Sort games by weight (best first)
        sorted_games = sorted(
            feasible_games,
            key=lambda g: g.get('weight', 0),
            reverse=True
        )
        
        additional_games = []
        
        for game in sorted_games:
            # Check timeout
            if time.time() - start_time > timeout:
                logger.info("Phase 2: Timeout reached")
                break
            
            team_a = game['teamA']
            team_b = game['teamB']
            
            # Try to find an available TSL for this game
            available_tsls = game.get('available_tsls', [game])
            
            for tsl in available_tsls:
                tsl_id = tsl['tsl_id']
                timeslot_id = tsl['timeslot_id']
                
                # Skip if TSL already used
                if tsl_id in self.used_tsls:
                    continue
                
                # Check constraints
                if not self._can_schedule_game(team_a, team_b, timeslot_id):
                    continue
                
                # Schedule this game!
                game_copy = game.copy()
                game_copy['timeslot_id'] = tsl['timeslot_id']
                game_copy['location_id'] = tsl['location_id']
                game_copy['location_name'] = tsl['location_name']
                game_copy['tsl_id'] = tsl['tsl_id']
                game_copy['date'] = tsl['date']
                game_copy['modifier'] = tsl['modifier']
                
                additional_games.append(game_copy)
                self.used_tsls.add(tsl_id)
                self._track_game(game_copy)
                
                # Found a slot, don't try other TSLs for this pairing
                break
        
        return additional_games
    
    def _can_schedule_game(self, team_a: int, team_b: int, timeslot_id: int) -> bool:
        """
        Check if game can be scheduled without violating constraints.
        
        Args:
            team_a: First team ID
            team_b: Second team ID
            timeslot_id: Timeslot ID
            
        Returns:
            True if game can be scheduled
        """
        # Get week and day for this timeslot
        if timeslot_id not in self.model.week_mapping:
            return False
        
        week_num = self.model.week_mapping[timeslot_id][0]
        day = self.model.day_mapping.get(timeslot_id)
        
        if day is None:
            return False
        
        # Check weekly limits
        if self.team_weekly_games[week_num][team_a] >= self.max_games_per_week:
            return False
        if self.team_weekly_games[week_num][team_b] >= self.max_games_per_week:
            return False
        
        # Check daily limits
        if self.team_daily_games[day][team_a] >= self.max_games_per_day:
            return False
        if self.team_daily_games[day][team_b] >= self.max_games_per_day:
            return False
        
        return True
    
    def _track_game(self, game: Dict):
        """Track a scheduled game for constraint checking and cross-week memory."""
        self.scheduled_games.append(game)
        
        team_a = game['teamA']
        team_b = game['teamB']
        timeslot_id = game['timeslot_id']
        
        # Track weekly game counts
        if timeslot_id in self.model.week_mapping:
            week_num = self.model.week_mapping[timeslot_id][0]
            self.team_weekly_games[week_num][team_a] += 1
            self.team_weekly_games[week_num][team_b] += 1
        
        # Track daily game counts
        if timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            self.team_daily_games[day][team_a] += 1
            self.team_daily_games[day][team_b] += 1
        
        # Track games THIS RUN for cross-week memory
        # This ensures Week 2 knows which teams got games in Week 1
        self.games_this_run[team_a] += 1
        self.games_this_run[team_b] += 1
    
    def _untrack_game(self, game: Dict):
        """Untrack a game when it's being removed/displaced."""
        team_a = game['teamA']
        team_b = game['teamB']
        timeslot_id = game['timeslot_id']
        
        # Untrack weekly game counts
        if timeslot_id in self.model.week_mapping:
            week_num = self.model.week_mapping[timeslot_id][0]
            self.team_weekly_games[week_num][team_a] -= 1
            self.team_weekly_games[week_num][team_b] -= 1
        
        # Untrack daily game counts
        if timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            self.team_daily_games[day][team_a] -= 1
            self.team_daily_games[day][team_b] -= 1
        
        # Untrack games THIS RUN
        self.games_this_run[team_a] -= 1
        self.games_this_run[team_b] -= 1
    
    def _diagnose_unscheduled_teams(self, week_num: int, feasible_games: List[Dict]):
        """
        Diagnose why teams didn't get scheduled in Phase 1A+1B.
        
        Args:
            week_num: Current week number
            feasible_games: All feasible games for this week
        """
        import sys
        
        # Get all teams that should have games this week
        all_teams = set()
        for game in feasible_games:
            all_teams.add(game['teamA'])
            all_teams.add(game['teamB'])
        
        # Get teams that were scheduled this week
        scheduled_this_week = set()
        for game in self.scheduled_games:
            if game['timeslot_id'] in self.model.week_mapping:
                game_week = self.model.week_mapping[game['timeslot_id']][0]
                if game_week == week_num:
                    scheduled_this_week.add(game['teamA'])
                    scheduled_this_week.add(game['teamB'])
        
        unscheduled = all_teams - scheduled_this_week
        
        if not unscheduled:
            sys.stderr.write(f"\n✓ Week {week_num}: All teams scheduled in Phase 1 (1A+1B+1C)\n")
            sys.stderr.flush()
            return
        
        sys.stderr.write(f"\n⚠️  Week {week_num} Diagnostic: {len(unscheduled)} teams unscheduled after Phase 1 (1A+1B+1C)\n")
        sys.stderr.write("=" * 80 + "\n")
        sys.stderr.flush()
        
        for team_id in sorted(unscheduled):
            team_name = self.model.get_team_name(team_id)
            sys.stderr.write(f"\nTeam {team_id} ({team_name}):\n")
            
            # Get this team's feasible games
            team_games = [g for g in feasible_games if g['teamA'] == team_id or g['teamB'] == team_id]
            
            if not team_games:
                sys.stderr.write("  ❌ NO FEASIBLE GAMES\n")
                sys.stderr.write("     - Team has no opponents available at overlapping times\n")
                sys.stderr.flush()
                continue
            
            # Get all TSL options for this team
            team_tsls = set()
            team_tsl_details = []
            for game in team_games:
                for tsl in game.get('available_tsls', [game]):
                    team_tsls.add(tsl['tsl_id'])
                    team_tsl_details.append({
                        'tsl_id': tsl['tsl_id'],
                        'date': tsl['date'],
                        'modifier': tsl['modifier'],
                        'location': tsl['location_name'],
                        'opponent': game['teamB'] if game['teamA'] == team_id else game['teamA']
                    })
            
            # Check various reasons
            reasons = []
            
            # 1. Check if all TSLs were taken
            used_tsls = team_tsls & self.used_tsls
            if len(used_tsls) == len(team_tsls):
                reasons.append("HIGH TSL DEMAND")
                sys.stderr.write(f"  🔥 HIGH TSL DEMAND - All {len(team_tsls)} TSL options taken by other teams\n")
                
                # Show which teams took these slots
                competing_teams = defaultdict(list)
                for game in self.scheduled_games:
                    if game['tsl_id'] in used_tsls:
                        game_week = self.model.week_mapping.get(game['timeslot_id'], [None])[0]
                        if game_week == week_num:
                            for tsl_detail in team_tsl_details:
                                if tsl_detail['tsl_id'] == game['tsl_id']:
                                    competing_teams[game['tsl_id']].append({
                                        'teams': (game['teamA'], game['teamB']),
                                        'tsl': tsl_detail
                                    })
                
                sys.stderr.write(f"     TSL utilization by competing teams:\n")
                for tsl_id in sorted(used_tsls):
                    if tsl_id in competing_teams:
                        for comp in competing_teams[tsl_id]:
                            t1, t2 = comp['teams']
                            t1_name = self.model.get_team_name(t1)
                            t2_name = self.model.get_team_name(t2)
                            tsl_info = comp['tsl']
                            t1_games = self.games_this_run.get(t1, 0)
                            t2_games = self.games_this_run.get(t2, 0)
                            sys.stderr.write(f"       - {tsl_info['date']} {tsl_info['modifier']} at {tsl_info['location']}: "
                                           f"{t1_name}({t1_games} games) vs {t2_name}({t2_games} games)\n")
            
            # 2. Check weekly capacity
            current_weekly_games = self.team_weekly_games[week_num].get(team_id, 0)
            if current_weekly_games >= self.max_games_per_week:
                reasons.append("WEEKLY CAPACITY EXHAUSTED")
                sys.stderr.write(f"  ⚠️  WEEKLY CAPACITY EXHAUSTED - Already has {current_weekly_games}/{self.max_games_per_week} games this week\n")
            
            # 3. Check if team has opponents available
            opponent_ids = set()
            for game in team_games:
                opponent = game['teamB'] if game['teamA'] == team_id else game['teamA']
                opponent_ids.add(opponent)
            
            available_opponents = []
            for opp_id in opponent_ids:
                opp_weekly_games = self.team_weekly_games[week_num].get(opp_id, 0)
                if opp_weekly_games < self.max_games_per_week:
                    available_opponents.append(opp_id)
            
            if not available_opponents:
                reasons.append("NO AVAILABLE OPPONENTS")
                sys.stderr.write(f"  ❌ NO AVAILABLE OPPONENTS - All {len(opponent_ids)} potential opponents at weekly capacity\n")
            
            # 4. Check cross-week priority
            team_games_this_run = self.games_this_run.get(team_id, 0)
            if team_games_this_run > 0:
                reasons.append("LOWER PRIORITY")
                sys.stderr.write(f"  📊 LOWER PRIORITY - Has {team_games_this_run} games from previous weeks this run\n")
                
                # Show if higher priority teams took their slots
                higher_priority_count = 0
                for game in self.scheduled_games:
                    if game['tsl_id'] in used_tsls:
                        game_week = self.model.week_mapping.get(game['timeslot_id'], [None])[0]
                        if game_week == week_num:
                            t1_priority = self.games_this_run.get(game['teamA'], 0)
                            t2_priority = self.games_this_run.get(game['teamB'], 0)
                            if t1_priority < team_games_this_run or t2_priority < team_games_this_run:
                                higher_priority_count += 1
                
                if higher_priority_count > 0:
                    sys.stderr.write(f"     - {higher_priority_count} of their TSL options taken by higher-priority teams\n")
            
            # 5. Check daily limits
            daily_conflicts = []
            for tsl_detail in team_tsl_details:
                date = tsl_detail['date']
                if date in [self.model.day_mapping.get(ts_id) for ts_id in self.model.day_mapping.keys()]:
                    daily_games = self.team_daily_games[date].get(team_id, 0)
                    if daily_games >= self.max_games_per_day:
                        daily_conflicts.append(date)
            
            if daily_conflicts:
                reasons.append("DAILY LIMIT CONFLICT")
                sys.stderr.write(f"  🚫 DAILY LIMIT CONFLICT - Already at daily limit on {len(set(daily_conflicts))} days\n")
            
            # Summary
            if not reasons:
                sys.stderr.write(f"  ⁉️  UNKNOWN REASON - Team has {len(team_games)} feasible games but wasn't scheduled\n")
                sys.stderr.write(f"     - {len(team_games)} feasible games available\n")
                sys.stderr.write(f"     - {len(team_tsls)} TSL options ({len(used_tsls)} taken, {len(team_tsls) - len(used_tsls)} free)\n")
                sys.stderr.write(f"     - {len(opponent_ids)} potential opponents ({len(available_opponents)} available)\n")
            
            sys.stderr.flush()
        
        sys.stderr.write("=" * 80 + "\n\n")
        sys.stderr.flush()
