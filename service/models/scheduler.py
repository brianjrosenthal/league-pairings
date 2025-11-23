"""
Scheduling algorithms for game pairing generation.
"""

from abc import ABC, abstractmethod
from typing import Dict, List, Set
from ..utils.exceptions import NoFeasibleGamesError


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
    Integer Linear Programming scheduler (placeholder for future implementation).
    
    This would use optimization libraries like PuLP or scipy to find
    the optimal schedule that maximizes total weight while respecting
    all constraints.
    """
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Use ILP to find optimal schedule.
        
        Args:
            feasible_games: List of feasible games with weights
            
        Returns:
            Optimal schedule
            
        Raises:
            NotImplementedError: This is a placeholder for future work
        """
        raise NotImplementedError(
            "ILP scheduler not yet implemented. Use 'greedy' algorithm instead."
        )


def get_scheduler(algorithm: str = "greedy") -> BaseScheduler:
    """
    Factory function to get a scheduler instance.
    
    Args:
        algorithm: Name of algorithm ('greedy' or 'ilp')
        
    Returns:
        Scheduler instance
        
    Raises:
        ValueError: If algorithm name is not recognized
    """
    schedulers = {
        "greedy": GreedyScheduler,
        "ilp": ILPScheduler
    }
    
    scheduler_class = schedulers.get(algorithm.lower())
    
    if scheduler_class is None:
        raise ValueError(
            f"Unknown algorithm '{algorithm}'. "
            f"Available: {', '.join(schedulers.keys())}"
        )
    
    return scheduler_class()
