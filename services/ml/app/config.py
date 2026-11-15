from __future__ import annotations

import os
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parents[1] / ".env")


@dataclass(frozen=True)
class Settings:
    app_name: str = "ModelForge ML Service"
    environment: str = "development"
    log_level: str = "INFO"
    max_rows_per_job: int = 250000
    max_upload_bytes: int = 20 * 1024 * 1024
    service_token: str | None = None
    artifacts_dir: Path = Path(__file__).resolve().parents[1] / "artifacts"


@lru_cache
def get_settings() -> Settings:
    return Settings(
        environment=os.getenv("ML_ENVIRONMENT", "development"),
        log_level=os.getenv("ML_LOG_LEVEL", "INFO"),
        max_rows_per_job=int(os.getenv("ML_MAX_ROWS_PER_JOB", "250000")),
        max_upload_bytes=int(os.getenv("ML_MAX_UPLOAD_BYTES", str(20 * 1024 * 1024))),
        service_token=os.getenv("ML_SERVICE_TOKEN"),
        artifacts_dir=Path(
            os.getenv(
                "ML_ARTIFACTS_DIR",
                str(Path(__file__).resolve().parents[1] / "artifacts"),
            ),
        ),
    )
