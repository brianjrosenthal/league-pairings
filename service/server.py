"""
FastAPI server for the league scheduling service.
"""

from fastapi import FastAPI, HTTPException, Query
from fastapi.responses import JSONResponse
from datetime import date, datetime
from typing import Optional
import logging

from config_local import DATABASE_CONFIG, SCHEDULING_CONFIG, API_CONFIG

from generate_pairings import ScheduleGenerator
from utils.exceptions import (
    SchedulingError,
    DatabaseError,
    NoFeasibleGamesError,
    InsufficientDataError,
    ValidationError
)

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Initialize FastAPI app
app = FastAPI(
    title=API_CONFIG.get('title', 'League Pairings API'),
    description=API_CONFIG.get('description', 'API for generating optimal game schedules'),
    version="1.0.0"
)


@app.get("/")
async def root():
    """Root endpoint - health check."""
    return {
        "status": "online",
        "service": "League Pairings Scheduler",
        "version": "1.0.0",
        "endpoints": {
            "/schedule": "Generate game schedule",
            "/docs": "API documentation"
        }
    }


@app.get("/schedule")
async def generate_schedule(
    start_date: str = Query(
        ...,
        description="Start date in YYYY-MM-DD format",
        example="2025-12-01"
    ),
    end_date: str = Query(
        ...,
        description="End date in YYYY-MM-DD format",
        example="2025-12-08"
    ),
    algorithm: str = Query(
        SCHEDULING_CONFIG.get('default_algorithm', 'greedy'),
        description="Scheduling algorithm to use",
        enum=["greedy", "ilp"]
    )
):
    """
    Generate an optimal game schedule for the specified date range.
    
    This endpoint:
    1. Fetches all relevant data from the database
    2. Generates feasible game combinations
    3. Applies weighting based on team rankings and recent games
    4. Uses the selected algorithm to choose optimal games
    5. Returns the complete schedule with metadata
    
    Parameters:
    - **start_date**: Beginning of scheduling period (YYYY-MM-DD)
    - **end_date**: End of scheduling period (YYYY-MM-DD)
    - **algorithm**: Scheduling algorithm ('greedy' or 'ilp')
    
    Returns:
    - **schedule**: List of scheduled games
    - **metadata**: Generation statistics and parameters
    - **warnings**: Any issues or limitations encountered
    """
    try:
        # Validate and parse dates
        try:
            start = datetime.strptime(start_date, '%Y-%m-%d').date()
            end = datetime.strptime(end_date, '%Y-%m-%d').date()
        except ValueError as e:
            raise ValidationError(f"Invalid date format: {e}")
        
        # Validate date range
        if end < start:
            raise ValidationError("End date must be after start date")
        
        if (end - start).days > 365:
            raise ValidationError("Date range cannot exceed 365 days")
        
        # Generate schedule
        logger.info(f"Schedule request: {start_date} to {end_date}, algorithm={algorithm}")
        
        generator = ScheduleGenerator(DATABASE_CONFIG, SCHEDULING_CONFIG)
        result = generator.generate_schedule(start, end, algorithm)
        
        return JSONResponse(content=result)
        
    except ValidationError as e:
        logger.warning(f"Validation error: {e}")
        raise HTTPException(status_code=400, detail=str(e))
    
    except (NoFeasibleGamesError, InsufficientDataError) as e:
        logger.warning(f"Unable to generate schedule: {e}")
        return JSONResponse(
            status_code=200,
            content={
                "success": False,
                "error": str(e),
                "error_type": type(e).__name__,
                "schedule": [],
                "metadata": {
                    "start_date": start_date,
                    "end_date": end_date,
                    "algorithm": algorithm
                }
            }
        )
    
    except DatabaseError as e:
        logger.error(f"Database error: {e}")
        raise HTTPException(
            status_code=503,
            detail=f"Database connection failed: {str(e)}"
        )
    
    except SchedulingError as e:
        logger.error(f"Scheduling error: {e}")
        raise HTTPException(
            status_code=500,
            detail=f"Schedule generation failed: {str(e)}"
        )
    
    except Exception as e:
        logger.error(f"Unexpected error: {e}", exc_info=True)
        raise HTTPException(
            status_code=500,
            detail="An unexpected error occurred"
        )


@app.get("/health")
async def health_check():
    """
    Health check endpoint for monitoring.
    
    Returns:
    - Service status
    - Database connectivity
    """
    try:
        # Try to connect to database
        from models.database import Database
        db = Database(DATABASE_CONFIG)
        conn = db.connect()
        conn.close()
        
        return {
            "status": "healthy",
            "database": "connected",
            "timestamp": datetime.now().isoformat()
        }
    except Exception as e:
        return JSONResponse(
            status_code=503,
            content={
                "status": "unhealthy",
                "database": "disconnected",
                "error": str(e),
                "timestamp": datetime.now().isoformat()
            }
        )


if __name__ == "__main__":
    import uvicorn
    
    host = API_CONFIG.get('host', '0.0.0.0')
    port = API_CONFIG.get('port', 8000)
    debug = API_CONFIG.get('debug', False)
    
    logger.info(f"Starting server on {host}:{port}")
    logger.info(f"Debug mode: {debug}")
    
    uvicorn.run(
        "server:app",
        host=host,
        port=port,
        reload=debug,
        log_level="info" if debug else "warning"
    )
