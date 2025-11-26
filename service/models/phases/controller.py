"""
Multi-Phase Scheduler Controller

Lightweight coordinator that manages the overall scheduling flow.
Delegates actual scheduling to individual phase classes.
"""

from typing import Dict, List, Optional
from collections import defaultdict
import logging

from .schedule import Schedule
from .phase_1a_coverage import Phase1ACoverage
from .phase_1b_optimal import Phase1BOptimal
from .phase_1c_displacement import Phase1CDisplacement
from .phase_2_greedy import Phase2Greedy

logger = logging.getLogger(__name__)


class MultiPhaseController:
    """
    Controller that orchestrates the multi-phase scheduling process.
    
    Responsibilities:
    - Initialize all phases with shared context
    - Manage week-by-week iteration
    - Call each phase in sequence
    - Handle stop_after_phase logic
    - Maintain scheduling state between phases
    """
    
    def __init__(self, model, config: Dict, timeout: int = 120, stop_after_phase: Optional[str] = None):
        """
        Initialize the controller.
        
        Args:
            model: DataModel with week/day mappings and team data
            config: Scheduling configuration
            timeout: Total timeout for all scheduling
            stop_after_phase: Optional phase to stop after ('1A', '1B', '1C', '2')
        """
        self.model = model
        self.config = config
        self.timeout = timeout
        self.stop_after_phase = stop_after_phase
        
        # Extract config
        self.min_games_per_week = config.get('min_games_per_week', 1)
        self.max_games_per_week = config.get('max_games_per_week', 2)
        self.max_games_per_day = config.get('max_games_per_day', 1)
        
        # Initialize all phases
        self.phase_1a = Phase1ACoverage(model, config, self.max_games_per_week, self.max_games_per_day)
        self.phase_1b = Phase1BOptimal(model, config, self.max_games_per_week, self.max_games_per_day)
        self.phase_1c = Phase1CDisplacement(model, config, self.max_games_per_week, self.max_games_per_day)
        self.phase_2 = Phase2Greedy(model, config, self.max_games_per_week, self.max_games_per_day)
    
    def schedule(self, feasible_games: List[Dict]) -> List[Dict]:
        """
        Generate schedule using multi-phase approach.
        
        Args:
            feasible_games: List of all feasible games
            
        Returns:
            List of scheduled games
        """
        if not feasible_games:
            raise RuntimeError("No feasible games provided")
        
        logger.info(f"Multi-Phase Controller: Starting with {len(feasible_games)} feasible games")
        
        # Group games by week
        games_by_week = self._group_games_by_week(feasible_games)
        
        if not games_by_week:
            logger.warning("No games could be mapped to weeks")
            return []
        
        all_weeks = sorted(games_by_week.keys())
        logger.info(f"Scheduling across {len(all_weeks)} weeks: {all_weeks}")
        
        # Calculate time allocation
        time_per_week = self.timeout / len(all_weeks) if all_weeks else self.timeout
        phase_timeouts = self._calculate_phase_timeouts(time_per_week)
        
        # Initialize schedule with constraint enforcement
        schedule = Schedule(self.model, self.max_games_per_week)
        
        # Process each week
        for week_num in all_weeks:
            week_games = games_by_week[week_num]
            logger.info(f"\n{'='*60}")
            logger.info(f"WEEK {week_num}: {len(week_games)} candidate games")
            logger.info(f"{'='*60}")
            
            schedule = self._schedule_week(schedule, week_games, week_num, phase_timeouts)
            
            # Check if we should stop after current set of completed phases
            if self.stop_after_phase and self._should_stop_after_week():
                logger.info(f"Stopping after completing phases through {self.stop_after_phase}")
                break
        
        logger.info(f"\nTotal games scheduled: {len(schedule.games)}")
        return schedule.games
    
    def _group_games_by_week(self, feasible_games: List[Dict]) -> Dict[int, List[Dict]]:
        """Group feasible games by week number."""
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
        
        return games_by_week
    
    def _calculate_phase_timeouts(self, time_per_week: float) -> Dict[str, int]:
        """Calculate timeout for each phase based on total time per week."""
        return {
            '1A': int(time_per_week * 0.10),  # 10% for Phase 1A
            '1B': int(time_per_week * 0.10),  # 10% for Phase 1B
            '1C': int(time_per_week * 0.10),  # 10% for Phase 1C
            '2': int(time_per_week * 0.70),   # 70% for Phase 2
        }
    
    def _schedule_week(
        self,
        schedule: Schedule,
        week_games: List[Dict],
        week_num: int,
        phase_timeouts: Dict[str, int]
    ) -> Schedule:
        """
        Schedule games for a single week using all applicable phases.
        
        Args:
            schedule: Current schedule with constraint enforcement
            week_games: Feasible games for this week
            week_num: Week number
            phase_timeouts: Timeout for each phase
            
        Returns:
            Updated schedule
        """
        initial_game_count = len(schedule.games)
        
        # Phase 1A: Pure Coverage
        logger.info(f"\n--- Phase 1A: Pure Coverage ({phase_timeouts['1A']}s) ---")
        schedule = self.phase_1a.schedule(schedule, week_games, week_num, phase_timeouts['1A'])
        phase_1a_games = len(schedule.games) - initial_game_count
        
        if self.stop_after_phase == '1A':
            logger.info(f"Phase 1A complete: {phase_1a_games} games scheduled")
            return schedule
        
        # Phase 1B: Comprehensive Optimal
        logger.info(f"\n--- Phase 1B: Comprehensive Optimal ({phase_timeouts['1B']}s) ---")
        phase_1b_start = len(schedule.games)
        schedule = self.phase_1b.schedule(schedule, week_games, week_num, phase_timeouts['1B'])
        phase_1b_games = len(schedule.games) - phase_1b_start
        
        if self.stop_after_phase == '1B':
            logger.info(f"Phase 1B complete: {phase_1b_games} games scheduled")
            return schedule
        
        # Phase 1C: Strategic Displacement
        logger.info(f"\n--- Phase 1C: Strategic Displacement ({phase_timeouts['1C']}s) ---")
        phase_1c_start = len(schedule.games)
        schedule = self.phase_1c.schedule(schedule, week_games, week_num, phase_timeouts['1C'])
        phase_1c_games = len(schedule.games) - phase_1c_start
        
        if self.stop_after_phase == '1C':
            logger.info(f"Phase 1C complete: {phase_1c_games} games scheduled")
            return schedule
        
        # Phase 2: Greedy Filling
        logger.info(f"\n--- Phase 2: Greedy Filling ({phase_timeouts['2']}s) ---")
        phase_2_start = len(schedule.games)
        schedule = self.phase_2.schedule(schedule, week_games, week_num, phase_timeouts['2'])
        phase_2_games = len(schedule.games) - phase_2_start
        
        # Summary for this week
        total_week_games = len(schedule.games) - initial_game_count
        logger.info(f"\nWeek {week_num} Summary:")
        logger.info(f"  Phase 1A: {phase_1a_games} games")
        logger.info(f"  Phase 1B: {phase_1b_games} games")
        logger.info(f"  Phase 1C: {phase_1c_games} games")
        logger.info(f"  Phase 2:  {phase_2_games} games")
        logger.info(f"  Total:    {total_week_games} games")
        
        return schedule
    
    def _should_stop_after_week(self) -> bool:
        """
        Check if we should stop processing more weeks.
        
        When stop_after_phase is set, we want to run that phase for all weeks
        but skip later phases. We don't stop after each week, we just skip
        the later phases for each week.
        
        Returns:
            False - we never stop early between weeks, we process all weeks
        """
        return False
