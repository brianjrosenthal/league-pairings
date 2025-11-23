# League Pairings Scheduling Service

A Python-based scheduling service that generates optimal game pairings for league play using constraint satisfaction and weighted optimization.

## Features

- **Intelligent Scheduling**: Generates game schedules based on team availability, location capacity, and previous game history
- **Multiple Algorithms**: Support for greedy scheduling (implemented) and ILP optimization (future)
- **Constraint Management**: Avoids scheduling teams that played recently, respects availability windows
- **Weight-Based Optimization**: Prioritizes matchups between similarly-ranked teams
- **REST API**: FastAPI-based service with automatic documentation
- **Robust Error Handling**: Comprehensive validation and error reporting

## Architecture

```
service/
├── config.local.py.example    # Configuration template
├── config.local.py            # Actual config (gitignored)
├── server.py                  # FastAPI server
├── generate_pairings.py       # Main orchestrator
├── models/
│   ├── database.py           # Database access layer
│   ├── data_model.py         # Data structures
│   └── scheduler.py          # Scheduling algorithms
└── utils/
    ├── constraints.py        # Constraint checking
    ├── weights.py            # Weight calculation
    └── exceptions.py         # Custom exceptions
```

## Setup

### 1. Install Dependencies

```bash
cd service
pip install -r requirements.txt
```

### 2. Configure Database

Copy the example config and update with your credentials:

```bash
cp config.local.py.example config.local.py
# Edit config.local.py with your database credentials
```

### 3. Run the Server

```bash
python server.py
```

The server will start on `http://localhost:8000`

## API Usage

### Generate Schedule

```bash
GET /schedule?start_date=2025-12-01&end_date=2025-12-08&algorithm=greedy
```

**Parameters:**
- `start_date` (required): Start date in YYYY-MM-DD format
- `end_date` (required): End date in YYYY-MM-DD format  
- `algorithm` (optional): `greedy` or `ilp` (default: greedy)

**Response:**
```json
{
  "success": true,
  "schedule": [
    {
      "game_id": 1,
      "date": "2025-12-01",
      "time_modifier": "7:00 PM",
      "location": "Court A",
      "division": "Division 1",
      "team_a": "Team Alpha",
      "team_b": "Team Beta",
      "weight": 0.85
    }
  ],
  "metadata": {
    "total_games": 12,
    "algorithm": "greedy",
    "generated_at": "2025-11-23T14:28:00Z"
  },
  "warnings": []
}
```

### Health Check

```bash
GET /health
```

Returns service status and database connectivity.

### Interactive Documentation

Visit `http://localhost:8000/docs` for interactive API documentation powered by Swagger UI.

## Configuration

### Database Settings

```python
DATABASE_CONFIG = {
    "host": "localhost",
    "user": "your_user",
    "password": "your_password",
    "database": "your_database",
    "charset": "utf8mb4",
    "use_pure": True
}
```

### Scheduling Parameters

```python
SCHEDULING_CONFIG = {
    "recent_games_weeks": 3,        # Look back period for recent games
    "recent_game_penalty": 0.1,     # Weight penalty for recent opponents
    "ideal_ranking_diff": 5,        # Ideal ranking difference for matchups
    "default_algorithm": "greedy"   # Default scheduling algorithm
}
```

## Scheduling Algorithms

### Greedy Scheduler

The greedy scheduler:
1. Calculates weights for all feasible games
2. Sorts games by weight (descending)
3. Selects games greedily, avoiding team/location conflicts
4. Fast and produces good results for most scenarios
5. **Time Complexity**: O(n log n) where n = number of feasible games

**Best for**: Quick scheduling, smaller leagues, when near-optimal is sufficient

### ILP Scheduler

The ILP (Integer Linear Programming) scheduler:
- Uses mathematical optimization (via PuLP) to find globally optimal solutions
- Formulates scheduling as an optimization problem with binary decision variables
- Maximizes total weight across all selected games
- Guarantees the mathematically optimal solution
- **Time Complexity**: Variable (typically seconds to minutes)

**Mathematical Formulation**:
```
Maximize: Σ(weight[g] × x[g]) for all games g
Subject to:
  - Σ(x[g]) ≤ 1 for all games g where team t participates (each team plays once)
  - Σ(x[g]) ≤ 1 for all games g using TSL (each location-time slot used once)
  - x[g] ∈ {0, 1} (binary decision variables)
```

**Best for**: Critical schedules, larger leagues, when optimality is required

**Performance**:
- Small leagues (10-20 teams): < 1 second
- Medium leagues (50-100 teams): 1-10 seconds
- Large leagues (200+ teams): 10-60 seconds

**Configuration**:
The ILP solver has a 60-second timeout by default. If a solution isn't found within this time, an error is returned.

## Weight Calculation

Game weights are calculated based on:

1. **Ranking Similarity** (0.1 - 1.0)
   - Teams with similar rankings = higher weight
   - Large ranking differences = lower weight

2. **Recency Penalty** (0.1 - 1.0)
   - Recently played opponents = lower weight
   - Never played or long ago = higher weight

3. **Combined Weight**
   - Final weight = ranking_weight × recency_weight

## Constraints

The system enforces:

- **Team availability**: Both teams must be available at the timeslot
- **Location availability**: Location must be available at the timeslot
- **No conflicts**: Each team and location can only be used once per schedule
- **Division separation**: Teams only play within their division
- **Recency checking**: Optional de-prioritization of recent opponents

## Development

### Running Tests

```bash
# TODO: Add tests
pytest tests/
```

### Adding a New Scheduler

1. Create a new class in `models/scheduler.py` inheriting from `BaseScheduler`
2. Implement the `schedule()` method
3. Add to the `get_scheduler()` factory function
4. Update API enum to include new algorithm name

### Database Schema

The service expects the following tables:
- `teams`: Team information and rankings
- `divisions`: Division groupings
- `locations`: Game locations
- `timeslots`: Available time slots
- `location_availability`: Location-timeslot mappings
- `team_availability`: Team-timeslot mappings
- `previous_games`: Historical game data

See `schema.sql` in the parent directory for complete schema.

## Troubleshooting

### Database Connection Errors

- Verify `config.local.py` has correct credentials
- Check database server is running and accessible
- Ensure user has necessary permissions

### No Feasible Games

- Check that teams have availability in the date range
- Verify locations have availability
- Ensure teams exist in the specified divisions

### Import Errors

- Ensure all dependencies are installed: `pip install -r requirements.txt`
- Check Python version (3.8+ required)
- Verify you're in the `service/` directory when running

## License

Part of the league-pairings application.
