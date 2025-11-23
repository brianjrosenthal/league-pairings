"""
Data model for organizing and processing scheduling data.
"""

from typing import Dict, List, Set, Tuple
from collections import defaultdict
from datetime import datetime, timedelta


class DataModel:
    """
    Organizes raw database data into useful structures for scheduling.
    """
    
    def __init__(self, raw_data: Dict):
        """
        Initialize data model from raw database data.
        
        Args:
            raw_data: Dictionary containing all raw data from database
        """
        self.teams = raw_data["teams"]
        self.divisions = raw_data["divisions"]
        self.timeslots = raw_data["timeslots"]
        self.locations = raw_data["locations"]
        self.previous_games = raw_data["previous_games"]
        
        # Build lookup dictionaries
        self.division_lookup = {d["id"]: d["name"] for d in self.divisions}
        self.team_lookup = {t["team_id"]: t for t in self.teams}
        self.location_lookup = {l["location_id"]: l for l in self.locations}
        self.timeslot_lookup = {ts["timeslot_id"]: ts for ts in self.timeslots}
        
        # Build TSL (Timeslot-Location combinations)
        self.tsls = self._build_tsls(
            raw_data["location_availability"]
        )
        
        # Build team availability lookup
        self.team_availability = self._build_team_availability(
            raw_data["team_availability"]
        )
        
        # Group teams by division
        self.teams_by_division = self._group_teams_by_division()
        
        # Build week and day mappings for scheduling period
        self.week_mapping = self._build_week_mapping()
        self.day_mapping = self._build_day_mapping()
        
        # Calculate season game counts per team
        self.team_season_games = self._count_team_season_games()
    
    def _build_tsls(self, location_availability: List[Dict]) -> List[Dict]:
        """
        Build TSL (Timeslot-Location) combinations.
        
        Args:
            location_availability: List of location availability records
            
        Returns:
            List of TSL dictionaries with unique IDs
        """
        tsls = []
        tsl_id = 1
        
        for avail in location_availability:
            timeslot = self.timeslot_lookup.get(avail["timeslot_id"])
            location = self.location_lookup.get(avail["location_id"])
            
            if timeslot and location:
                tsls.append({
                    "tsl_id": tsl_id,
                    "timeslot_id": avail["timeslot_id"],
                    "location_id": avail["location_id"],
                    "date": timeslot["date"],
                    "modifier": timeslot["modifier"],
                    "location_name": location["name"]
                })
                tsl_id += 1
        
        return tsls
    
    def _build_team_availability(
        self, 
        team_availability: List[Dict]
    ) -> Dict[int, Set[int]]:
        """
        Build team availability lookup.
        
        Args:
            team_availability: List of team availability records
            
        Returns:
            Dictionary mapping team_id -> set of available timeslot_ids
        """
        availability = defaultdict(set)
        
        for avail in team_availability:
            availability[avail["team_id"]].add(avail["timeslot_id"])
        
        return availability
    
    def _group_teams_by_division(self) -> Dict[int, List[Dict]]:
        """
        Group teams by their division.
        
        Returns:
            Dictionary mapping division_id -> list of teams
        """
        by_division = defaultdict(list)
        
        for team in self.teams:
            by_division[team["division_id"]].append(team)
        
        return by_division
    
    def _build_week_mapping(self) -> Dict[int, Tuple[int, datetime, datetime]]:
        """
        Build mapping of timeslot_id to week information.
        Week is defined as Sunday-Saturday.
        
        Returns:
            Dictionary mapping timeslot_id -> (week_number, week_start, week_end)
        """
        if not self.timeslots:
            return {}
        
        # Find the earliest date (should be a Sunday or we'll find the previous Sunday)
        dates = [ts['date'] for ts in self.timeslots]
        if isinstance(dates[0], str):
            dates = [datetime.strptime(d, '%Y-%m-%d').date() if isinstance(d, str) else d for d in dates]
        
        min_date = min(dates)
        
        # Find the Sunday on or before min_date (week starts on Sunday = day 6 in Python)
        days_since_sunday = (min_date.weekday() + 1) % 7  # Convert to days since Sunday
        week_start = min_date - timedelta(days=days_since_sunday)
        
        week_mapping = {}
        week_number = 0
        
        for ts in self.timeslots:
            ts_date = ts['date']
            if isinstance(ts_date, str):
                ts_date = datetime.strptime(ts_date, '%Y-%m-%d').date()
            
            # Calculate which week this timeslot belongs to
            days_diff = (ts_date - week_start).days
            week_num = days_diff // 7
            
            # Calculate week boundaries
            this_week_start = week_start + timedelta(weeks=week_num)
            this_week_end = this_week_start + timedelta(days=6)
            
            week_mapping[ts['timeslot_id']] = (week_num, this_week_start, this_week_end)
        
        return week_mapping
    
    def _build_day_mapping(self) -> Dict[int, datetime]:
        """
        Build mapping of timeslot_id to date.
        
        Returns:
            Dictionary mapping timeslot_id -> date
        """
        day_mapping = {}
        
        for ts in self.timeslots:
            ts_date = ts['date']
            if isinstance(ts_date, str):
                ts_date = datetime.strptime(ts_date, '%Y-%m-%d').date()
            
            day_mapping[ts['timeslot_id']] = ts_date
        
        return day_mapping
    
    def _count_team_season_games(self) -> Dict[int, int]:
        """
        Count total games played by each team this season (from previous_games).
        
        Returns:
            Dictionary mapping team_id -> game_count
        """
        game_counts = defaultdict(int)
        
        for game in self.previous_games:
            game_counts[game['team_1_id']] += 1
            game_counts[game['team_2_id']] += 1
        
        # Ensure all teams have an entry (even if 0 games)
        for team in self.teams:
            if team['team_id'] not in game_counts:
                game_counts[team['team_id']] = 0
        
        return dict(game_counts)
    
    def get_team_name(self, team_id: int) -> str:
        """Get team name by ID."""
        team = self.team_lookup.get(team_id)
        return team["name"] if team else f"Team {team_id}"
    
    def get_division_name(self, division_id: int) -> str:
        """Get division name by ID."""
        return self.division_lookup.get(division_id, f"Division {division_id}")
    
    def get_location_name(self, location_id: int) -> str:
        """Get location name by ID."""
        location = self.location_lookup.get(location_id)
        return location["name"] if location else f"Location {location_id}"
    
    def get_timeslot_info(self, timeslot_id: int) -> Dict:
        """Get full timeslot information by ID."""
        return self.timeslot_lookup.get(timeslot_id, {})


