from __future__ import annotations

from dataclasses import dataclass
from functools import lru_cache

from dotenv import load_dotenv

load_dotenv()


@dataclass(frozen=True)
class Settings:
    app_name: str = "ModelForge ML Service"
    environment: str = "development"
    log_level: str = "INFO"
    max_rows_per_job: int = 250000
    training_timeout_minutes: int = 60


@lru_cache
def get_settings() -> Settings:
    return Settings()
