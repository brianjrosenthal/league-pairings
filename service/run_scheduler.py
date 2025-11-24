#!/usr/bin/env python
"""
Command-line interface for running the scheduler.

Usage:
    python run_scheduler.py --start-date 2025-12-01 --end-date 2025-12-08 --stop-after 1A
"""

import argparse
import json
import sys
from datetime import datetime

from config_local import DATABASE_CONFIG, SCHEDULING_CONFIG
from generate_pairings import ScheduleGenerator


def _print_schedule_by_division(schedule):
    """Print schedule grouped by week and division, sorted by week then division."""
    from collections import defaultdict
    from datetime import datetime, timedelta
    
    if not schedule:
        print("\nNo games scheduled.\n")
        return
    
    # Find the earliest date to determine week 0 start (Sunday on or before earliest date)
    dates = []
    for game in schedule:
        date_str = game.get('date')
        if date_str:
            try:
                game_date = datetime.strptime(str(date_str), '%Y-%m-%d').date()
                dates.append(game_date)
            except (ValueError, TypeError):
                pass
    
    if not dates:
        print("\nNo valid dates in schedule.\n")
        return
    
    min_date = min(dates)
    
    # Find the Sunday on or before min_date (weekday 6 = Sunday)
    days_since_sunday = (min_date.weekday() + 1) % 7  # Convert to days since Sunday
    week_0_start = min_date - timedelta(days=days_since_sunday)
    
    # Group games by (week, division)
    games_by_week_division = defaultdict(list)
    for game in schedule:
        division = game.get('division') or game.get('division_name', 'Unknown Division')
        
        date_str = game.get('date')
        if date_str:
            try:
                game_date = datetime.strptime(str(date_str), '%Y-%m-%d').date()
                # Calculate week number (Sunday = start of week)
                days_diff = (game_date - week_0_start).days
                week_num = days_diff // 7
                
                games_by_week_division[(week_num, division)].append(game)
            except (ValueError, TypeError):
                games_by_week_division[(0, division)].append(game)
        else:
            games_by_week_division[(0, division)].append(game)
    
    # Sort by week number first, then division name
    sorted_keys = sorted(games_by_week_division.keys(), key=lambda x: (x[0], x[1]))
    
    # Print each week-division combination
    for week_num, division_name in sorted_keys:
        games = games_by_week_division[(week_num, division_name)]
        
        # Sort games by date and time
        games.sort(key=lambda g: (g.get('date', ''), g.get('time_modifier', '')))
        
        print(f"\n{division_name} (Week {week_num}):")
        for game in games:
            date = game.get('date', 'N/A')
            time = game.get('time_modifier', 'N/A')
            location = game.get('location', 'N/A')
            
            # Handle different naming conventions
            team1 = game.get('team_a') or game.get('team_1_name') or game.get('teamA_name', 'N/A')
            team2 = game.get('team_b') or game.get('team_2_name') or game.get('teamB_name', 'N/A')
            
            print(f"  - {date} {time:8s} {location:15s} {team1} vs {team2}")
    
    print()  # Extra newline at end


def main():
    parser = argparse.ArgumentParser(
        description='Run the multi-phase game scheduler',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Run Phase 1A only for a week
  python run_scheduler.py --start-date 2025-12-01 --end-date 2025-12-08 --stop-after 1A
  
  # Run through Phase 1B
  python run_scheduler.py --start-date 2025-12-01 --end-date 2025-12-08 --stop-after 1B
  
  # Run all phases (full schedule)
  python run_scheduler.py --start-date 2025-12-01 --end-date 2025-12-31
  
  # Use different algorithm
  python run_scheduler.py --start-date 2025-12-01 --end-date 2025-12-08 --algorithm greedy
        """
    )
    
    parser.add_argument(
        '--start-date',
        required=True,
        help='Start date in YYYY-MM-DD format'
    )
    
    parser.add_argument(
        '--end-date',
        required=True,
        help='End date in YYYY-MM-DD format'
    )
    
    parser.add_argument(
        '--algorithm',
        default='ortools',
        choices=['greedy', 'ilp', 'ortools', 'multi_phase'],
        help='Scheduling algorithm (default: ortools, which uses multi-phase)'
    )
    
    parser.add_argument(
        '--stop-after',
        choices=['1A', '1B', '1C', '2'],
        help='Stop after this phase (for debugging). Options: 1A, 1B, 1C, 2'
    )
    
    parser.add_argument(
        '--timeout',
        type=int,
        default=120,
        help='Timeout in seconds (default: 120)'
    )
    
    parser.add_argument(
        '--output',
        help='Output file for results (JSON). If not specified, prints to stdout'
    )
    
    parser.add_argument(
        '--verbose',
        action='store_true',
        help='Enable verbose logging'
    )
    
    args = parser.parse_args()
    
    # Set logging level
    if args.verbose:
        import logging
        logging.getLogger().setLevel(logging.DEBUG)
    
    # Parse dates
    try:
        start_date = datetime.strptime(args.start_date, '%Y-%m-%d').date()
        end_date = datetime.strptime(args.end_date, '%Y-%m-%d').date()
    except ValueError as e:
        print(f"Error: Invalid date format. Use YYYY-MM-DD. {e}", file=sys.stderr)
        sys.exit(1)
    
    # Validate date range
    if end_date < start_date:
        print("Error: End date must be after start date", file=sys.stderr)
        sys.exit(1)
    
    # Create generator
    generator = ScheduleGenerator(DATABASE_CONFIG, SCHEDULING_CONFIG)
    
    # Generate schedule
    try:
        print(f"\n{'='*60}")
        print(f"Running Scheduler")
        print(f"{'='*60}")
        print(f"Start Date:      {args.start_date}")
        print(f"End Date:        {args.end_date}")
        print(f"Algorithm:       {args.algorithm}")
        print(f"Timeout:         {args.timeout}s")
        if args.stop_after:
            print(f"Stop After:      Phase {args.stop_after}")
        print(f"{'='*60}\n")
        
        result = generator.generate_schedule(
            start_date=start_date,
            end_date=end_date,
            algorithm=args.algorithm,
            timeout=args.timeout,
            stop_after_phase=args.stop_after
        )
        
        # Print summary
        print(f"\n{'='*60}")
        print(f"Schedule Generation Complete")
        print(f"{'='*60}")
        print(f"Total Games:     {result['metadata']['total_games']}")
        print(f"Feasible Games:  {result['metadata']['feasible_games_count']}")
        print(f"Teams:           {result['metadata']['teams_count']}")
        print(f"Divisions:       {result['metadata']['divisions_count']}")
        
        if result.get('warnings'):
            print(f"\n⚠️  Warnings:")
            for warning in result['warnings']:
                print(f"  - {warning}")
        
        print(f"{'='*60}\n")
        
        # Output results
        if args.output:
            with open(args.output, 'w') as f:
                json.dump(result, f, indent=2)
            print(f"✓ Results written to {args.output}")
        else:
            _print_schedule_by_division(result['schedule'])
        
        sys.exit(0)
        
    except Exception as e:
        print(f"\n❌ Error: {e}", file=sys.stderr)
        if args.verbose:
            import traceback
            traceback.print_exc()
        sys.exit(1)


if __name__ == '__main__':
    main()