class FeasibleGameGenerator:
    """
    Generates all feasible games based on constraints.
    
    Creates one game per team pairing with list of available TSLs.
    Scheduler will assign specific TSL during optimization.
    """
    
    def __init__(self, model: DataModel):
        """
        Initialize generator with data model.
        
        Args:
            model: DataModel instance
        """
        self.model = model
    
    def generate(self) -> List[Dict]:
        """
        Generate all feasible game combinations.
        
        Creates ONE game per team pairing, with available TSLs tracked.
        
        Returns:
            List of feasible game dictionaries
        """
        games = []
        game_id = 1
        
        # For each division
        for div_id, teams in self.model.teams_by_division.items():
            n = len(teams)
            
            # Generate all possible team pairings within division
            for i in range(n):
                for j in range(i + 1, n):
                    team_a = teams[i]
                    team_b = teams[j]
                    team_a_id = team_a["team_id"]
                    team_b_id = team_b["team_id"]
                    
                    # Find all TSLs where both teams are available
                    available_tsls = []
                    for tsl in self.model.tsls:
                        timeslot_id = tsl["timeslot_id"]
                        
                        # Both teams must be available at this timeslot
                        if (timeslot_id in self.model.team_availability[team_a_id] and
                            timeslot_id in self.model.team_availability[team_b_id]):
                            available_tsls.append(tsl)
                    
                    # Only create game if there's at least one available TSL
                    if available_tsls:
                        # Create ONE game for this pairing
                        # Use first available TSL as default (will be optimized)
                        first_tsl = available_tsls[0]
                        
                        games.append({
                            "game_id": game_id,
                            "teamA": team_a_id,
                            "teamB": team_b_id,
                            "teamA_name": team_a["name"],
                            "teamB_name": team_b["name"],
                            "division_id": div_id,
                            "division_name": self.model.get_division_name(div_id),
                            "timeslot_id": first_tsl["timeslot_id"],
                            "location_id": first_tsl["location_id"],
                            "location_name": first_tsl["location_name"],
                            "tsl_id": first_tsl["tsl_id"],
                            "date": first_tsl["date"],
                            "modifier": first_tsl["modifier"],
                            "available_tsls": available_tsls,  # Store all options
                            "weight": None  # Will be set by WeightCalculator
                        })
                        game_id += 1
        
        return games
