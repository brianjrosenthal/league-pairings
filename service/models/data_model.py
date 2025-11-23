"""
Data model for organizing and processing scheduling data.
"""

from typing import Dict, List, Set
from collections import defaultdict
from datetime import datetime


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
                    
                    # Check each TSL to see if both teams are available
                    for tsl in self.model.tsls:
                        timeslot_id = tsl["timeslot_id"]
                        
                        # Both teams must be available at this timeslot
                        if (timeslot_id in self.model.team_availability[team_a_id] and
                            timeslot_id in self.model.team_availability[team_b_id]):
                            
                            games.append({
                                "game_id": game_id,
                                "teamA": team_a_id,
                                "teamB": team_b_id,
                                "teamA_name": team_a["name"],
                                "teamB_name": team_b["name"],
                                "division_id": div_id,
                                "division_name": self.model.get_division_name(div_id),
                                "timeslot_id": timeslot_id,
                                "location_id": tsl["location_id"],
                                "location_name": tsl["location_name"],
                                "tsl_id": tsl["tsl_id"],
                                "date": tsl["date"],
                                "modifier": tsl["modifier"],
                                "weight": None  # Will be set by WeightCalculator
                            })
                            game_id += 1
        
        return games
