"""
Job management system for asynchronous scheduling operations.
"""

import json
import os
import uuid
from datetime import datetime
from pathlib import Path
from typing import Dict, Optional, Any, List
from enum import Enum


class JobStatus(str, Enum):
    """Job status enumeration."""
    QUEUED = "queued"
    RUNNING = "running"
    COMPLETED = "completed"
    FAILED = "failed"


class JobManager:
    """
    Manages asynchronous scheduling jobs using file-based storage.
    """
    
    def __init__(self, jobs_dir: str = "jobs"):
        """
        Initialize job manager.
        
        Args:
            jobs_dir: Directory to store job files
        """
        self.jobs_dir = Path(jobs_dir)
        self.jobs_dir.mkdir(exist_ok=True)
    
    def create_job(self, params: Dict[str, Any]) -> str:
        """
        Create a new job with queued status.
        
        Args:
            params: Job parameters (start_date, end_date, algorithm)
            
        Returns:
            job_id: Unique identifier for the job
        """
        job_id = str(uuid.uuid4())
        
        job_data = {
            "job_id": job_id,
            "status": JobStatus.QUEUED,
            "params": params,
            "created_at": datetime.now().isoformat(),
            "updated_at": datetime.now().isoformat(),
            "started_at": None,
            "completed_at": None,
            "result": None,
            "error": None,
            "progress": {
                "current_step": "Initializing",
                "total_steps": 5,
                "completed_steps": 0
            }
        }
        
        self._save_job(job_id, job_data)
        return job_id
    
    def get_job(self, job_id: str) -> Optional[Dict[str, Any]]:
        """
        Get job data by ID.
        
        Args:
            job_id: Job identifier
            
        Returns:
            Job data dictionary or None if not found
        """
        job_file = self.jobs_dir / f"{job_id}.json"
        
        if not job_file.exists():
            return None
        
        try:
            with open(job_file, 'r') as f:
                return json.load(f)
        except Exception:
            return None
    
    def update_job_status(
        self,
        job_id: str,
        status: JobStatus,
        error: Optional[str] = None,
        result: Optional[Dict[str, Any]] = None,
        progress: Optional[Dict[str, Any]] = None
    ) -> bool:
        """
        Update job status and related fields.
        
        Args:
            job_id: Job identifier
            status: New status
            error: Error message if failed
            result: Result data if completed
            progress: Progress information
            
        Returns:
            True if update successful, False otherwise
        """
        job_data = self.get_job(job_id)
        
        if job_data is None:
            return False
        
        job_data["status"] = status
        job_data["updated_at"] = datetime.now().isoformat()
        
        if status == JobStatus.RUNNING and job_data["started_at"] is None:
            job_data["started_at"] = datetime.now().isoformat()
        
        if status in [JobStatus.COMPLETED, JobStatus.FAILED]:
            job_data["completed_at"] = datetime.now().isoformat()
        
        if error is not None:
            job_data["error"] = error
        
        if result is not None:
            job_data["result"] = result
        
        if progress is not None:
            job_data["progress"].update(progress)
        
        self._save_job(job_id, job_data)
        return True
    
    def delete_job(self, job_id: str) -> bool:
        """
        Delete a job file.
        
        Args:
            job_id: Job identifier
            
        Returns:
            True if deleted, False if not found
        """
        job_file = self.jobs_dir / f"{job_id}.json"
        
        if not job_file.exists():
            return False
        
        try:
            job_file.unlink()
            return True
        except Exception:
            return False
    
    def count_running_jobs(self) -> int:
        """
        Count the number of jobs currently running or queued.
        
        Returns:
            Number of jobs with status "running" or "queued"
        """
        count = 0
        
        for job_file in self.jobs_dir.glob("*.json"):
            try:
                with open(job_file, 'r') as f:
                    job_data = json.load(f)
                
                status = job_data.get("status")
                if status in [JobStatus.RUNNING, JobStatus.QUEUED]:
                    count += 1
            except Exception:
                continue
        
        return count
    
    def is_at_capacity(self, max_concurrent_jobs: int = 2) -> bool:
        """
        Check if the system is at capacity for concurrent jobs.
        
        Args:
            max_concurrent_jobs: Maximum number of concurrent jobs allowed
            
        Returns:
            True if at or over capacity, False otherwise
        """
        return self.count_running_jobs() >= max_concurrent_jobs
    
    def get_running_jobs(self) -> List[Dict[str, Any]]:
        """
        Get list of all running or queued jobs.
        
        Returns:
            List of job data dictionaries for running/queued jobs
        """
        running_jobs = []
        
        for job_file in self.jobs_dir.glob("*.json"):
            try:
                with open(job_file, 'r') as f:
                    job_data = json.load(f)
                
                status = job_data.get("status")
                if status in [JobStatus.RUNNING, JobStatus.QUEUED]:
                    running_jobs.append(job_data)
            except Exception:
                continue
        
        return running_jobs
    
    def cleanup_old_jobs(self, max_age_hours: int = 24) -> int:
        """
        Clean up completed/failed jobs older than specified hours.
        
        Args:
            max_age_hours: Maximum age in hours
            
        Returns:
            Number of jobs cleaned up
        """
        from datetime import timedelta
        
        count = 0
        cutoff = datetime.now() - timedelta(hours=max_age_hours)
        
        for job_file in self.jobs_dir.glob("*.json"):
            try:
                with open(job_file, 'r') as f:
                    job_data = json.load(f)
                
                # Only cleanup completed or failed jobs
                if job_data["status"] not in [JobStatus.COMPLETED, JobStatus.FAILED]:
                    continue
                
                completed_at = job_data.get("completed_at")
                if completed_at:
                    completed_time = datetime.fromisoformat(completed_at)
                    if completed_time < cutoff:
                        job_file.unlink()
                        count += 1
            except Exception:
                continue
        
        return count
    
    def _save_job(self, job_id: str, job_data: Dict[str, Any]) -> None:
        """
        Save job data to file.
        
        Args:
            job_id: Job identifier
            job_data: Job data dictionary
        """
        job_file = self.jobs_dir / f"{job_id}.json"
        
        with open(job_file, 'w') as f:
            json.dump(job_data, f, indent=2)
