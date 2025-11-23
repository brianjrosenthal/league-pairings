"""
Scheduling algorithms for game pairing generation.
"""

from abc import ABC, abstractmethod
from typing import Dict, List, Set
from utils.exceptions import NoFeasibleGamesError


class BaseScheduler(ABC):
    """
    Abstract base class for scheduling algorithms.
    """
    
    @abstractmethod
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Select games from the feasible set to create a schedule.
        
        Args:
            feasible_games: List of all feasible game options with weights
            
        Returns:
            List of selected games for the schedule
            
        Raises:
            NoFeasibleGamesError: If no valid schedule can be created
        """
        pass


class GreedyScheduler(BaseScheduler):
    """
    Greedy scheduler that selects highest-weight games first.
    
    This is an improved greedy algorithm that:
    1. Sorts games by weight (descending)
    2. Selects games ensuring no team or TSL conflicts
    3. Prioritizes better matchups
    """
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Greedily select games by weight, avoiding conflicts.
        
        Args:
            feasible_games: List of feasible games with weights
            
        Returns:
            Selected games forming a valid schedule
            
        Raises:
            NoFeasibleGamesError: If no games can be scheduled
        """
        if not feasible_games:
            raise NoFeasibleGamesError("No feasible games available to schedule")
        
        # Sort by weight (highest first)
        sorted_games = sorted(
            feasible_games,
            key=lambda g: g.get('weight', 0),
            reverse=True
        )
        
        # Track used resources
        used_teams: Set[int] = set()
        used_tsls: Set[int] = set()
        selected_games: List[Dict] = []
        
        # Greedy selection
        for game in sorted_games:
            team_a = game['teamA']
            team_b = game['teamB']
            tsl_id = game['tsl_id']
            
            # Check if this game conflicts with already selected games
            if (team_a in used_teams or 
                team_b in used_teams or 
                tsl_id in used_tsls):
                continue  # Skip this game, it conflicts
            
            # No conflicts - select this game
            selected_games.append(game)
            used_teams.add(team_a)
            used_teams.add(team_b)
            used_tsls.add(tsl_id)
        
        if not selected_games:
            raise NoFeasibleGamesError(
                "Could not schedule any games - all options have conflicts"
            )
        
        return selected_games


