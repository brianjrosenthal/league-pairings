"""
True Multi-Phase Scheduler

Phase 1: Coverage Optimization (OR-Tools CP-SAT)
- Ensures every team plays at least 1 game
- Uses precise optimization
- Allocates ~40% of timeout

Phase 2: Greedy Capacity Filling
- Fills remaining capacity up to 3 games/week, 1 game/day
- Fast greedy algorithm
- Uses remaining timeout
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
        Generate schedule using two-phase approach.
        
        Args:
            feasible_games: List of feasible games (one per team pairing)
            
        Returns:
            Selected games forming optimal schedule
        """
        if not feasible_games:
            raise RuntimeError("No feasible games provided")
        
        logger.info(f"True Multi-Phase: Starting with {len(feasible_games)} feasible games")
        
        # Phase 1: Coverage optimization (40% of timeout)
        phase1_timeout = int(self.timeout * 0.4)
        logger.info(f"=== PHASE 1: Coverage Optimization ({phase1_timeout}s) ===")
        phase1_games = self._phase1_coverage_optimization(feasible_games, phase1_timeout)
        
        logger.info(f"Phase 1 complete: {len(phase1_games)} games scheduled")
        
        # Phase 2: Greedy capacity filling (remaining timeout)
        phase2_timeout = self.timeout - phase1_timeout
        logger.info(f"=== PHASE 2: Greedy Capacity Filling ({phase2_timeout}s) ===")
        phase2_games = self._phase2_greedy_filling(feasible_games, phase2_timeout)
        
        logger.info(f"Phase 2 complete: {len(phase2_games)} additional games scheduled")
        
        total_games = phase1_games + phase2_games
        logger.info(f"Total games scheduled: {len(total_games)}")
        
        return total_games
    
    def _phase1_coverage_optimization(
        self, 
        feasible_games: List[Dict],
        timeout: int
    ) -> List[Dict]:
        """
        Phase 1: Ensure every team plays at least once using OR-Tools.
        
        Args:
            feasible_games: All feasible games
            timeout: Time limit for this phase
            
        Returns:
            Games selected for coverage
        """
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            raise RuntimeError("OR-Tools not installed")
        
        model = cp_model.CpModel()
        
        # Decision variables
        game_vars = {}
        for i, game in enumerate(feasible_games):
            game_vars[i] = model.NewBoolVar(f'game_{i}')
        
        # Constraint: Each team plays at most once
        team_games = defaultdict(list)
        for i, game in enumerate(feasible_games):
            team_games[game['teamA']].append(i)
            team_games[game['teamB']].append(i)
        
        for team_id, game_indices in team_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
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
        
        # Maximize: coverage + game weights
        objective_terms = []
        
        # High priority: team coverage
        for coverage_var in team_coverage.values():
            objective_terms.append(10000 * coverage_var)
        
        # Secondary: game quality
        for i, game in enumerate(feasible_games):
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
            logger.warning(f"Phase 1 failed with status: {solver.StatusName(status)}")
            return []
        
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
                    
                    # Track usage
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
        """Track a scheduled game for constraint checking."""
        self.scheduled_games.append(game)
        
        team_a = game['teamA']
        team_b = game['teamB']
        timeslot_id = game['timeslot_id']
        
        if timeslot_id in self.model.week_mapping:
            week_num = self.model.week_mapping[timeslot_id][0]
            self.team_weekly_games[week_num][team_a] += 1
            self.team_weekly_games[week_num][team_b] += 1
        
        if timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            self.team_daily_games[day][team_a] += 1
            self.team_daily_games[day][team_b] += 1
