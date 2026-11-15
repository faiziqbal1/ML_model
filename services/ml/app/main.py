from __future__ import annotations

import hmac
import logging
from typing import Annotated, Literal
from uuid import UUID

from fastapi import FastAPI, File, Form, Header, HTTPException, UploadFile
from pydantic import BaseModel, ConfigDict, Field, ValidationError
from sklearn.base import is_classifier, is_regressor

from app.config import get_settings
from app.training import (
    ModelArtifactError,
    ModelArtifactNotFound,
    PredictionError,
    TrainingError,
    load_saved_model,
    predict_frame,
    read_dataset,
    read_prediction_csv,
    train_model,
)

settings = get_settings()
app = FastAPI(title=settings.app_name, version="0.1.0")
logger = logging.getLogger(__name__)


def _authorize_service(authorization: str | None) -> None:
    current_settings = get_settings()
    if not current_settings.service_token:
        raise HTTPException(status_code=503, detail="ML service authentication is not configured.")

    expected_authorization = f"Bearer {current_settings.service_token}"
    if authorization is None or not hmac.compare_digest(authorization, expected_authorization):
        raise HTTPException(status_code=401, detail="Invalid ML service credentials.")


class TrainingMetadata(BaseModel):
    model_config = ConfigDict(extra="forbid")

    job_id: UUID
    dataset_id: str
    model_name: str
    target_column: str
    algorithm: Literal["classification", "regression"]
    params: dict[str, object] = Field(default_factory=dict)
    organization_id: str | None = None
    project_id: str | None = None
    dataset_name: str | None = None


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


@app.post("/train")
async def train(
    metadata: Annotated[str, Form()],
    dataset_file: Annotated[UploadFile, File()],
    authorization: Annotated[str | None, Header()] = None,
) -> dict[str, object]:
    _authorize_service(authorization)
    current_settings = get_settings()

    try:
        training_metadata = TrainingMetadata.model_validate_json(metadata)
    except ValidationError as error:
        raise HTTPException(status_code=422, detail=error.errors(include_url=False)) from error

    try:
        contents = await dataset_file.read(current_settings.max_upload_bytes + 1)
    finally:
        await dataset_file.close()

    if not contents:
        raise HTTPException(status_code=422, detail="The uploaded dataset is empty.")
    if len(contents) > current_settings.max_upload_bytes:
        raise HTTPException(status_code=413, detail="The uploaded dataset exceeds the size limit.")

    try:
        frame = read_dataset(contents, dataset_file.filename or "")
        if len(frame) > current_settings.max_rows_per_job:
            raise HTTPException(status_code=413, detail="The dataset exceeds the row limit.")

        result = train_model(
            frame=frame,
            job_id=training_metadata.job_id,
            target_column=training_metadata.target_column,
            problem_type=training_metadata.algorithm,
            model_name=training_metadata.model_name,
            params=training_metadata.params,
            artifacts_dir=current_settings.artifacts_dir,
        )
    except TrainingError as error:
        raise HTTPException(status_code=422, detail=str(error)) from error
    except HTTPException:
        raise
    except Exception as error:
        logger.exception("Training failed for job %s.", training_metadata.job_id)
        raise HTTPException(
            status_code=500,
            detail="Model training failed due to an internal error.",
        ) from error

    return {
        **result,
        "job_id": str(training_metadata.job_id),
        "dataset_id": training_metadata.dataset_id,
        "source": "fastapi",
    }


@app.get("/models/{model_id}")
def model_metadata(
    model_id: UUID,
    authorization: Annotated[str | None, Header()] = None,
) -> dict[str, object]:
    _authorize_service(authorization)
    current_settings = get_settings()

    try:
        pipeline, feature_schema = load_saved_model(model_id, current_settings.artifacts_dir)
    except ModelArtifactNotFound as error:
        raise HTTPException(status_code=404, detail=str(error)) from error
    except ModelArtifactError as error:
        logger.exception("Model artifact validation failed for %s.", model_id)
        raise HTTPException(status_code=500, detail=str(error)) from error

    estimator = pipeline.named_steps.get("model")
    if estimator is None:
        raise HTTPException(status_code=500, detail="The saved model pipeline is incomplete.")

    problem_type = (
        "classification" if is_classifier(estimator)
        else "regression" if is_regressor(estimator)
        else "unknown"
    )
    return {
        "model_id": str(model_id),
        "estimator": type(estimator).__name__,
        "problem_type": problem_type,
        "feature_schema": feature_schema,
        "supports_probabilities": callable(getattr(estimator, "predict_proba", None)),
    }


@app.post("/predict")
async def predict(
    model_id: Annotated[UUID, Form()],
    input_file: Annotated[UploadFile, File()],
    authorization: Annotated[str | None, Header()] = None,
) -> dict[str, object]:
    _authorize_service(authorization)
    current_settings = get_settings()

    try:
        contents = await input_file.read(current_settings.max_upload_bytes + 1)
    finally:
        await input_file.close()

    if not contents:
        raise HTTPException(status_code=422, detail="The prediction CSV is empty.")
    if len(contents) > current_settings.max_upload_bytes:
        raise HTTPException(status_code=413, detail="The prediction CSV exceeds the size limit.")

    try:
        frame = read_prediction_csv(
            contents,
            input_file.filename or "",
            current_settings.max_rows_per_job,
        )
        pipeline, feature_schema = load_saved_model(model_id, current_settings.artifacts_dir)
        result = predict_frame(frame, pipeline, feature_schema)
    except PredictionError as error:
        raise HTTPException(status_code=422, detail=str(error)) from error
    except ModelArtifactNotFound as error:
        raise HTTPException(status_code=404, detail=str(error)) from error
    except ModelArtifactError as error:
        logger.exception("Model artifact validation failed for %s.", model_id)
        raise HTTPException(status_code=500, detail=str(error)) from error
    except (ArithmeticError, AttributeError, IndexError, KeyError, RuntimeError, TypeError, ValueError) as error:
        logger.exception("Prediction failed for model %s.", model_id)
        raise HTTPException(
            status_code=500,
            detail="Prediction failed due to an internal error.",
        ) from error

    return {
        **result,
        "model_id": str(model_id),
        "source": "fastapi",
    }
