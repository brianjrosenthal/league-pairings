#!/usr/bin/env python3

import mysql.connector
from collections import defaultdict
import json
from datetime import date


# =============================================================================
# DATABASE ACCESS
# =============================================================================

class Database:
    def __init__(self, host, user, password, database):
        self.host = host
        self.user = user
        self.password = password
        self.database = database

    def connect(self):
        return mysql.connector.connect(
            host=self.host,
            user=self.user,
            password=self.password,
            database=self.database,
            use_pure = True
        )


    def fetch_all(self, start_date, end_date):

        conn = self.connect()
        cursor = conn.cursor(dictionary=True)

        # --- Teams ---
        cursor.execute("""
            SELECT id AS team_id,
                   division_id,
                   previous_year_ranking,
                   name
            FROM teams
        """)
        teams = cursor.fetchall()

        # --- Divisions ---
        cursor.execute("""
            SELECT id, name
            FROM divisions
        """)
        divisions = cursor.fetchall()

        # --- Time Slots (timeslots table) ---
        cursor.execute("""
            SELECT id AS timeslot_id,
                   date,
                   modifier
            FROM timeslots
            WHERE date BETWEEN %s AND %s
        """, (start_date, end_date))
        time_slots = cursor.fetchall()

        # --- Locations ---
        cursor.execute("""
            SELECT id AS location_id,
                   name
            FROM locations
        """)
        locations = cursor.fetchall()

        # --- Location Availability filtered by date range ---
        cursor.execute("""
            SELECT la.location_id, la.timeslot_id
            FROM location_availability la
            JOIN timeslots ts ON ts.id = la.timeslot_id
            WHERE ts.date BETWEEN %s AND %s
        """, (start_date, end_date))
        loc_avail_raw = cursor.fetchall()


        # --- Team Availability ---
        cursor.execute("""
            SELECT team_id, timeslot_id
            FROM team_availability
        """)
        team_avail_raw = cursor.fetchall()

        # --- Previous Games ---
        cursor.execute("""
            SELECT date,
                   team_1_id,
                   team_2_id,
                   team_1_score,
                   team_2_score
            FROM previous_games
        """)
        previous_games = cursor.fetchall()

        conn.close()

        return {
            "teams": teams,
            "divisions": divisions,
            "time_slots": time_slots,
            "locations": locations,
            "loc_avail": loc_avail_raw,
            "team_avail": team_avail_raw,
            "previous_games": previous_games
        }


# =============================================================================
# DATA MODEL
# =============================================================================

class DataModel:
    def __init__(self, raw):
        self.teams = raw["teams"]
        self.divisions = raw["divisions"]
        self.division_lookup = {d["id"]: d["name"] for d in self.divisions}
        self.time_slots = raw["time_slots"]
        self.locations = raw["locations"]
        self.previous_games = raw["previous_games"]

        # TSLs = timeslot + location combos from availability table
        self.tsl_list = self._build_tsl(raw["loc_avail"])

        # team availability lookup
        self.team_avail_map = self._build_team_availability(raw["team_avail"])

        # teams grouped by division
        self.teams_by_div = self._group_teams_by_division()

    def _build_tsl(self, loc_avail_raw):
        tsl = []
        tsl_id_counter = 1
        for row in loc_avail_raw:
            tsl.append({
                "tsl_id": tsl_id_counter,
                "timeslot_id": row["timeslot_id"],
                "location_id": row["location_id"]
            })
            tsl_id_counter += 1
        return tsl

    def _build_team_availability(self, team_avail_raw):
        avail = defaultdict(set)
        for row in team_avail_raw:
            avail[row["team_id"]].add(row["timeslot_id"])
        return avail

    def _group_teams_by_division(self):
        by_div = defaultdict(list)
        for t in self.teams:
            by_div[t["division_id"]].append(t)
        return by_div

def debug_division_capacity(model, feasible_games):
    print("\n=== DIVISION CAPACITY DEBUG ===")
    
    # Map divisions → teams
    teams_by_div = model.teams_by_div
    
    # Count TSLs
    tsl_by_div = defaultdict(set)
    for g in feasible_games:
        tsl_by_div[g["division_id"]].add(g["tsl_id"])
    
    # Count team participation potential
    games_by_team = defaultdict(int)
    for g in feasible_games:
        games_by_team[g["teamA"]] += 1
        games_by_team[g["teamB"]] += 1
    
    for div_id, teams in teams_by_div.items():
        div_name = model.division_lookup.get(div_id, f"Division {div_id}")
        
        num_teams = len(teams)
        needed_slots = (num_teams + 1) // 2
        available_tsl = len(tsl_by_div[div_id])

        zero_feasible = [t["team_id"] for t in teams if games_by_team[t["team_id"]] == 0]

        print(f"\nDivision: {div_name}")
        print(f"- Teams: {num_teams}")
        print(f"- Needed TSLs: {needed_slots}")
        print(f"- Available TSLs: {available_tsl}")
        print(f"- Teams with zero feasible games: {zero_feasible}")
        
        if available_tsl < needed_slots:
            print("❌ Not enough TSLs — impossible to schedule all teams.")
        elif zero_feasible:
            print("⚠️ Some teams unavailable at any shared TSL — impossible to schedule all teams.")
        else:
            print("✔ Enough TSLs + availability — ILP should schedule everyone.")