class ILPScheduler(BaseScheduler):
    """
    Integer Linear Programming scheduler using PuLP.
    
    This scheduler formulates the game scheduling problem as an Integer Linear Program
    and finds the mathematically optimal solution that maximizes total weight while
    respecting all constraints:
    - Each team plays at most once
    - Each timeslot-location (TSL) hosts at most one game
    - All feasibility constraints are maintained
    """
    
    def __init__(self, timeout: int = 60):
        """
        Initialize ILP scheduler.
        
        Args:
            timeout: Maximum time in seconds for solver (default: 60)
        """
        self.timeout = timeout
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Use ILP to find optimal schedule.
        
        Args:
            feasible_games: List of feasible games with weights
            
        Returns:
            Optimal schedule
            
        Raises:
            NoFeasibleGamesError: If no valid schedule can be created
            RuntimeError: If optimization fails
        """
        import logging
        logger = logging.getLogger(__name__)
        
        if not feasible_games:
            raise NoFeasibleGamesError("No feasible games available to schedule")
        
        try:
            import pulp
        except ImportError:
            raise RuntimeError(
                "PuLP library not installed. Install with: pip install pulp"
            )
        
        logger.info(f"ILP Scheduler: Starting with {len(feasible_games)} feasible games")
        
        # Create optimization problem
        prob = pulp.LpProblem("GameScheduling", pulp.LpMaximize)
        
        logger.info("ILP Scheduler: Creating decision variables...")
        # Decision variables: binary variable for each game
        game_vars = {
            i: pulp.LpVariable(f"game_{i}", cat='Binary')
            for i in range(len(feasible_games))
        }
        
        logger.info("ILP Scheduler: Setting objective function...")
        # Objective function: maximize total weight
        prob += pulp.lpSum([
            feasible_games[i].get('weight', 0) * game_vars[i]
            for i in range(len(feasible_games))
        ]), "TotalWeight"
        
        logger.info("ILP Scheduler: Adding team uniqueness constraints...")
        # Constraint 1: Each team plays at most once
        team_games = {}
        for i, game in enumerate(feasible_games):
            team_a = game['teamA']
            team_b = game['teamB']
            
            if team_a not in team_games:
                team_games[team_a] = []
            if team_b not in team_games:
                team_games[team_b] = []
            
            team_games[team_a].append(i)
            team_games[team_b].append(i)
        
        for team_id, game_indices in team_games.items():
            prob += (
                pulp.lpSum([game_vars[i] for i in game_indices]) <= 1,
                f"Team_{team_id}_Uniqueness"
            )
        
        logger.info(f"ILP Scheduler: Added constraints for {len(team_games)} teams")
        logger.info("ILP Scheduler: Adding TSL uniqueness constraints...")
        
        # Constraint 2: Each TSL (timeslot-location) hosts at most one game
        tsl_games = {}
        for i, game in enumerate(feasible_games):
            tsl_id = game['tsl_id']
            
            if tsl_id not in tsl_games:
                tsl_games[tsl_id] = []
            
            tsl_games[tsl_id].append(i)
        
        for tsl_id, game_indices in tsl_games.items():
            prob += (
                pulp.lpSum([game_vars[i] for i in game_indices]) <= 1,
                f"TSL_{tsl_id}_Uniqueness"
            )
        
        logger.info(f"ILP Scheduler: Added constraints for {len(tsl_games)} TSLs")
        logger.info(f"ILP Scheduler: Problem has {len(game_vars)} variables and {len(team_games) + len(tsl_games)} constraints")
        logger.info(f"ILP Scheduler: Starting solver (timeout: {self.timeout}s)...")
        
        # Solve the problem
        # Use CBC solver with time limit
        solver = pulp.PULP_CBC_CMD(
            msg=0,  # Suppress solver output
            timeLimit=self.timeout
        )
        
        status = prob.solve(solver)
        
        logger.info(f"ILP Scheduler: Solver completed with status: {pulp.LpStatus[status]}")
        
        # Check solution status
        if status != pulp.LpStatusOptimal:
            if status == pulp.LpStatusInfeasible:
                raise NoFeasibleGamesError(
                    "ILP solver found no feasible solution"
                )
            elif status == pulp.LpStatusNotSolved:
                raise RuntimeError(
                    f"ILP solver did not complete within {self.timeout} seconds"
                )
            else:
                raise RuntimeError(
                    f"ILP solver failed with status: {pulp.LpStatus[status]}"
                )
        
        # Extract selected games
        selected_games = []
        for i, var in game_vars.items():
            if var.value() == 1:
                selected_games.append(feasible_games[i])
        
        if not selected_games:
            raise NoFeasibleGamesError(
                "ILP solver completed but selected no games"
            )
        
        return selected_games


class ORToolsScheduler(BaseScheduler):
    """
    Google OR-Tools CP-SAT scheduler (fastest option).
    
    Uses Google's CP-SAT solver which is highly optimized for constraint
    satisfaction and integer programming problems. Significantly faster
    than PuLP, especially for large-scale scheduling.
    """
    
    def __init__(self, timeout: int = 60):
        """
        Initialize OR-Tools scheduler.
        
        Args:
            timeout: Maximum time in seconds for solver (default: 60)
        """
        self.timeout = timeout
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Use Google OR-Tools to find optimal schedule.
        
        Args:
            feasible_games: List of feasible games with weights
            
        Returns:
            Optimal schedule
            
        Raises:
            NoFeasibleGamesError: If no valid schedule can be created
            RuntimeError: If optimization fails
        """
        import logging
        logger = logging.getLogger(__name__)
        
        if not feasible_games:
            raise NoFeasibleGamesError("No feasible games available to schedule")
        
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            raise RuntimeError(
                "OR-Tools library not installed. Install with: pip install ortools"
            )
        
        logger.info(f"OR-Tools Scheduler: Starting with {len(feasible_games)} feasible games")
        
        # Create the model
        model = cp_model.CpModel()
        
        logger.info("OR-Tools Scheduler: Creating decision variables...")
        # Decision variables: binary variable for each game
        game_vars = {}
        for i in range(len(feasible_games)):
            game_vars[i] = model.NewBoolVar(f'game_{i}')
        
        logger.info("OR-Tools Scheduler: Adding team uniqueness constraints...")
        # Constraint 1: Each team plays at most once
        team_games = {}
        for i, game in enumerate(feasible_games):
            team_a = game['teamA']
            team_b = game['teamB']
            
            if team_a not in team_games:
                team_games[team_a] = []
            if team_b not in team_games:
                team_games[team_b] = []
            
            team_games[team_a].append(i)
            team_games[team_b].append(i)
        
        for team_id, game_indices in team_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
        logger.info(f"OR-Tools Scheduler: Added constraints for {len(team_games)} teams")
        logger.info("OR-Tools Scheduler: Adding TSL uniqueness constraints...")
        
        # Constraint 2: Each TSL hosts at most one game
        tsl_games = {}
        for i, game in enumerate(feasible_games):
            tsl_id = game['tsl_id']
            
            if tsl_id not in tsl_games:
                tsl_games[tsl_id] = []
            
            tsl_games[tsl_id].append(i)
        
        for tsl_id, game_indices in tsl_games.items():
            model.Add(sum(game_vars[i] for i in game_indices) <= 1)
        
        logger.info(f"OR-Tools Scheduler: Added constraints for {len(tsl_games)} TSLs")
        
        # Objective: maximize total weight
        # OR-Tools works with integers, so scale weights by 1000
        logger.info("OR-Tools Scheduler: Setting objective function...")
        scaled_weights = []
        for i, game in enumerate(feasible_games):
            weight = game.get('weight', 0)
            scaled_weight = int(weight * 1000)  # Scale to integer
            scaled_weights.append(scaled_weight * game_vars[i])
        
        model.Maximize(sum(scaled_weights))
        
        logger.info(f"OR-Tools Scheduler: Problem has {len(game_vars)} variables and {len(team_games) + len(tsl_games)} constraints")
        logger.info(f"OR-Tools Scheduler: Starting solver (timeout: {self.timeout}s)...")
        
        # Create solver and solve
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = self.timeout
        solver.parameters.log_search_progress = False
        
        status = solver.Solve(model)
        
        logger.info(f"OR-Tools Scheduler: Solver completed with status: {solver.StatusName(status)}")
        
        # Check solution status
        if status == cp_model.OPTIMAL or status == cp_model.FEASIBLE:
            # Extract selected games
            selected_games = []
            for i in range(len(feasible_games)):
                if solver.Value(game_vars[i]) == 1:
                    selected_games.append(feasible_games[i])
            
            if not selected_games:
                raise NoFeasibleGamesError(
                    "OR-Tools solver completed but selected no games"
                )
            
            logger.info(f"OR-Tools Scheduler: Selected {len(selected_games)} games (objective value: {solver.ObjectiveValue() / 1000:.3f})")
            return selected_games
            
        elif status == cp_model.INFEASIBLE:
            raise NoFeasibleGamesError(
                "OR-Tools solver found no feasible solution"
            )
        else:
            raise RuntimeError(
                f"OR-Tools solver failed with status: {solver.StatusName(status)}"
            )


def get_scheduler(algorithm: str = "greedy") -> BaseScheduler:
    """
    Factory function to get a scheduler instance.
    
    Args:
        algorithm: Name of algorithm ('greedy', 'ilp', or 'ortools')
        
    Returns:
        Scheduler instance
        
    Raises:
        ValueError: If algorithm name is not recognized
    """
    schedulers = {
        "greedy": GreedyScheduler,
        "ilp": ILPScheduler,
        "ortools": ORToolsScheduler
    }
    
    scheduler_class = schedulers.get(algorithm.lower())
    
    if scheduler_class is None:
        raise ValueError(
            f"Unknown algorithm '{algorithm}'. "
            f"Available: {', '.join(schedulers.keys())}"
        )
    
    return scheduler_class()
