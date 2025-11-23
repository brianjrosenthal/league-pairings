"""
FastAPI server for the league scheduling service.
"""

from fastapi import FastAPI, HTTPException, Query, BackgroundTasks
from fastapi.responses import JSONResponse
from datetime import date, datetime
from typing import Optional
import logging
import asyncio

from config_local import DATABASE_CONFIG, SCHEDULING_CONFIG, API_CONFIG

from generate_pairings import ScheduleGenerator
from job_manager import JobManager, JobStatus
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

# Initialize job manager
job_manager = JobManager("jobs")


def run_schedule_generation(job_id: str, start_date: str, end_date: str, algorithm: str):
    """
    Background task to run schedule generation.
    
    Args:
        job_id: Job identifier
        start_date: Start date string
        end_date: End date string
        algorithm: Algorithm name
    """
    try:
        # Update status to running
        job_manager.update_job_status(
            job_id,
            JobStatus.RUNNING,
            progress={
                "current_step": "Validating dates",
                "completed_steps": 1
            }
        )
        
        # Parse dates
        start = datetime.strptime(start_date, '%Y-%m-%d').date()
        end = datetime.strptime(end_date, '%Y-%m-%d').date()
        
        # Update progress
        job_manager.update_job_status(
            job_id,
            JobStatus.RUNNING,
            progress={
                "current_step": "Fetching data from database",
                "completed_steps": 2
            }
        )
        
        # Generate schedule
        logger.info(f"Job {job_id}: Generating schedule {start_date} to {end_date}, algorithm={algorithm}")
        generator = ScheduleGenerator(DATABASE_CONFIG, SCHEDULING_CONFIG)
        
        job_manager.update_job_status(
            job_id,
            JobStatus.RUNNING,
            progress={
                "current_step": "Running optimization algorithm",
                "completed_steps": 3
            }
        )
        
        result = generator.generate_schedule(start, end, algorithm)
        
        # Update progress
        job_manager.update_job_status(
            job_id,
            JobStatus.RUNNING,
            progress={
                "current_step": "Finalizing results",
                "completed_steps": 4
            }
        )
        
        # Mark as completed
        job_manager.update_job_status(
            job_id,
            JobStatus.COMPLETED,
            result=result,
            progress={
                "current_step": "Complete",
                "completed_steps": 5
            }
        )
        
        logger.info(f"Job {job_id}: Completed successfully")
        
    except Exception as e:
        logger.error(f"Job {job_id}: Failed with error: {e}", exc_info=True)
        job_manager.update_job_status(
            job_id,
            JobStatus.FAILED,
            error=str(e)
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
        enum=["greedy", "ilp", "ortools"]
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


@app.post("/schedule/start")
async def start_schedule_generation(
    background_tasks: BackgroundTasks,
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
        enum=["greedy", "ilp", "ortools"]
    )
):
    """
    Start an asynchronous schedule generation job.
    
    Returns immediately with a job ID that can be used to check status.
    
    Parameters:
    - **start_date**: Beginning of scheduling period (YYYY-MM-DD)
    - **end_date**: End of scheduling period (YYYY-MM-DD)
    - **algorithm**: Scheduling algorithm ('greedy', 'ilp', or 'ortools')
    
    Returns:
    - **job_id**: Unique identifier for the job
    - **status**: Initial job status
    """
    try:
        # Validate dates
        try:
            start = datetime.strptime(start_date, '%Y-%m-%d').date()
            end = datetime.strptime(end_date, '%Y-%m-%d').date()
        except ValueError as e:
            raise HTTPException(status_code=400, detail=f"Invalid date format: {e}")
        
        if end < start:
            raise HTTPException(status_code=400, detail="End date must be after start date")
        
        if (end - start).days > 365:
            raise HTTPException(status_code=400, detail="Date range cannot exceed 365 days")
        
        # Create job
        job_id = job_manager.create_job({
            "start_date": start_date,
            "end_date": end_date,
            "algorithm": algorithm
        })
        
        # Start background task
        background_tasks.add_task(
            run_schedule_generation,
            job_id,
            start_date,
            end_date,
            algorithm
        )
        
        logger.info(f"Created job {job_id} for schedule generation")
        
        return {
            "job_id": job_id,
            "status": "queued",
            "message": "Job created successfully"
        }
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error creating job: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail="Failed to create job")


@app.get("/schedule/status/{job_id}")
async def get_job_status(job_id: str):
    """
    Get the status of a schedule generation job.
    
    Parameters:
    - **job_id**: Job identifier
    
    Returns:
    - **job_id**: Job identifier
    - **status**: Current status (queued, running, completed, failed)
    - **progress**: Progress information
    - **created_at**: Job creation timestamp
    - **started_at**: Job start timestamp (if started)
    - **completed_at**: Job completion timestamp (if completed)
    """
    job_data = job_manager.get_job(job_id)
    
    if job_data is None:
        raise HTTPException(status_code=404, detail="Job not found")
    
    return {
        "job_id": job_data["job_id"],
        "status": job_data["status"],
        "progress": job_data.get("progress", {}),
        "created_at": job_data["created_at"],
        "started_at": job_data.get("started_at"),
        "completed_at": job_data.get("completed_at"),
        "error": job_data.get("error")
    }


@app.get("/schedule/result/{job_id}")
async def get_job_result(job_id: str):
    """
    Get the result of a completed schedule generation job.
    
    Parameters:
    - **job_id**: Job identifier
    
    Returns:
    - Complete schedule result if job is completed
    - Error message if job failed
    - Status information if job is still running
    """
    job_data = job_manager.get_job(job_id)
    
    if job_data is None:
        raise HTTPException(status_code=404, detail="Job not found")
    
    status = job_data["status"]
    
    if status == JobStatus.COMPLETED:
        return job_data["result"]
    elif status == JobStatus.FAILED:
        return {
            "success": False,
            "error": job_data.get("error", "Job failed"),
            "job_id": job_id
        }
    else:
        return {
            "success": False,
            "error": f"Job is still {status}",
            "status": status,
            "progress": job_data.get("progress", {}),
            "job_id": job_id
        }


@app.delete("/schedule/{job_id}")
async def delete_job(job_id: str):
    """
    Delete a job and its results.
    
    Parameters:
    - **job_id**: Job identifier
    
    Returns:
    - Success message
    """
    success = job_manager.delete_job(job_id)
    
    if not success:
        raise HTTPException(status_code=404, detail="Job not found")
    
    return {
        "success": True,
        "message": "Job deleted successfully"
    }


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
