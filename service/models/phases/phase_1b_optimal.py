"""
Phase 1B: Comprehensive Optimal Scheduling

Schedules remaining unscheduled teams optimally using OR-Tools.
Considers both unscheduled vs unscheduled and unscheduled vs scheduled games.
"""

from typing import Dict, List
import logging

from .base_phase import BasePhase
from .scheduling_state import SchedulingState

logger = logging.getLogger(__name__)


class Phase1BOptimal(BasePhase):
    """
    Phase 1B: Optimal scheduling for remaining unscheduled teams.
    
    TODO: Extract from true_multi_phase_scheduler.py
    For now, returns state unchanged.
    """
    
    def get_phase_name(self) -> str:
        return "Phase 1B: Comprehensive Optimal"
    
    def schedule(
        self,
        state: SchedulingState,
        feasible_games: List[Dict],
        week_num: int,
        timeout: int
    ) -> SchedulingState:
        """
        Execute Phase 1B scheduling.
        
        Args:
            state: Current scheduling state
            feasible_games: All feasible games for this week
            week_num: Current week number
            timeout: Time limit for this phase
            
        Returns:
            New state with Phase 1B games added
        """
        logger.info(f"Phase 1B Week {week_num}: TODO - Implementation pending")
        # TODO: Port logic from true_multi_phase_scheduler._phase1b_optimal_remaining
        return state
