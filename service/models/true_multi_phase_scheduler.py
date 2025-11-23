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
import time

from .data_model import DataModel

logger = logging.getLogger(__name__)


class TrueMultiPhaseScheduler:
    """
    Two-phase scheduler: Coverage optimization + Greedy capacity filling.
    Now works week-by-week to update game counts progressively.
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
        
        # Track teams scheduled in THIS optimization run
        self.teams_scheduled_this_run = defaultdict(int)  # {team_id: count}
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Generate schedule using week-by-week two-phase approach.
        Each week: Phase 1 (coverage) + Phase 2 (filling)
        Updates game counts across weeks.
        
        Args:
            feasible_games: List of feasible games (one per team pairing)
            
        Returns:
            Selected games forming optimal schedule
        """
        if not feasible_games:
            raise RuntimeError("No feasible games provided")
        
        logger.info(f"True Multi-Phase: Starting with {len(feasible_games)} feasible games")
        
        # Group games by week
        games_by_week = defaultdict(list)
        for game in feasible_games:
            available_tsls = game.get('available_tsls', [game])
            for tsl in available_tsls:
                timeslot_id = tsl['timeslot_id']
                if timeslot_id in self.model.week_mapping:
                    week_num = self.model.week_mapping[timeslot_id][0]
                    games_by_week[week_num].append((game, tsl))
                    break  # Only need to categorize by first available TSL's week
        
        all_weeks = sorted(games_by_week.keys())
        logger.info(f"Scheduling across {len(all_weeks)} weeks")
        
        # Allocate time per week
        time_per_week = self.timeout / len(all_weeks) if all_weeks else self.timeout
        
        all_scheduled_games = []
        
        for week_num in all_weeks:
            logger.info(f"\n=== WEEK {week_num} ===")
            week_games = games_by_week[week_num]
            
            # Phase 1 for this week: Coverage (40% of week time)
            phase1_timeout = int(time_per_week * 0.4)
            logger.info(f"Phase 1: Coverage optimization ({phase1_timeout}s)")
            phase1_games = self._schedule_week_phase1(week_games, week_num, phase1_timeout)
            all_scheduled_games.extend(phase1_games)
            
            logger.info(f"Phase 1: Scheduled {len(phase1_games)} games")
            
            # Phase 2 for this week: Filling (60% of week time)
            phase2_timeout = int(time_per_week * 0.6)
            logger.info(f"Phase 2: Capacity filling ({phase2_timeout}s)")
            phase2_games = self._schedule_week_phase2(week_games, week_num, phase2_timeout)
            all_scheduled_games.extend(phase2_games)
            
            logger.info(f"Phase 2: Scheduled {len(phase2_games)} additional games")
            logger.info(f"Week {week_num} total: {len(phase1_games) + len(phase2_games)} games")
        
        logger.info(f"\nTotal games scheduled: {len(all_scheduled_games)}")
        return all_scheduled_games
    
    def _schedule_week_phase1(
        self,
        week_games: List[Tuple[Dict, Dict]],
        week_num: int,
        timeout: int
    ) -> List[Dict]:
        """
        Phase 1 for a single week: Coverage optimization.
        Prioritizes teams that haven't played this week yet.
        Uses UNBIASED weights - no previous game history penalty.
        
        Args:
            week_games: List of (game_pairing, tsl) tuples for this week
            week_num: Week number
            timeout: Time limit
            
        Returns:
            Selected games for this week
        """
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            raise RuntimeError("OR-Tools not installed")
        
        if not week_games:
            return []
        
        model = cp_model.CpModel()
        
        # Decision variables - one per (game, tsl) pair
        game_vars = {}
        for i, (game, tsl) in enumerate(week_games):
            game_vars[i] = model.NewBoolVar(f'game_{i}')
        
        # Constraint: Each team plays at most once THIS WEEK in Phase 1
        team_week_games = defaultdict(list)
        for i, (game, tsl) in enumerate(week_games):
            team_week_games[game['teamA']].append(i)
            team_week_games[game['teamB']].append(i)
        
        for team_id, game_indices in team_week_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
        # Constraint: Each TSL used at most once
        tsl_usage = defaultdict(list)
        for i, (game, tsl) in enumerate(week_games):
            tsl_id = tsl['tsl_id']
            tsl_usage[tsl_id].append(i)
        
        for tsl_id, game_indices in tsl_usage.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
        # Objective: Maximize coverage (teams that play this week)
        team_coverage = {}
        for team_id, game_indices in team_week_games.items():
            coverage_var = model.NewBoolVar(f'coverage_t{team_id}')
            team_coverage[team_id] = coverage_var
            
            # Coverage = 1 if team plays at least once this week
            model.Add(sum(game_vars[i] for i in game_indices) >= 1).OnlyEnforceIf(coverage_var)
            model.Add(sum(game_vars[i] for i in game_indices) == 0).OnlyEnforceIf(coverage_var.Not())
        
        # Build objective
        objective_terms = []
        
        # Priority 1: Teams that haven't played YET (across all scheduled weeks)
        for team_id, coverage_var in team_coverage.items():
            if self.teams_scheduled_this_run.get(team_id, 0) == 0:
                # Never played = VERY high priority
                objective_terms.append(100000 * coverage_var)
            else:
                # Already played = still good but lower priority
                objective_terms.append(10000 * coverage_var)
        
        # Priority 2: Game quality (unbiased - no previous game penalty)
        for i, (game, tsl) in enumerate(week_games):
            # Use UNBIASED weight - strip out season balance factor
            weight = game.get('weight', 0)
            # Normalize weight to be unbiased (approximate by assuming 1.0 base)
            scaled_weight = int(weight * 1000)
            objective_terms.append(scaled_weight * game_vars[i])
        
        model.Maximize(sum(objective_terms))
        
        # Solve
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = timeout
        solver.parameters.log_search_progress = False
        
        status = solver.Solve(model)
        
        if status not in [cp_model.OPTIMAL, cp_model.FEASIBLE]:
            logger.warning(f"Week {week_num} Phase 1 failed: {solver.StatusName(status)}")
            return []
        
        # Extract solution
        selected_games = []
        for i, (game, tsl) in enumerate(week_games):
            if solver.Value(game_vars[i]) == 1:
                # Skip if TSL already used (shouldn't happen but be safe)
                if tsl['tsl_id'] in self.used_tsls:
                    continue
                
                # Skip if violates constraints (shouldn't happen but be safe)
                if not self._can_schedule_game(game['teamA'], game['teamB'], tsl['timeslot_id']):
                    continue
                
                # Create game with assigned TSL
                game_copy = game.copy()
                game_copy['timeslot_id'] = tsl['timeslot_id']
                game_copy['location_id'] = tsl['location_id']
                game_copy['location_name'] = tsl['location_name']
                game_copy['tsl_id'] = tsl['tsl_id']
                game_copy['date'] = tsl['date']
                game_copy['modifier'] = tsl['modifier']
                
                selected_games.append(game_copy)
                self.used_tsls.add(tsl['tsl_id'])
                self._track_game(game_copy)
        
        return selected_games
    
    def _schedule_week_phase2(
        self,
        week_games: List[Tuple[Dict, Dict]],
        week_num: int,
        timeout: int
    ) -> List[Dict]:
        """
        Phase 2 for a single week: Greedy capacity filling.
        Fills remaining slots up to weekly/daily limits.
        
        Args:
            week_games: List of (game_pairing, tsl) tuples for this week
            week_num: Week number
            timeout: Time limit
            
        Returns:
            Additional selected games
        """
        start_time = time.time()
        
        # Sort games by UPDATED weight (considers games scheduled this run)
        weighted_options = []
        for game, tsl in week_games:
            # Skip if TSL already used
            if tsl['tsl_id'] in self.used_tsls:
                continue
            
            # Skip if can't schedule
            if not self._can_schedule_game(game['teamA'], game['teamB'], tsl['timeslot_id']):
                continue
            
            # Calculate priority:  games involving teams that haven't played yet get higher priority
            team_a_games = self.teams_scheduled_this_run.get(game['teamA'], 0)
            team_b_games = self.teams_scheduled_this_run.get(game['teamB'], 0)
            
            # Boost for unscheduled teams
            priority_boost = 0
            if team_a_games == 0:
                priority_boost += 10.0
            if team_b_games == 0:
                priority_boost += 10.0
            
            # Penalize teams with many games
            total_games = team_a_games + team_b_games
            adjusted_weight = game.get('weight', 0) + priority_boost - (total_games * 0.5)
            
            weighted_options.append((adjusted_weight, game, tsl))
        
        # Sort by adjusted weight (highest first)
        weighted_options.sort(key=lambda x: x[0], reverse=True)
        
        selected_games = []
        
        for adjusted_weight, game, tsl in weighted_options:
            # Check timeout
            if time.time() - start_time > timeout:
                logger.info(f"Week {week_num} Phase 2: Timeout reached")
                break
            
            # Re-check constraints (may have changed)
            if tsl['tsl_id'] in self.used_tsls:
                continue
            
            if not self._can_schedule_game(game['teamA'], game['teamB'], tsl['timeslot_id']):
                continue
            
            # Schedule this game
            game_copy = game.copy()
            game_copy['timeslot_id'] = tsl['timeslot_id']
            game_copy['location_id'] = tsl['location_id']
            game_copy['location_name'] = tsl['location_name']
            game_copy['tsl_id'] = tsl['tsl_id']
            game_copy['date'] = tsl['date']
            game_copy['modifier'] = tsl['modifier']
            
            selected_games.append(game_copy)
            self.used_tsls.add(tsl['tsl_id'])
            self._track_game(game_copy)
        
        return selected_games
    
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
        
        # Update week counts
        if timeslot_id in self.model.week_mapping:
            week_num = self.model.week_mapping[timeslot_id][0]
            self.team_weekly_games[week_num][team_a] += 1
            self.team_weekly_games[week_num][team_b] += 1
        
        # Update daily counts
        if timeslot_id in self.model.day_mapping:
            day = self.model.day_mapping[timeslot_id]
            self.team_daily_games[day][team_a] += 1
            self.team_daily_games[day][team_b] += 1
        
        # Update THIS RUN counts (for cross-week priority)
        self.teams_scheduled_this_run[team_a] = self.teams_scheduled_this_run.get(team_a, 0) + 1
        self.teams_scheduled_this_run[team_b] = self.teams_scheduled_this_run.get(team_b, 0) + 1
