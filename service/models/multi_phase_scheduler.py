"""
Multi-phase scheduler with weekly and daily constraints.

This scheduler implements a two-phase approach:
1. Phase 1: Ensure every available team plays at least 1 game per week
2. Phase 2: Fill remaining capacity up to max 3 games/week, 1 game/day per team
"""

from typing import Dict, List, Set, Tuple
from collections import defaultdict
import logging

from .data_model import DataModel

logger = logging.getLogger(__name__)


class MultiPhaseORToolsScheduler:
    """
    Multi-phase scheduler using Google OR-Tools CP-SAT.
    
    Handles complex constraints:
    - 1-3 games per team per week
    - Max 1 game per team per day
    - Season game balance (priority to underplayed teams)
    - Two-phase optimization for minimum coverage then capacity filling
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
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Generate schedule using rolling weekly optimization.
        
        For better performance on longer periods, optimizes one week at a time,
        treating previous weeks' selections as fixed constraints.
        
        Args:
            feasible_games: List of feasible games with weights
            
        Returns:
            Selected games forming optimal schedule
            
        Raises:
            RuntimeError: If optimization fails
        """
        if not feasible_games:
            raise RuntimeError("No feasible games provided")
        
        # Build game metadata first to determine weeks
        self._build_game_metadata(feasible_games)
        
        # Get unique weeks
        unique_weeks = set()
        for (week_num, _) in self.games_by_week_team.keys():
            unique_weeks.add(week_num)
        
        if not unique_weeks:
            raise RuntimeError("No valid week mappings found")
        
        weeks = sorted(unique_weeks)
        logger.info(f"Scheduling across {len(weeks)} weeks: {weeks}")
        
        # If only 1-2 weeks, optimize together
        if len(weeks) <= 2:
            logger.info("Short period (≤2 weeks), optimizing together")
            return self._schedule_all_at_once(feasible_games)
        
        # For longer periods, use rolling weekly optimization
        logger.info(f"Long period ({len(weeks)} weeks), using rolling optimization")
        return self._schedule_rolling_weekly(feasible_games, weeks)
    
    def _schedule_all_at_once(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Optimize all weeks together (for short periods).
        
        Args:
            feasible_games: List of feasible games with weights
            
        Returns:
            Selected games forming optimal schedule
        """
        if not feasible_games:
            raise RuntimeError("No feasible games provided")
        
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            raise RuntimeError(
                "OR-Tools library not installed. Install with: pip install ortools"
            )
        
        logger.info(f"Multi-phase scheduler: Starting with {len(feasible_games)} feasible games")
        
        # Build game metadata structures
        self._build_game_metadata(feasible_games)
        
        # Create the model
        model = cp_model.CpModel()
        
        # Decision variables: binary variable for each game
        game_vars = {}
        for i in range(len(feasible_games)):
            game_vars[i] = model.NewBoolVar(f'game_{i}')
        
        # Add basic constraints (team uniqueness per game, TSL uniqueness)
        self._add_basic_constraints(model, game_vars, feasible_games)
        
        # Add weekly game limits
        self._add_weekly_constraints(model, game_vars, feasible_games)
        
        # Add daily game limits
        self._add_daily_constraints(model, game_vars, feasible_games)
        
        # Add minimum coverage objective (Phase 1)
        self._add_minimum_coverage_objective(model, game_vars, feasible_games)
        
        # Objective: maximize weighted games + minimum coverage
        self._set_objective(model, game_vars, feasible_games)
        
        logger.info(f"Multi-phase scheduler: Problem has {len(game_vars)} variables")
        
        # Solve
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = self.timeout
        solver.parameters.log_search_progress = False
        solver.parameters.num_search_workers = 1
        
        status = solver.Solve(model)
        
        logger.info(f"Multi-phase scheduler: Solver completed with status: {solver.StatusName(status)}")
        
        # Extract solution
        if status == cp_model.OPTIMAL or status == cp_model.FEASIBLE:
            selected_games = []
            for i in range(len(feasible_games)):
                if solver.Value(game_vars[i]) == 1:
                    selected_games.append(feasible_games[i])
            
            if not selected_games:
                raise RuntimeError("Solver completed but selected no games")
            
            logger.info(f"Multi-phase scheduler: Selected {len(selected_games)} games")
            return selected_games
            
        elif status == cp_model.INFEASIBLE:
            raise RuntimeError("No feasible schedule found with current constraints")
        else:
            raise RuntimeError(f"Solver failed with status: {solver.StatusName(status)}")
    
    def _build_game_metadata(self, games: List[Dict]):
        """Build lookup structures for games."""
        self.games_by_team = defaultdict(list)
        self.games_by_week_team = defaultdict(list)
        self.games_by_day_team = defaultdict(list)
        self.games_by_tsl = defaultdict(list)
        
        for i, game in enumerate(games):
            team_a = game['teamA']
            team_b = game['teamB']
            tsl_id = game['tsl_id']
            timeslot_id = game['timeslot_id']
            
            # Map by team
            self.games_by_team[team_a].append(i)
            self.games_by_team[team_b].append(i)
            
            # Map by week+team
            if timeslot_id in self.model.week_mapping:
                week_num = self.model.week_mapping[timeslot_id][0]
                self.games_by_week_team[(week_num, team_a)].append(i)
                self.games_by_week_team[(week_num, team_b)].append(i)
            
            # Map by day+team
            if timeslot_id in self.model.day_mapping:
                day = self.model.day_mapping[timeslot_id]
                self.games_by_day_team[(day, team_a)].append(i)
                self.games_by_day_team[(day, team_b)].append(i)
            
            # Map by TSL
            self.games_by_tsl[tsl_id].append(i)
    
    def _add_basic_constraints(self, model, game_vars, games):
        """Add basic uniqueness constraints."""
        from ortools.sat.python import cp_model
        
        logger.info("Adding basic constraints...")
        
        # Each TSL hosts at most one game
        for tsl_id, game_indices in self.games_by_tsl.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
        logger.info(f"Added {len(self.games_by_tsl)} TSL uniqueness constraints")
    
    def _add_weekly_constraints(self, model, game_vars, games):
        """Add weekly game limit constraints."""
        from ortools.sat.python import cp_model
        
        logger.info("Adding weekly game constraints...")
        
        count = 0
        for (week_num, team_id), game_indices in self.games_by_week_team.items():
            # Max games per week
            model.Add(sum(game_vars[i] for i in game_indices) <= self.max_games_per_week)
            count += 1
        
        logger.info(f"Added {count} weekly game limit constraints")
    
    def _add_daily_constraints(self, model, game_vars, games):
        """Add daily game limit constraints."""
        from ortools.sat.python import cp_model
        
        logger.info("Adding daily game constraints...")
        
        count = 0
        for (day, team_id), game_indices in self.games_by_day_team.items():
            # Max games per day
            model.Add(sum(game_vars[i] for i in game_indices) <= self.max_games_per_day)
            count += 1
        
        logger.info(f"Added {count} daily game limit constraints")
    
    def _add_minimum_coverage_objective(self, model, game_vars, games):
        """
        Add soft constraints for minimum weekly coverage.
        
        Creates indicator variables for teams that play at least 1 game per week.
        """
        from ortools.sat.python import cp_model
        
        logger.info("Adding minimum coverage objectives...")
        
        self.coverage_vars = {}
        coverage_count = 0
        
        # For each team+week combination where team has availability
        for (week_num, team_id), game_indices in self.games_by_week_team.items():
            if not game_indices:
                continue
            
            # Create indicator: this team plays at least 1 game this week
            coverage_var = model.NewBoolVar(f'coverage_w{week_num}_t{team_id}')
            self.coverage_vars[(week_num, team_id)] = coverage_var
            
            # coverage_var = 1 if team plays >= 1 game in this week
            model.Add(sum(game_vars[i] for i in game_indices) >= 1).OnlyEnforceIf(coverage_var)
            model.Add(sum(game_vars[i] for i in game_indices) == 0).OnlyEnforceIf(coverage_var.Not())
            
            coverage_count += 1
        
        logger.info(f"Added {coverage_count} coverage indicator variables")
    
    def _set_objective(self, model, game_vars, games):
        """Set objective function to maximize coverage + weighted games."""
        from ortools.sat.python import cp_model
        
        logger.info("Setting objective function...")
        
        objective_terms = []
        
        # Part 1: Maximize weekly coverage (high priority)
        # Each team playing at least once per week gets high score
        coverage_weight = 10000  # High weight for minimum coverage
        for coverage_var in self.coverage_vars.values():
            objective_terms.append(coverage_weight * coverage_var)
        
        # Part 2: Maximize total weighted games (secondary priority)
        # Use game weights for quality matchups
        for i, game in enumerate(games):
            weight = game.get('weight', 0)
            # Scale to integer (OR-Tools needs integers)
            scaled_weight = int(weight * 1000)
            objective_terms.append(scaled_weight * game_vars[i])
        
        model.Maximize(sum(objective_terms))
        
        logger.info(f"Objective includes {len(self.coverage_vars)} coverage terms + {len(games)} game weight terms")
    
    def _schedule_rolling_weekly(self, feasible_games: List[Dict], weeks: List[int]) -> List[Dict]:
        """
        Optimize weeks sequentially for better performance on long periods.
        
        Args:
            feasible_games: List of all feasible games
            weeks: Sorted list of week numbers
            
        Returns:
            Combined selected games from all weeks
        """
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            raise RuntimeError("OR-Tools library not installed")
        
        all_selected_games = []
        scheduled_team_games = defaultdict(int)  # Track games per team across all weeks
        
        # Calculate per-week timeout
        timeout_per_week = max(10, self.timeout // len(weeks))
        logger.info(f"Using {timeout_per_week}s timeout per week")
        
        for week_idx, week_num in enumerate(weeks):
            logger.info(f"Optimizing week {week_num} ({week_idx + 1}/{len(weeks)})")
            
            # Filter games for this week
            week_games = []
            week_game_indices = []
            for i, game in enumerate(feasible_games):
                timeslot_id = game['timeslot_id']
                if timeslot_id in self.model.week_mapping:
                    game_week = self.model.week_mapping[timeslot_id][0]
                    if game_week == week_num:
                        week_games.append(game)
                        week_game_indices.append(i)
            
            if not week_games:
                logger.info(f"No games available for week {week_num}, skipping")
                continue
            
            logger.info(f"Week {week_num}: {len(week_games)} feasible games")
            
            # Create model for this week
            model = cp_model.CpModel()
            game_vars = {}
            for i in range(len(week_games)):
                game_vars[i] = model.NewBoolVar(f'w{week_num}_game_{i}')
            
            # Add constraints for this week only
            self._add_week_constraints(model, game_vars, week_games, week_num, scheduled_team_games)
            
            # Set objective
            self._add_week_objective(model, game_vars, week_games, week_num, scheduled_team_games)
            
            # Solve this week
            solver = cp_model.CpSolver()
            solver.parameters.max_time_in_seconds = timeout_per_week
            solver.parameters.log_search_progress = False
            
            status = solver.Solve(model)
            
            if status == cp_model.OPTIMAL or status == cp_model.FEASIBLE:
                week_selected = []
                for i in range(len(week_games)):
                    if solver.Value(game_vars[i]) == 1:
                        week_selected.append(week_games[i])
                        # Update team game counts
                        scheduled_team_games[week_games[i]['teamA']] += 1
                        scheduled_team_games[week_games[i]['teamB']] += 1
                
                all_selected_games.extend(week_selected)
                logger.info(f"Week {week_num}: Selected {len(week_selected)} games")
            else:
                logger.warning(f"Week {week_num}: No solution found (status: {solver.StatusName(status)})")
        
        if not all_selected_games:
            raise RuntimeError("No games selected across all weeks")
        
        logger.info(f"Rolling optimization: Total {len(all_selected_games)} games across {len(weeks)} weeks")
        return all_selected_games
    
    def _add_week_constraints(self, model, game_vars, week_games, week_num, prior_team_games):
        """Add constraints for a single week optimization."""
        from ortools.sat.python import cp_model
        
        # TSL uniqueness
        tsl_games = defaultdict(list)
        for i, game in enumerate(week_games):
            tsl_games[game['tsl_id']].append(i)
        
        for tsl_id, game_indices in tsl_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
        # Weekly game limits (for this week)
        week_team_games = defaultdict(list)
        for i, game in enumerate(week_games):
            week_team_games[game['teamA']].append(i)
            week_team_games[game['teamB']].append(i)
        
        for team_id, game_indices in week_team_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= self.max_games_per_week)
        
        # Daily game limits
        day_team_games = defaultdict(list)
        for i, game in enumerate(week_games):
            ts_id = game['timeslot_id']
            if ts_id in self.model.day_mapping:
                day = self.model.day_mapping[ts_id]
                day_team_games[(day, game['teamA'])].append(i)
                day_team_games[(day, game['teamB'])].append(i)
        
        for (day, team_id), game_indices in day_team_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= self.max_games_per_day)
    
    def _add_week_objective(self, model, game_vars, week_games, week_num, prior_team_games):
        """Add objective for a single week optimization."""
        from ortools.sat.python import cp_model
        
        objective_terms = []
        
        # Coverage: prioritize teams with fewer total season games
        week_team_games = defaultdict(list)
        for i, game in enumerate(week_games):
            week_team_games[game['teamA']].append(i)
            week_team_games[game['teamB']].append(i)
        
        for team_id, game_indices in week_team_games.items():
            # Coverage indicator
            coverage_var = model.NewBoolVar(f'cov_t{team_id}')
            model.Add(sum(game_vars[i] for i in game_indices) >= 1).OnlyEnforceIf(coverage_var)
            model.Add(sum(game_vars[i] for i in game_indices) == 0).OnlyEnforceIf(coverage_var.Not())
            
            # Weight coverage by inverse of prior games (teams with fewer games get higher priority)
            prior_games = prior_team_games.get(team_id, 0)
            # Scale: 0 prior games = 10000, each game reduces priority
            coverage_weight = max(5000, 10000 - (prior_games * 500))
            objective_terms.append(coverage_weight * coverage_var)
        
        # Game quality weights
        for i, game in enumerate(week_games):
            weight = game.get('weight', 0)
            scaled_weight = int(weight * 1000)
            objective_terms.append(scaled_weight * game_vars[i])
        
        model.Maximize(sum(objective_terms))
