"""
True Multi-Phase Scheduler

REFACTORED VERSION - Now uses modular phase architecture.

This file serves as a thin wrapper around the MultiPhaseController,
which delegates to individual phase classes for cleaner, more maintainable code.

Phase 1A: Pure Coverage (service/models/phases/phase_1a_coverage.py)
Phase 1B: Comprehensive Optimal (service/models/phases/phase_1b_optimal.py)
Phase 1C: Strategic Displacement (service/models/phases/phase_1c_displacement.py)
Phase 2: Greedy Capacity Filling (service/models/phases/phase_2_greedy.py)
"""

from typing import Dict, List, Optional
import logging

from .data_model import DataModel
from .phases import MultiPhaseController

logger = logging.getLogger(__name__)


class TrueMultiPhaseScheduler:
    """
    Multi-phase scheduler with modular architecture.
    
    This class now delegates to the MultiPhaseController which manages
    individual phase classes. This makes the code more maintainable and testable.
    """
    
    def __init__(self, model: DataModel, config: Dict, timeout: int = 120, stop_after_phase: Optional[str] = None):
        """
        Initialize multi-phase scheduler.
        
        Args:
            model: DataModel with week/day mappings
            config: Scheduling configuration
            timeout: Maximum time in seconds for solver
            stop_after_phase: Stop after this phase for debugging (e.g., '1A', '1B', '1C', '2')
        """
        self.model = model
        self.config = config
        self.timeout = timeout
        self.stop_after_phase = stop_after_phase
        
        # Create the controller that will orchestrate all phases
        self.controller = MultiPhaseController(
            model=model,
            config=config,
            timeout=timeout,
            stop_after_phase=stop_after_phase
        )
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Generate schedule using multi-phase approach.
        
        This is now just a thin wrapper that delegates to the controller.
        
        Args:
            feasible_games: List of feasible games
            
        Returns:
            Selected games forming optimal schedule
        """
        return self.controller.schedule(feasible_games)
