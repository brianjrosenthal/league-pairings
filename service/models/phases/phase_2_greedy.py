"""
Phase 2: Greedy Capacity Filling

Greedily fills remaining capacity up to weekly/daily limits.
Fast algorithm that prioritizes games by weight.
"""

from typing import Dict, List
import logging

from .base_phase import BasePhase
from .scheduling_state import SchedulingState

logger = logging.getLogger(__name__)


class Phase2Greedy(BasePhase):
    """
    Phase 2: Greedy capacity filling.
    
    TODO: Extract from true_multi_phase_scheduler.py
    For now, returns state unchanged.
    """
    
    def get_phase_name(self) -> str:
        return "Phase 2: Greedy Filling"
    
    def schedule(
        self,
        state: SchedulingState,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> SchedulingState:
        """
        Execute Phase 2 scheduling.
        
        Args:
            state: Current scheduling state
            feasible_games: All feasible games for this week
            week_num: Current week number
            timeout: Time limit for this phase
            
        Returns:
            New state with Phase 2 games added
        """
        logger.info(f"Phase 2 Week {week_num}: TODO - Implementation pending")
        # TODO: Port logic from true_multi_phase_scheduler._phase2_greedy_filling
        return state
