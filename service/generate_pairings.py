"""
Main scheduling orchestrator.

This module coordinates all components to generate optimal game schedules.
"""

from datetime import date, datetime
from typing import Dict, List, Optional
import logging

from models.database import Database
from models.data_model import DataModel, FeasibleGameGenerator
from models.scheduler import get_scheduler
from utils.constraints import ConstraintChecker
from utils.weights import WeightCalculator
from utils.exceptions import (
    SchedulingError,
    DatabaseError,
    NoFeasibleGamesError,
    InsufficientDataError
)

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class ScheduleGenerator:
    """
    Main orchestrator for schedule generation.
    """
    
    def __init__(
        self,
        db_config: Dict,
        scheduling_config: Dict
    ):
        """
        Initialize the schedule generator.
        
        Args:
            db_config: Database configuration
            scheduling_config: Scheduling algorithm parameters
        """
        self.db = Database(db_config)
        self.config = scheduling_config
    
    def generate_schedule(
        self,
        start_date: date,
        end_date: date,
        algorithm: str = "greedy",
        timeout: int = 120,
        stop_after_phase: Optional[str] = None
    ) -> Dict:
        """
        Generate a complete schedule for the given date range.
        
        Args:
            start_date: Start of scheduling period
            end_date: End of scheduling period
            algorithm: Scheduling algorithm to use ('greedy' or 'ilp')
            timeout: Maximum time in seconds for solver
            stop_after_phase: Stop after this phase for debugging (e.g., '1A', '1B', '1C', '2')
            
        Returns:
            Dictionary containing:
                - schedule: List of selected games
                - metadata: Information about the generation process
                - warnings: Any warnings or issues encountered
                
        Raises:
            SchedulingError: If schedule generation fails
        """
        try:
            logger.info(f"Generating schedule for {start_date} to {end_date}")
            logger.info(f"Using algorithm: {algorithm}")
            
            # Step 1: Fetch data from database
            logger.info("Fetching data from database...")
            raw_data = self.db.fetch_all_data(start_date, end_date)
            
            # Validate we have data
            if not raw_data['teams']:
                raise InsufficientDataError("No teams found in database")
            if not raw_data['timeslots']:
                raise InsufficientDataError(
                    f"No timeslots found between {start_date} and {end_date}"
                )
            
            # Step 2: Build data model
            logger.info("Building data model...")
            model = DataModel(raw_data)
            
            # Step 3: Generate feasible games
            logger.info("Generating feasible games...")
            game_generator = FeasibleGameGenerator(model)
            feasible_games = game_generator.generate()
            
            logger.info(f"Generated {len(feasible_games)} feasible games")
            
            if not feasible_games:
                raise NoFeasibleGamesError(
                    "No feasible games could be generated with current constraints"
                )
            
            # Step 4: Initialize constraint checker
            logger.info("Initializing constraint checker...")
            constraint_checker = ConstraintChecker(
                model.previous_games,
                self.config
            )
            
            # Step 5: Calculate weights
            logger.info("Calculating game weights...")
            weight_calculator = WeightCalculator(
                model.teams,
                constraint_checker,
                self.config,
                model.team_season_games
            )
            weighted_games = weight_calculator.apply_weights_to_games(feasible_games)
            
            # Step 6: Run scheduling algorithm
            logger.info(f"Running {algorithm} scheduler with {timeout}s timeout...")
            if stop_after_phase:
                logger.info(f"Will stop after phase: {stop_after_phase}")
            if algorithm == 'multi_phase' or algorithm == 'ortools':
                # Use true multi-phase scheduler: Phase 1 (coverage) + Phase 2 (greedy filling)
                from models.true_multi_phase_scheduler import TrueMultiPhaseScheduler
                scheduler = TrueMultiPhaseScheduler(model, self.config, timeout, stop_after_phase)
                selected_games = scheduler.schedule(weighted_games)
            else:
                # Use legacy schedulers (greedy, ilp)
                scheduler = get_scheduler(algorithm)
                selected_games = scheduler.schedule(weighted_games)
            
            logger.info(f"Selected {len(selected_games)} games for schedule")
            
            # Step 7: Analyze results and generate warnings
            warnings = self._generate_warnings(model, selected_games, feasible_games)
            
            # Step 8: Build response
            result = {
                "success": True,
                "schedule": self._format_schedule(selected_games),
                "metadata": {
                    "start_date": start_date.isoformat(),
                    "end_date": end_date.isoformat(),
                    "algorithm": algorithm,
                    "total_games": len(selected_games),
                    "feasible_games_count": len(feasible_games),
                    "divisions_count": len(model.divisions),
                    "teams_count": len(model.teams),
                    "generated_at": datetime.now().isoformat()
                },
                "warnings": warnings
            }
            
            logger.info("Schedule generation complete!")
            return result
            
        except (DatabaseError, NoFeasibleGamesError, InsufficientDataError) as e:
            logger.error(f"Schedule generation failed: {e}")
            raise
        except Exception as e:
            logger.error(f"Unexpected error during schedule generation: {e}")
            raise SchedulingError(f"Schedule generation failed: {e}")
    
    def _format_schedule(self, games: List[Dict]) -> List[Dict]:
        """
        Format games into a clean schedule structure.
        
        Args:
            games: List of selected games
            
        Returns:
            Formatted schedule list
        """
        formatted = []
        
        for i, game in enumerate(games):
            try:
                # Convert date to string if needed
                game_date = game.get('date')
                if game_date is None:
                    logger.warning(f"Game {i} has no date field")
                    game_date = 'UNKNOWN'
                elif hasattr(game_date, 'isoformat'):
                    game_date = game_date.isoformat()
                elif hasattr(game_date, 'strftime'):
                    game_date = game_date.strftime('%Y-%m-%d')
                elif isinstance(game_date, str):
                    game_date = game_date
                else:
                    # Fallback: convert to string
                    logger.warning(f"Game {i} date is unexpected type: {type(game_date)}")
                    game_date = str(game_date)
                
                formatted.append({
                    "game_id": game.get('game_id'),
                    "date": game_date,
                    "time_modifier": game.get('modifier', ''),
                    "timeslot_id": game.get('timeslot_id'),
                    "location": game.get('location_name', 'Unknown'),
                    "location_id": game.get('location_id'),
                    "division": game.get('division_name', 'Unknown'),
                    "division_id": game.get('division_id'),
                    "team_a": game.get('teamA_name', 'Unknown'),
                    "team_a_id": game.get('teamA'),
                    "team_b": game.get('teamB_name', 'Unknown'),
                    "team_b_id": game.get('teamB'),
                    "weight": round(game.get('weight', 0), 3)
                })
            except Exception as e:
                logger.error(f"Error formatting game {i}: {e}")
                logger.error(f"Game data: {game}")
                raise
        
        # Sort by date, then location
        formatted.sort(key=lambda g: (g['date'], g['location']))
        
        return formatted
    
    def _generate_warnings(
        self,
        model: DataModel,
        selected_games: List[Dict],
        feasible_games: List[Dict]
    ) -> List[str]:
        """
        Generate warnings about potential scheduling issues.
        
        Args:
            model: Data model
            selected_games: Games that were selected
            feasible_games: All feasible games
            
        Returns:
            List of warning messages
        """
        warnings = []
        
        # Check which teams didn't get scheduled
        scheduled_teams = set()
        for game in selected_games:
            scheduled_teams.add(game['teamA'])
            scheduled_teams.add(game['teamB'])
        
        all_teams = set(t['team_id'] for t in model.teams)
        unscheduled_teams = all_teams - scheduled_teams
        
        if unscheduled_teams:
            # Build list of unscheduled teams with their divisions
            unscheduled_details = []
            for tid in unscheduled_teams:
                team = model.team_lookup.get(tid)
                if team:
                    team_name = team['name']
                    division_name = model.get_division_name(team['division_id'])
                    unscheduled_details.append(f"{team_name} ({division_name})")
            
            # Sort for consistent display
            unscheduled_details.sort()
            
            warnings.append(
                f"{len(unscheduled_teams)} team(s) could not be scheduled: "
                f"{', '.join(unscheduled_details)}"
            )
        
        # Check division capacity
        for div_id, teams in model.teams_by_division.items():
            div_games = [g for g in selected_games if g['division_id'] == div_id]
            needed_slots = (len(teams) + 1) // 2
            
            if len(div_games) < needed_slots:
                div_name = model.get_division_name(div_id)
                warnings.append(
                    f"{div_name}: Only scheduled {len(div_games)} of "
                    f"{needed_slots} needed games"
                )
        
        return warnings