# =============================================================================
# FEASIBLE GAME GENERATION
# =============================================================================

class FeasibleGameGenerator:
    def __init__(self, model: DataModel):
        self.model = model

    def generate(self):
        games = []
        game_id = 1

        for div_id, div_teams in self.model.teams_by_div.items():
            n = len(div_teams)

            # Pair each team with every other team in the same division
            for i in range(n):
                for j in range(i+1, n):
                    teamA = div_teams[i]
                    teamB = div_teams[j]
                    teamA_id = teamA["team_id"]
                    teamB_id = teamB["team_id"]

                    # Check each TSL
                    for tsl in self.model.tsl_list:
                        ts = tsl["timeslot_id"]

                        if ts in self.model.team_avail_map[teamA_id] and \
                           ts in self.model.team_avail_map[teamB_id]:

                            games.append({
                                "game_id": game_id,
                                "teamA": teamA_id,
                                "teamB": teamB_id,
                                "division_id": div_id,
                                "timeslot_id": tsl["timeslot_id"],
                                "location_id": tsl["location_id"],
                                "tsl_id": tsl["tsl_id"],
                                "weight": None
                            })
                            game_id += 1

        return games


# =============================================================================
# WEIGHTS (placeholder)
# =============================================================================

class WeightCalculator:
    def __init__(self, model: DataModel):
        self.model = model

    def apply_weights(self, games):
        for g in games:
            g["weight"] = 1
        return games


# =============================================================================
# TEMP SCHEDULER (greedy, replace with ILP later)
# =============================================================================

class Scheduler:
    def __init__(self, model: DataModel):
        self.model = model

    def schedule(self, games):
        used_teams = set()
        used_tsl = set()
        selected = []

        for g in games:
            if g["teamA"] in used_teams:
                continue
            if g["teamB"] in used_teams:
                continue
            if g["tsl_id"] in used_tsl:
                continue

            selected.append(g)
            used_teams.add(g["teamA"])
            used_teams.add(g["teamB"])
            used_tsl.add(g["tsl_id"])

        return selected

class SchedulePrinter:
    def __init__(self, model: DataModel):
        self.model = model

class SchedulePrinter:
    def __init__(self, model: DataModel):
        self.model = model

    def pretty_print(self, schedule):
        # Build lookup tables
        team_lookup = {t["team_id"]: t["name"] for t in self.model.teams}
        location_lookup = {l["location_id"]: l["name"] for l in self.model.locations}
        timeslot_lookup = {ts["timeslot_id"]: ts for ts in self.model.time_slots}

        # Group games by division
        divisions = defaultdict(list)
        for g in schedule:
            divisions[g["division_id"]].append(g)

        # Print each division
        for div_id, games in divisions.items():
            division_name = self.model.division_lookup.get(div_id, f"Division {div_id}")

            print(f"\n{division_name}:")
            games_sorted = sorted(games, key=lambda x: (x["timeslot_id"], x["location_id"]))

            for g in games_sorted:
                ts = timeslot_lookup[g["timeslot_id"]]

                # Convert date properly whether it's a date object or string
                date = ts["date"].strftime("%Y-%m-%d") if hasattr(ts["date"], "strftime") else str(ts["date"])

                modifier = ts["modifier"]
                location_name = location_lookup[g["location_id"]]
                teamA = team_lookup[g["teamA"]]
                teamB = team_lookup[g["teamB"]]

                print(f"- {date} {modifier}: {location_name}: {teamA} vs {teamB}")


# =============================================================================
# ORCHESTRATOR
# =============================================================================

class ScheduleBuilder:
    def __init__(self, db_config, start_date, end_date):
        self.db = Database(**db_config)
        self.start_date = start_date
        self.end_date = end_date

    def run(self):
        print("Loading data...")
        raw = self.db.fetch_all(self.start_date, self.end_date)

        model = DataModel(raw)

        print("Generating feasible games...")
        fg = FeasibleGameGenerator(model)
        games = fg.generate()
        print(f"Feasible games: {len(games)}")

        debug_division_capacity(model, games)

        print("Applying weights...")
        wc = WeightCalculator(model)
        games = wc.apply_weights(games)

        print("Scheduling...")
        scheduler = Scheduler(model)
        selected = scheduler.schedule(games)
        print(f"Selected {len(selected)} games")

        return selected, model


# =============================================================================
# MAIN
# =============================================================================

def main():
    db_config = {
        "host": "mysql.brianrosenthal.org",
        "user": "pairings",
        "database": "pairings",
        "password": "dbpw4pairings!"
    }

    # Configure date range here:
    start_date = date(2025, 12, 1)
    end_date   = date(2025, 12, 8)

    builder = ScheduleBuilder(db_config, start_date, end_date)

    schedule, model = builder.run()

    # Pretty print
    print("\n==============================================")
    print("HUMAN-READABLE SCHEDULE")
    print("==============================================")
    sp = SchedulePrinter(model)
    sp.pretty_print(schedule)

    # JSON output too
    #print("\nFINAL JSON OUTPUT:")
    #print(json.dumps(schedule, indent=2))

if __name__ == "__main__":
    main()

