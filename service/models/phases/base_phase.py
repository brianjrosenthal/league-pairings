"""
Base phase interface.

All scheduling phases must implement this interface.
"""

from abc import ABC, abstractmethod
from typing import Dict, List
import logging

from .scheduling_state import SchedulingState

logger = logging.getLogger(__name__)


class BasePhase(ABC):
    """
    Abstract base class for scheduling phases.
    
    Each phase receives:
    - Current scheduling state
    - Feasible games for the week
    - DataModel with mappings
    - Configuration parameters
    - Timeout
    
    And returns:
    - New scheduling state with games added
    """
    
    def __init__(
        self,
        model,
        config: Dict,
        max_games_per_week: int,
        max_games_per_day: int
    ):
        """
        Initialize phase with shared context.
        
        Args:
            model: DataModel with week/day mappings and team data
            config: Scheduling configuration
            max_games_per_week: Maximum games per team per week
            max_games_per_day: Maximum games per team per day
        """
        self.model = model
        self.config = config
        self.max_games_per_week = max_games_per_week
        self.max_games_per_day = max_games_per_day
    
    @abstractmethod
    def schedule(
        self,
        state: SchedulingState,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> SchedulingState:
        """
        Execute this phase's scheduling algorithm.
        
        Args:
            state: Current scheduling state
            feasible_games: All feasible games for this week
            week_num: Current week number
            timeout: Time limit for this phase
            
        Returns:
            New scheduling state with games added by this phase
        """
        pass
    
    @abstractmethod
    def get_phase_name(self) -> str:
        """Get human-readable phase name for logging."""
        pass
