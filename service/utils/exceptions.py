"""
Custom exceptions for the scheduling service.
"""


class SchedulingError(Exception):
    """Base exception for all scheduling errors."""
    pass


class DatabaseError(SchedulingError):
    """Error connecting to or querying the database."""
    pass


class NoFeasibleGamesError(SchedulingError):
    """No feasible games can be generated with current constraints."""
    pass


class InsufficientDataError(SchedulingError):
    """Not enough data available to generate a schedule."""
    pass


class ConfigurationError(SchedulingError):
    """Error in configuration or setup."""
    pass


class ValidationError(SchedulingError):
    """Input validation error."""
    pass
