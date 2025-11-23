"""
True Multi-Phase Scheduler

Phase 1A: Pure Coverage (OR-Tools CP-SAT)
- Maximizes number of teams that play (ignores weights)
- Each team gets at most 1 game in this phase
- Allocates ~10% of timeout

Phase 1B: Comprehensive Optimal (OR-Tools CP-SAT)
- Schedules ALL remaining unscheduled teams optimally
- Considers unscheduled vs unscheduled, unscheduled vs scheduled
- Uses weights for quality
- Allocates ~10% of timeout

Phase 1C: Strategic Displacement
- For still-unscheduled teams, tries swapping with scheduled teams
- Within-division swaps only
- Only if displaced team can be rescheduled
- Allocates ~10% of timeout

Phase 2: Greedy Capacity Filling
- Fills remaining capacity up to 3 games/week, 1 game/day
- Fast greedy algorithm
- Allocates ~70% of timeout
"""

from typing import Dict, List, Optional, Set, Tuple
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
            
            # Phase 1B: Comprehensive optimal (10% of week time)
            phase1b_timeout = int(time_per_week * 0.10)
            logger.info(f"Phase 1B: Comprehensive Optimal ({phase1b_timeout}s)")
            phase1b_games = self._phase1b_optimal_remaining(week_games, phase1b_timeout)
            all_scheduled_games.extend(phase1b_games)
            
            logger.info(f"Phase 1B: Scheduled {len(phase1b_games)} additional games")
            
            # Phase 1C: Strategic displacement (10% of week time)
            phase1c_timeout = int(time_per_week * 0.10)
            logger.info(f"Phase 1C: Strategic Displacement ({phase1c_timeout}s)")
            phase1c_games = self._phase1c_strategic_displacement(week_games, week_num, phase1c_timeout)
            all_scheduled_games.extend(phase1c_games)
            
            logger.info(f"Phase 1C: Scheduled {len(phase1c_games)} additional games")
            
            # Diagnose unscheduled teams after Phase 1A+1B+1C
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
        
        # Add Sunday preference as a tiebreaker (10x weight vs 1000x for team coverage)
        # This preserves high-slot days for future scheduling flexibility
        for i, game in enumerate(feasible_games):
            tsl_vars, available_tsls = tsl_assignment.get(i, ({}, []))
            for tsl in available_tsls:
                tsl_id = tsl['tsl_id']
                timeslot_id = tsl.get('timeslot_id')
                
                # Check if this TSL is on Sunday
                if timeslot_id and timeslot_id in self.model.day_mapping:
                    day = self.model.day_mapping[timeslot_id]
                    # Assuming day format like "2025-11-24" or similar date string
                    # Sunday detection: check if it's Sunday (day of week)
                    try:
                        from datetime import datetime
                        day_obj = datetime.strptime(day, '%Y-%m-%d')
                        if day_obj.weekday() == 6:  # Sunday = 6 in Python's weekday()
                            # Add small bonus for Sunday TSLs
                            if tsl_id in tsl_vars:
                                objective_terms.append(10 * tsl_vars[tsl_id])
                    except (ValueError, TypeError):
                        # If day format is different or parsing fails, skip Sunday bonus
                        pass
        
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
    
    def _phase1b_optimal_remaining(
        self,
        feasible_games: List[Dict],
        timeout: int
    ) -> List[Dict]:
        """
        Phase 1B: Comprehensive optimal scheduling for unscheduled teams.
        Considers ALL games involving unscheduled teams, including:
        - Unscheduled vs Unscheduled
        - Unscheduled vs Already-Scheduled (if scheduled team has capacity)
        Uses OR-Tools to optimally place all remaining unscheduled teams.
        
        Args:
            feasible_games: All feasible games
            timeout: Time limit for this phase
            
        Returns:
            Games selected for remaining coverage
        """
        import sys
        
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            raise RuntimeError("OR-Tools not installed")
        
        # Determine which teams still need scheduling THIS WEEK
        scheduled_this_week = set()
        for game in self.scheduled_games:
            timeslot_id = game.get('timeslot_id')
            if timeslot_id and timeslot_id in self.model.week_mapping:
                # Only count games from the current week we're processing
                scheduled_this_week.add(game['teamA'])
                scheduled_this_week.add(game['teamB'])
        
        # DEBUG: Show unscheduled teams by division
        all_teams = set()
        for game in feasible_games:
            all_teams.add(game['teamA'])
            all_teams.add(game['teamB'])
        
        unscheduled_teams = all_teams - scheduled_this_week
        
        if unscheduled_teams:
            # Group by division
            teams_by_division = defaultdict(list)
            for team_id in unscheduled_teams:
                team_data = self.model.team_lookup.get(team_id)
                if team_data:
                    division_id = team_data['division_id']
                    division_name = team_data.get('division_name', f"Division {division_id}")
                    team_name = self.model.get_team_name(team_id)
                    teams_by_division[division_name].append(team_name)
            
            sys.stderr.write(f"\nAfter Phase 1A, the following teams do not have games:\n")
            for division_name in sorted(teams_by_division.keys()):
                team_names = sorted(teams_by_division[division_name])
                sys.stderr.write(f"\nDivision \"{division_name}\":\n")
                for team_name in team_names:
                    sys.stderr.write(f"  - {team_name}\n")
            sys.stderr.write("\n")
            sys.stderr.flush()
        
        # Include ALL games where at least one team is unscheduled THIS WEEK
        # This allows unscheduled teams to play against already-scheduled teams
        remaining_games = []
        for game in feasible_games:
            team_a = game['teamA']
            team_b = game['teamB']
            
            # Include if at least one team hasn't played this week yet
            if team_a not in scheduled_this_week or team_b not in scheduled_this_week:
                remaining_games.append(game)
        
        if not remaining_games:
            logger.info("Phase 1B: All teams already scheduled")
            return []
        
        # Count unscheduled teams
        unscheduled_teams = set()
        for game in remaining_games:
            if game['teamA'] not in scheduled_this_week:
                unscheduled_teams.add(game['teamA'])
            if game['teamB'] not in scheduled_this_week:
                unscheduled_teams.add(game['teamB'])
        
        logger.info(f"Phase 1B: Considering {len(remaining_games)} games for {len(unscheduled_teams)} unscheduled teams")
        
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
            if team_id not in scheduled_this_week:
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
            logger.warning(f"Phase 1B failed with status: {solver.StatusName(status)}")
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
            
            # VALIDATION: Check if we're violating weekly constraint
            if self.team_weekly_games[week_num][team_a] > self.max_games_per_week:
                logger.error(f"CONSTRAINT VIOLATION: Team {team_a} scheduled for "
                           f"{self.team_weekly_games[week_num][team_a]} games in week {week_num} "
                           f"(max={self.max_games_per_week})")
            if self.team_weekly_games[week_num][team_b] > self.max_games_per_week:
                logger.error(f"CONSTRAINT VIOLATION: Team {team_b} scheduled for "
                           f"{self.team_weekly_games[week_num][team_b]} games in week {week_num} "
                           f"(max={self.max_games_per_week})")
        
        # Track daily game counts
        if timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            self.team_daily_games[day][team_a] += 1
            self.team_daily_games[day][team_b] += 1
            
            # VALIDATION: Check if we're violating daily constraint
            if self.team_daily_games[day][team_a] > self.max_games_per_day:
                logger.error(f"CONSTRAINT VIOLATION: Team {team_a} scheduled for "
                           f"{self.team_daily_games[day][team_a]} games on {day} "
                           f"(max={self.max_games_per_day})")
            if self.team_daily_games[day][team_b] > self.max_games_per_day:
                logger.error(f"CONSTRAINT VIOLATION: Team {team_b} scheduled for "
                           f"{self.team_daily_games[day][team_b]} games on {day} "
                           f"(max={self.max_games_per_day})")
        
        # Track games THIS RUN for cross-week memory
        # This ensures Week 2 knows which teams got games in Week 1
        self.games_this_run[team_a] += 1
        self.games_this_run[team_b] += 1
    
    def _untrack_game(self, game: Dict):
        """Untrack a scheduled game (used in displacement)."""
        team_a = game['teamA']
        team_b = game['teamB']
        timeslot_id = game['timeslot_id']
        tsl_id = game['tsl_id']
        
        # Remove from scheduled games
        self.scheduled_games.remove(game)
        
        # Remove from used TSLs
        self.used_tsls.discard(tsl_id)
        
        # Decrement weekly game counts
        if timeslot_id in self.model.week_mapping:
            week_num = self.model.week_mapping[timeslot_id][0]
            self.team_weekly_games[week_num][team_a] -= 1
            self.team_weekly_games[week_num][team_b] -= 1
        
        # Decrement daily game counts
        if timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            self.team_daily_games[day][team_a] -= 1
            self.team_daily_games[day][team_b] -= 1
        
        # Decrement games this run
        self.games_this_run[team_a] -= 1
        self.games_this_run[team_b] -= 1
    
    def _can_perform_displacement(
        self,
        scheduled_game: Dict,
        displaced_team: int,
        kept_team: int,
        unscheduled_team: int,
        swap_game: Dict,
        feasible_games: List[Dict],
        week_num: int
    ) -> Optional[Tuple[Dict, Dict, Dict]]:
        """
        Check if a displacement is viable WITHOUT modifying any state.
        
        Returns:
            Tuple of (swap_game_data, alt_game_data, alt_tsl_data) if viable, None otherwise
        """
        tsl_id = scheduled_game['tsl_id']
        timeslot_id = scheduled_game['timeslot_id']
        
        # Simulate untracking: check if we can schedule unscheduled_team vs kept_team
        # by temporarily reducing the game counts for displaced_team and kept_team
        week_num_sched = self.model.week_mapping[timeslot_id][0]
        day_sched = self.model.day_mapping[timeslot_id]
        
        # Check if unscheduled_team and kept_team can play at this TSL
        # (simulating that the old game is removed)
        unscheduled_week_count = self.team_weekly_games[week_num_sched].get(unscheduled_team, 0)
        kept_week_count = self.team_weekly_games[week_num_sched].get(kept_team, 0) - 1  # Simulate removal
        
        if unscheduled_week_count >= self.max_games_per_week:
            return None
        if kept_week_count >= self.max_games_per_week:
            return None
        
        unscheduled_day_count = self.team_daily_games[day_sched].get(unscheduled_team, 0)
        kept_day_count = self.team_daily_games[day_sched].get(kept_team, 0) - 1  # Simulate removal
        
        if unscheduled_day_count >= self.max_games_per_day:
            return None
        if kept_day_count >= self.max_games_per_day:
            return None
        
        # Find alternative game for displaced team
        alternative_games = [
            g for g in feasible_games
            if (g['teamA'] == displaced_team or g['teamB'] == displaced_team)
        ]
        
        for alt_game in alternative_games:
            alt_opponent = alt_game['teamB'] if alt_game['teamA'] == displaced_team else alt_game['teamA']
            
            for alt_tsl in alt_game.get('available_tsls', [alt_game]):
                alt_tsl_id = alt_tsl['tsl_id']
                alt_timeslot_id = alt_tsl['timeslot_id']
                
                # Verify this TSL is in the same week
                if alt_timeslot_id not in self.model.week_mapping:
                    continue
                alt_week = self.model.week_mapping[alt_timeslot_id][0]
                if alt_week != week_num:
                    continue
                
                # Skip the TSL we're using for the swap
                if alt_tsl_id == tsl_id:
                    continue
                
                # Skip already used TSLs
                if alt_tsl_id in self.used_tsls:
                    continue
                
                # Check if displaced_team can schedule with this opponent
                # (simulating that their old game is removed)
                displaced_week_count = self.team_weekly_games[alt_week].get(displaced_team, 0) - 1  # Simulate removal
                alt_opp_week_count = self.team_weekly_games[alt_week].get(alt_opponent, 0)
                
                if displaced_week_count >= self.max_games_per_week:
                    continue
                if alt_opp_week_count >= self.max_games_per_week:
                    continue
                
                alt_day = self.model.day_mapping.get(alt_timeslot_id)
                if alt_day is None:
                    continue
                
                displaced_day_count = self.team_daily_games[alt_day].get(displaced_team, 0) - 1  # Simulate removal
                alt_opp_day_count = self.team_daily_games[alt_day].get(alt_opponent, 0)
                
                if displaced_day_count >= self.max_games_per_day:
                    continue
                if alt_opp_day_count >= self.max_games_per_day:
                    continue
                
                # Found a viable swap!
                return (swap_game, alt_game, alt_tsl)
        
        return None
    
    def _phase1c_strategic_displacement(
        self,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> List[Dict]:
        """
        Phase 1C: Strategic displacement for unscheduled teams.
        Tries to swap unscheduled teams with scheduled teams in same division.
        Only swaps if the displaced team can be immediately rescheduled.
        
        Args:
            feasible_games: All feasible games
            week_num: Current week number
            timeout: Time limit for this phase
            
        Returns:
            Games scheduled through displacement
        """
        import time
        import sys
        start_time = time.time()
        
        # Find teams still unscheduled this week
        scheduled_this_week = set()
        for game in self.scheduled_games:
            if game['timeslot_id'] in self.model.week_mapping:
                game_week = self.model.week_mapping[game['timeslot_id']][0]
                if game_week == week_num:
                    scheduled_this_week.add(game['teamA'])
                    scheduled_this_week.add(game['teamB'])
        
        all_teams = set()
        for game in feasible_games:
            all_teams.add(game['teamA'])
            all_teams.add(game['teamB'])
        
        unscheduled_teams = all_teams - scheduled_this_week
        
        if not unscheduled_teams:
            logger.info("Phase 1C: No unscheduled teams, skipping displacement")
            return []
        
        # Check if there are any unused TSLs for this week
        week_tsls = set()
        for game in feasible_games:
            for tsl in game.get('available_tsls', [game]):
                if tsl.get('timeslot_id') in self.model.week_mapping:
                    tsl_week = self.model.week_mapping[tsl['timeslot_id']][0]
                    if tsl_week == week_num:
                        week_tsls.add(tsl['tsl_id'])
        
        used_week_tsls = set()
        for game in self.scheduled_games:
            if game['timeslot_id'] in self.model.week_mapping:
                game_week = self.model.week_mapping[game['timeslot_id']][0]
                if game_week == week_num:
                    used_week_tsls.add(game['tsl_id'])
        
        available_tsls = week_tsls - used_week_tsls
        
        if not available_tsls:
            logger.info(f"Phase 1C: No available TSLs for week {week_num}, skipping displacement")
            logger.info(f"  All {len(week_tsls)} TSLs are already in use")
            return []
        
        logger.info(f"Phase 1C: Attempting displacement for {len(unscheduled_teams)} unscheduled teams")
        logger.info(f"  Week {week_num} has {len(available_tsls)} unused TSLs (out of {len(week_tsls)} total)")
        
        displaced_games = []
        
        # For each unscheduled team, try to displace a scheduled team
        for unscheduled_team in unscheduled_teams:
            # Check timeout
            if time.time() - start_time > timeout:
                logger.info("Phase 1C: Timeout reached")
                break
            
            # Get this team's division
            unscheduled_team_data = self.model.team_lookup.get(unscheduled_team)
            if not unscheduled_team_data:
                continue
            unscheduled_division = unscheduled_team_data['division_id']
            
            # DEBUG: Print header for this unscheduled team
            unscheduled_team_name = self.model.get_team_name(unscheduled_team)
            division_name = unscheduled_team_data.get('division_name', f"Division {unscheduled_division}")
            
            sys.stderr.write(f"\n{'='*80}\n")
            sys.stderr.write(f"Trying to place: {unscheduled_team_name} (Division \"{division_name}\")\n")
            sys.stderr.write(f"{'='*80}\n")
            
            # Show current games in this division for this week
            division_games = [
                g for g in self.scheduled_games
                if g['timeslot_id'] in self.model.week_mapping
                and self.model.week_mapping[g['timeslot_id']][0] == week_num
                and (self.model.team_lookup.get(g['teamA'], {}).get('division_id') == unscheduled_division
                     or self.model.team_lookup.get(g['teamB'], {}).get('division_id') == unscheduled_division)
            ]
            
            sys.stderr.write(f"\nGames currently in Division \"{division_name}\" for week {week_num}:\n")
            if division_games:
                for game in division_games:
                    location_name = game.get('location_name', 'Unknown')
                    date = game.get('date', 'Unknown')
                    modifier = game.get('modifier', '')
                    team1_name = self.model.get_team_name(game['teamA'])
                    team2_name = self.model.get_team_name(game['teamB'])
                    sys.stderr.write(f"  - {location_name}, {date} {modifier}, {team1_name} vs {team2_name}\n")
            else:
                sys.stderr.write(f"  (No games scheduled yet in this division for this week)\n")
            sys.stderr.write("\n")
            sys.stderr.flush()
            
            # Look for a scheduled game this week that we could displace
            for scheduled_game in list(self.scheduled_games):
                game_week = self.model.week_mapping.get(scheduled_game['timeslot_id'], [None])[0]
                if game_week != week_num:
                    continue
                
                scheduled_team_a = scheduled_game['teamA']
                scheduled_team_b = scheduled_game['teamB']
                
                # Check if either team in scheduled game is in same division as unscheduled team
                team_a_data = self.model.team_lookup.get(scheduled_team_a)
                team_b_data = self.model.team_lookup.get(scheduled_team_b)
                
                if not team_a_data or not team_b_data:
                    continue
                
                team_a_division = team_a_data['division_id']
                team_b_division = team_b_data['division_id']
                
                if team_a_division != unscheduled_division and team_b_division != unscheduled_division:
                    continue
                
                # Try displacing one of the teams
                for displaced_team in [scheduled_team_a, scheduled_team_b]:
                    displaced_team_data = self.model.team_lookup.get(displaced_team)
                    if not displaced_team_data or displaced_team_data['division_id'] != unscheduled_division:
                        continue
                    
                    kept_team = scheduled_team_b if displaced_team == scheduled_team_a else scheduled_team_a
                    
                    # DEBUG: Show displacement attempt
                    displaced_team_name = self.model.get_team_name(displaced_team)
                    kept_team_name = self.model.get_team_name(kept_team)
                    
                    sys.stderr.write(f"\nTrying to displace {displaced_team_name}. ")
                    sys.stderr.write(f"{displaced_team_name} has location availability at the following slots:\n")
                    
                    # Get all feasible games for displaced team to show their availability
                    displaced_team_games = [
                        g for g in feasible_games
                        if g['teamA'] == displaced_team or g['teamB'] == displaced_team
                    ]
                    
                    # Collect all TSL options for this team
                    displaced_tsls = []
                    for dg in displaced_team_games:
                        for tsl in dg.get('available_tsls', [dg]):
                            if tsl.get('timeslot_id') in self.model.week_mapping:
                                tsl_week = self.model.week_mapping[tsl['timeslot_id']][0]
                                if tsl_week == week_num:
                                    displaced_tsls.append(tsl)
                    
                    # Show only available TSLs (excluding same day as the game being displaced)
                    scheduled_game_day = self.model.day_mapping.get(scheduled_game['timeslot_id'])
                    
                    available_tsls_for_team = []
                    for tsl in displaced_tsls:
                        tsl_id = tsl.get('tsl_id')
                        tsl_timeslot_id = tsl.get('timeslot_id')
                        
                        # Filter out:
                        # 1. Already used TSLs
                        # 2. TSLs on the same day as the game being displaced
                        if tsl_id not in self.used_tsls:
                            tsl_day = self.model.day_mapping.get(tsl_timeslot_id)
                            if tsl_day != scheduled_game_day:
                                available_tsls_for_team.append(tsl)
                    
                    if available_tsls_for_team:
                        for tsl in available_tsls_for_team:
                            date = tsl.get('date', 'Unknown')
                            modifier = tsl.get('modifier', '')
                            sys.stderr.write(f"  - {date} {modifier} (available)\n")
                    else:
                        sys.stderr.write(f"  (No available slots)\n")
                    
                    sys.stderr.flush()
                    
                    # Find the game between unscheduled_team and kept_team
                    swap_game = None
                    for g in feasible_games:
                        if ((g['teamA'] == unscheduled_team and g['teamB'] == kept_team) or
                            (g['teamA'] == kept_team and g['teamB'] == unscheduled_team)):
                            # Check if the scheduled game's TSL is available for this pairing
                            game_tsls = g.get('available_tsls', [g])
                            if any(tsl['tsl_id'] == scheduled_game['tsl_id'] for tsl in game_tsls):
                                swap_game = g
                                break
                    
                    if not swap_game:
                        sys.stderr.write(f"  ✗ Cannot swap: No feasible game between {unscheduled_team_name} and {kept_team_name}\n")
                        
                        # Show all other teams in this division and their available timeslots
                        # to help diagnose why displacement isn't possible
                        excluded_teams = {unscheduled_team, displaced_team, kept_team}
                        
                        # Get all teams in this division
                        division_teams = set()
                        for game in feasible_games:
                            team_a_data = self.model.team_lookup.get(game['teamA'])
                            team_b_data = self.model.team_lookup.get(game['teamB'])
                            if team_a_data and team_a_data['division_id'] == unscheduled_division:
                                division_teams.add(game['teamA'])
                            if team_b_data and team_b_data['division_id'] == unscheduled_division:
                                division_teams.add(game['teamB'])
                        
                        other_teams = division_teams - excluded_teams
                        
                        if other_teams:
                            sys.stderr.write(f"\n  Other teams in Division \"{division_name}\" and their available timeslots:\n")
                            
                            for other_team_id in sorted(other_teams):
                                other_team_name = self.model.get_team_name(other_team_id)
                                
                                # Get all available (unoccupied) timeslots for this team (games with displaced_team)
                                team_available_slots = []
                                for game in feasible_games:
                                    if (game['teamA'] == displaced_team and game['teamB'] == other_team_id) or \
                                       (game['teamA'] == other_team_id and game['teamB'] == displaced_team):
                                        # Check available TSLs for this week
                                        for tsl in game.get('available_tsls', [game]):
                                            tsl_id = tsl.get('tsl_id')
                                            timeslot_id = tsl.get('timeslot_id')
                                            
                                            # Only include TSLs that are:
                                            # 1. In the correct week
                                            # 2. Not already used/occupied
                                            if timeslot_id and timeslot_id in self.model.week_mapping:
                                                tsl_week = self.model.week_mapping[timeslot_id][0]
                                                if tsl_week == week_num and tsl_id and tsl_id not in self.used_tsls:
                                                    date = tsl.get('date', 'Unknown')
                                                    modifier = tsl.get('modifier', '')
                                                    team_available_slots.append(f"{date} {modifier}")
                                
                                if team_available_slots:
                                    sys.stderr.write(f"    {other_team_name}: {', '.join(team_available_slots)}\n")
                                else:
                                    sys.stderr.write(f"    {other_team_name}: (no available timeslots with {displaced_team_name})\n")
                        
                        sys.stderr.write("\n")
                        sys.stderr.flush()
                        continue
                    
                    # Check if displacement is viable WITHOUT modifying state
                    sys.stderr.write(f"\nTrying available timeslots for {displaced_team_name}:\n")
                    sys.stderr.flush()
                    
                    swap_result = self._can_perform_displacement(
                        scheduled_game, displaced_team, kept_team,
                        unscheduled_team, swap_game, feasible_games, week_num
                    )
                    
                    if swap_result is None:
                        sys.stderr.write(f"  ✗ No viable alternatives found for {displaced_team_name}\n\n")
                        sys.stderr.flush()
                        continue
                    
                    # Show success
                    swap_game_template, alt_game_template, alt_tsl = swap_result
                    alt_opponent = alt_game_template['teamB'] if alt_game_template['teamA'] == displaced_team else alt_game_template['teamA']
                    alt_opponent_name = self.model.get_team_name(alt_opponent)
                    alt_date = alt_tsl.get('date', 'Unknown')
                    alt_modifier = alt_tsl.get('modifier', '')
                    
                    sys.stderr.write(f"  ✓ {alt_date} {alt_modifier}: {alt_opponent_name} is available! Scheduling game then.\n\n")
                    sys.stderr.flush()
                    
                    # Displacement is viable! Now commit the changes
                    swap_game_template, alt_game_template, alt_tsl = swap_result
                    
                    # Untrack old game
                    self._untrack_game(scheduled_game)
                    
                    # Track new swap game (unscheduled_team vs kept_team)
                    new_game = swap_game_template.copy()
                    new_game['timeslot_id'] = scheduled_game['timeslot_id']
                    new_game['location_id'] = scheduled_game['location_id']
                    new_game['location_name'] = scheduled_game['location_name']
                    new_game['tsl_id'] = scheduled_game['tsl_id']
                    new_game['date'] = scheduled_game['date']
                    new_game['modifier'] = scheduled_game['modifier']
                    
                    self.used_tsls.add(scheduled_game['tsl_id'])
                    self._track_game(new_game)
                    displaced_games.append(new_game)
                    
                    # Track new game for displaced team
                    alt_new_game = alt_game_template.copy()
                    alt_new_game['timeslot_id'] = alt_tsl['timeslot_id']
                    alt_new_game['location_id'] = alt_tsl['location_id']
                    alt_new_game['location_name'] = alt_tsl['location_name']
                    alt_new_game['tsl_id'] = alt_tsl['tsl_id']
                    alt_new_game['date'] = alt_tsl['date']
                    alt_new_game['modifier'] = alt_tsl['modifier']
                    
                    self.used_tsls.add(alt_tsl['tsl_id'])
                    self._track_game(alt_new_game)
                    displaced_games.append(alt_new_game)
                    
                    alt_opponent = alt_game_template['teamB'] if alt_game_template['teamA'] == displaced_team else alt_game_template['teamA']
                    alt_opponent_name = self.model.get_team_name(alt_opponent)
                    logger.info(f"Displaced team {displaced_team} from TSL {scheduled_game['tsl_id']} "
                              f"to TSL {alt_tsl['tsl_id']} (now playing {alt_opponent_name}) "
                              f"to make room for team {unscheduled_team}")
                    
                    # Successfully scheduled unscheduled team
                    scheduled_this_week.add(unscheduled_team)
                    break
                
                if unscheduled_team in scheduled_this_week:
                    break
        
        return displaced_games
    
    def _diagnose_unscheduled_teams(self, week_num: int, feasible_games: List[Dict]):
        """
        Diagnose why teams didn't get scheduled in Phase 1A+1B+1C.
        
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
            sys.stderr.write(f"\n✓ Week {week_num}: All teams scheduled in Phase 1A+1B+1C\n")
            sys.stderr.flush()
            return
        
        sys.stderr.write(f"\n⚠️  Week {week_num} Diagnostic: {len(unscheduled)} teams unscheduled after Phase 1A+1B+1C\n")
        sys.stderr.write("=" * 80 + "\n")
        sys.stderr.flush()
        
        # Count total available TSLs for this week
        week_tsls = set()
        for game in feasible_games:
            for tsl in game.get('available_tsls', [game]):
                if tsl.get('timeslot_id') in self.model.week_mapping:
                    tsl_week = self.model.week_mapping[tsl['timeslot_id']][0]
                    if tsl_week == week_num:
                        week_tsls.add(tsl['tsl_id'])
        
        total_week_tsls = len(week_tsls)
        teams_this_week = len(all_teams)
        
        # Check for insufficient timeslots at week level
        if teams_this_week > total_week_tsls * 2:
            sys.stderr.write(f"\n⚠️  INSUFFICIENT TIMESLOTS FOR WEEK {week_num}:\n")
            sys.stderr.write(f"   - {teams_this_week} teams need games\n")
            sys.stderr.write(f"   - Only {total_week_tsls} timeslots available\n")
            sys.stderr.write(f"   - Maximum {total_week_tsls * 2} teams can be scheduled (2 per TSL)\n")
            sys.stderr.write(f"   - Need at least {teams_this_week // 2} timeslots\n\n")
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
