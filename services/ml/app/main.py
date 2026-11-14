from __future__ import annotations

from fastapi import FastAPI

from app.config import get_settings

settings = get_settings()
app = FastAPI(title=settings.app_name, version="0.1.0")


@app.get("/health")
def health() -> dict[str, str]:
    return {
        "status": "ok",
        "service": "modelforge-ml",
        "environment": settings.environment,
    }


@app.get("/")
def root() -> dict[str, str]:
    return {
        "message": "ModelForge ML service ready",
        "capabilities": "training, evaluation, prediction",
    }
