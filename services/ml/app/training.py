from __future__ import annotations

import math
import os
import pickle
from io import BytesIO
from pathlib import Path
from typing import Any
from uuid import UUID
from zipfile import BadZipFile

import joblib
import numpy as np
import pandas as pd
from openpyxl.utils.exceptions import InvalidFileException
from sklearn.ensemble import RandomForestClassifier, RandomForestRegressor
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LinearRegression, LogisticRegression
from sklearn.metrics import (
    accuracy_score,
    f1_score,
    mean_absolute_error,
    mean_squared_error,
    precision_score,
    r2_score,
    recall_score,
)
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler
from sklearn.tree import DecisionTreeClassifier, DecisionTreeRegressor
from xlrd.biffh import XLRDError


class TrainingError(ValueError):
    """Raised when a dataset or training request cannot produce a valid model."""


class PredictionError(ValueError):
    """Raised when prediction input does not match a saved model."""


class ModelArtifactNotFound(FileNotFoundError):
    """Raised when the requested private model artifact is unavailable."""


class ModelArtifactError(RuntimeError):
    """Raised when a saved model artifact cannot be safely loaded."""


def read_dataset(contents: bytes, filename: str) -> pd.DataFrame:
    extension = Path(filename).suffix.lower()
    stream = BytesIO(contents)

    try:
        if extension == ".csv":
            return pd.read_csv(stream)
        if extension in {".xlsx", ".xls"}:
            return pd.read_excel(stream)
    except (
        ImportError,
        InvalidFileException,
        OSError,
        TypeError,
        ValueError,
        UnicodeDecodeError,
        XLRDError,
        BadZipFile,
        pd.errors.ParserError,
    ) as error:
        raise TrainingError("The uploaded dataset could not be parsed.") from error

    raise TrainingError("Training supports CSV, XLSX, and XLS datasets.")


def train_model(
    frame: pd.DataFrame,
    job_id: UUID,
    target_column: str,
    problem_type: str,
    model_name: str,
    params: dict[str, Any],
    artifacts_dir: Path,
) -> dict[str, Any]:
    if frame.empty or frame.shape[1] < 2:
        raise TrainingError("The dataset must contain rows, a target, and at least one feature.")
    if frame.columns.duplicated().any():
        raise TrainingError("The dataset contains duplicate column names.")
    if target_column not in frame.columns:
        raise TrainingError(f"Target column '{target_column}' was not found in the dataset.")

    clean_frame = frame.dropna(subset=[target_column]).copy()
    if clean_frame.empty:
        raise TrainingError("The target column has no usable values.")

    target = clean_frame[target_column]
    features = clean_frame.drop(columns=[target_column]).dropna(axis=1, how="all")
    if features.empty:
        raise TrainingError("The dataset has no usable feature columns.")

    if problem_type == "regression":
        numeric_target = pd.to_numeric(target, errors="coerce")
        if numeric_target.isna().any() or not np.isfinite(numeric_target.to_numpy()).all():
            raise TrainingError("Regression targets must contain only finite numeric values.")
        target = numeric_target
    elif problem_type != "classification":
        raise TrainingError("Problem type must be 'classification' or 'regression'.")

    requested_estimator = params.get("estimator")
    estimator_name = str(requested_estimator or model_name).strip().lower().replace(" ", "_")
    estimator_name = {
        "linear": "linear_regression",
        "linearregression": "linear_regression",
        "decisiontree": "decision_tree",
        "randomforest": "random_forest",
        "logistic": "logistic_regression",
        "logisticregression": "logistic_regression",
    }.get(estimator_name, estimator_name)
    if requested_estimator is None and estimator_name not in {
        "linear_regression",
        "logistic_regression",
        "decision_tree",
        "random_forest",
    }:
        estimator_name = "random_forest"

    try:
        estimator = _create_estimator(estimator_name, problem_type, params)
    except TrainingError:
        raise
    except (TypeError, ValueError) as error:
        raise TrainingError(f"Invalid parameters for {estimator_name}: {error}") from error
    if problem_type == "classification":
        class_counts = target.value_counts()
        if class_counts.size < 2:
            raise TrainingError("Classification requires at least two target classes.")
        if (class_counts < 2).any():
            raise TrainingError("Each classification target class needs at least two rows.")
        test_rows = max(math.ceil(len(target) * 0.2), int(class_counts.size))
        if len(target) - test_rows < int(class_counts.size):
            raise TrainingError("The dataset is too small for a stratified classification split.")
        stratify = target
    else:
        test_rows = max(2, math.ceil(len(target) * 0.2))
        if len(target) - test_rows < 2:
            raise TrainingError("Regression requires at least four usable rows.")
        stratify = None

    try:
        X_train, X_test, y_train, y_test = train_test_split(
            features,
            target,
            test_size=test_rows,
            random_state=42,
            stratify=stratify,
        )
    except ValueError as error:
        raise TrainingError(f"Unable to split the dataset: {error}") from error

    numeric_columns = features.select_dtypes(include=["number"]).columns.tolist()
    categorical_columns = [column for column in features.columns if column not in numeric_columns]
    for column in categorical_columns:
        X_train[column] = X_train[column].map(
            lambda value: str(value) if pd.notna(value) else np.nan,
        )
        X_test[column] = X_test[column].map(
            lambda value: str(value) if pd.notna(value) else np.nan,
        )

    transformers: list[tuple[str, Pipeline, list[str]]] = []
    if numeric_columns:
        transformers.append(
            (
                "numeric",
                Pipeline(
                    [
                        ("imputer", SimpleImputer(strategy="median")),
                        ("scaler", StandardScaler()),
                    ],
                ),
                numeric_columns,
            ),
        )
    if categorical_columns:
        transformers.append(
            (
                "categorical",
                Pipeline(
                    [
                        ("imputer", SimpleImputer(strategy="most_frequent")),
                        ("encoder", OneHotEncoder(handle_unknown="ignore")),
                    ],
                ),
                categorical_columns,
            ),
        )

    pipeline = Pipeline(
        [
            ("preprocessor", ColumnTransformer(transformers)),
            ("model", estimator),
        ],
    )

    try:
        pipeline.fit(X_train, y_train)
        predictions = pipeline.predict(X_test)
    except (TypeError, ValueError) as error:
        raise TrainingError(f"Model training failed: {error}") from error

    if problem_type == "classification":
        metrics = {
            "accuracy": float(accuracy_score(y_test, predictions)),
            "precision_weighted": float(
                precision_score(y_test, predictions, average="weighted", zero_division=0),
            ),
            "recall_weighted": float(
                recall_score(y_test, predictions, average="weighted", zero_division=0),
            ),
            "f1_weighted": float(
                f1_score(y_test, predictions, average="weighted", zero_division=0),
            ),
        }
    else:
        metrics = {
            "r2": float(r2_score(y_test, predictions)),
            "mae": float(mean_absolute_error(y_test, predictions)),
            "rmse": float(np.sqrt(mean_squared_error(y_test, predictions))),
        }

    artifacts_dir.mkdir(parents=True, exist_ok=True)
    artifact_name = f"{job_id}.joblib"
    artifact_path = artifacts_dir / artifact_name
    temporary_path = artifacts_dir / f".{job_id}.joblib.tmp"
    setattr(pipeline, "modelforge_model_id", str(job_id))
    feature_schema = [
        {
            "name": column,
            "type": "number" if column in numeric_columns else "category",
        }
        for column in features.columns
    ]

    try:
        joblib.dump(pipeline, temporary_path)
        os.replace(temporary_path, artifact_path)
    except (OSError, TypeError, ValueError) as error:
        temporary_path.unlink(missing_ok=True)
        raise TrainingError("The trained model could not be saved.") from error

    return {
        "status": "completed",
        "model_id": str(job_id),
        "artifact_path": artifact_name,
        "model_name": model_name,
        "estimator": estimator_name,
        "problem_type": problem_type,
        "target_column": target_column,
        "feature_schema": feature_schema,
        "training_rows": int(len(y_train)),
        "test_rows": int(len(y_test)),
        "metrics": metrics,
    }


def _create_estimator(
    estimator_name: str,
    problem_type: str,
    params: dict[str, Any],
) -> Any:
    estimator_parameters = {key: value for key, value in params.items() if key != "estimator"}

    allowed_parameters = {
        "linear_regression": {"fit_intercept", "positive"},
        "logistic_regression": {"C", "max_iter"},
        "decision_tree": {"max_depth", "min_samples_split", "min_samples_leaf"},
        "random_forest": {"n_estimators", "max_depth", "min_samples_split", "min_samples_leaf"},
    }
    if estimator_name not in allowed_parameters:
        raise TrainingError(f"Unsupported estimator '{estimator_name}'.")

    unsupported_parameters = set(estimator_parameters) - allowed_parameters[estimator_name]
    if unsupported_parameters:
        names = ", ".join(sorted(unsupported_parameters))
        raise TrainingError(f"Unsupported parameters for {estimator_name}: {names}.")

    integer_limits = {
        "n_estimators": (1, 500),
        "max_depth": (1, 100),
        "min_samples_split": (2, 1000),
        "min_samples_leaf": (1, 500),
        "max_iter": (100, 5000),
    }
    for name, (minimum, maximum) in integer_limits.items():
        if name in estimator_parameters:
            value = estimator_parameters[name]
            if isinstance(value, bool) or not isinstance(value, int) or not minimum <= value <= maximum:
                raise TrainingError(f"{name} must be an integer between {minimum} and {maximum}.")

    if "C" in estimator_parameters:
        value = estimator_parameters["C"]
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not 0 < value <= 1000:
            raise TrainingError("C must be a number greater than 0 and no greater than 1000.")

    for name in ("fit_intercept", "positive"):
        if name in estimator_parameters and not isinstance(estimator_parameters[name], bool):
            raise TrainingError(f"{name} must be a boolean.")

    if estimator_name == "linear_regression" and problem_type == "regression":
        return LinearRegression(**estimator_parameters)
    if estimator_name == "logistic_regression" and problem_type == "classification":
        return LogisticRegression(**{"max_iter": 1000, **estimator_parameters})
    if estimator_name == "decision_tree":
        model_type = DecisionTreeClassifier if problem_type == "classification" else DecisionTreeRegressor
        return model_type(random_state=42, **estimator_parameters)
    if estimator_name == "random_forest":
        model_type = RandomForestClassifier if problem_type == "classification" else RandomForestRegressor
        return model_type(**{"n_estimators": 100, "random_state": 42, **estimator_parameters})

    raise TrainingError(f"Estimator '{estimator_name}' is not supported for {problem_type}.")


def load_saved_model(model_id: UUID, artifacts_dir: Path) -> tuple[Pipeline, list[dict[str, str]]]:
    artifacts_root = artifacts_dir.resolve()
    artifact_path = artifacts_dir / f"{model_id}.joblib"
    if artifact_path.is_symlink():
        raise ModelArtifactNotFound("The requested model artifact is unavailable.")

    try:
        resolved_path = artifact_path.resolve(strict=True)
    except FileNotFoundError as error:
        raise ModelArtifactNotFound("The requested model artifact was not found.") from error

    if resolved_path.parent != artifacts_root or not resolved_path.is_file():
        raise ModelArtifactNotFound("The requested model artifact is unavailable.")

    try:
        pipeline = joblib.load(resolved_path)
    except (
        AttributeError,
        EOFError,
        ImportError,
        IndexError,
        KeyError,
        OSError,
        pickle.UnpicklingError,
        TypeError,
        ValueError,
    ) as error:
        raise ModelArtifactError("The saved model artifact could not be loaded.") from error

    if not isinstance(pipeline, Pipeline):
        raise ModelArtifactError("The saved artifact is not a supported model pipeline.")

    stored_model_id = getattr(pipeline, "modelforge_model_id", None)
    if stored_model_id is not None and stored_model_id != str(model_id):
        raise ModelArtifactError("The saved artifact does not belong to the requested model.")

    feature_names = getattr(pipeline, "feature_names_in_", None)
    preprocessor = pipeline.named_steps.get("preprocessor")
    transformers = getattr(preprocessor, "transformers_", None)
    if feature_names is None or transformers is None:
        raise ModelArtifactError("The saved model does not contain its training feature schema.")

    feature_types: dict[str, str] = {}
    for transformer_name, _, columns in transformers:
        if transformer_name not in {"numeric", "categorical"}:
            continue
        if isinstance(columns, str):
            column_names = [columns]
        else:
            column_names = [str(column) for column in columns]
        feature_type = "number" if transformer_name == "numeric" else "category"
        feature_types.update({column: feature_type for column in column_names})

    schema = [
        {"name": str(column), "type": feature_types[str(column)]}
        for column in feature_names
        if str(column) in feature_types
    ]
    if len(schema) != len(feature_names) or not schema:
        raise ModelArtifactError("The saved model has an incomplete training feature schema.")

    return pipeline, schema


def read_prediction_csv(contents: bytes, filename: str, max_rows: int) -> pd.DataFrame:
    if Path(filename).suffix.lower() != ".csv":
        raise PredictionError("Prediction input must be a CSV file.")

    try:
        frame = pd.read_csv(BytesIO(contents))
    except (
        OSError,
        TypeError,
        ValueError,
        UnicodeDecodeError,
        pd.errors.EmptyDataError,
        pd.errors.ParserError,
    ) as error:
        raise PredictionError("The prediction CSV could not be parsed.") from error

    if frame.empty:
        raise PredictionError("The prediction CSV must contain at least one data row.")
    if frame.columns.duplicated().any():
        raise PredictionError("The prediction CSV contains duplicate column names.")
    if len(frame) > max_rows:
        raise PredictionError(f"The prediction CSV exceeds the {max_rows} row limit.")

    return frame


def predict_frame(
    frame: pd.DataFrame,
    pipeline: Pipeline,
    feature_schema: list[dict[str, str]],
) -> dict[str, Any]:
    feature_names = [feature["name"] for feature in feature_schema]
    missing_columns = [column for column in feature_names if column not in frame.columns]
    if missing_columns:
        missing = ", ".join(missing_columns)
        raise PredictionError(f"Missing required feature columns: {missing}.")

    prediction_frame = frame.loc[:, feature_names].copy()
    for feature in feature_schema:
        column = feature["name"]
        if feature["type"] == "number":
            original_values = prediction_frame[column]
            numeric_values = pd.to_numeric(original_values, errors="coerce")
            invalid_values = original_values.notna() & numeric_values.isna()
            if invalid_values.any():
                raise PredictionError(f"Feature '{column}' must contain numeric values.")
            if not np.isfinite(numeric_values.dropna().to_numpy()).all():
                raise PredictionError(f"Feature '{column}' must contain finite numeric values.")
            prediction_frame[column] = numeric_values
        else:
            prediction_frame[column] = prediction_frame[column].map(
                lambda value: str(value) if pd.notna(value) else np.nan,
            )

    estimator = pipeline.named_steps.get("model")
    if estimator is None:
        raise ModelArtifactError("The saved model pipeline is incomplete.")

    try:
        predictions = pipeline.predict(prediction_frame)
        supports_probabilities = callable(getattr(estimator, "predict_proba", None))
        probabilities = estimator.predict_proba(
            pipeline.named_steps["preprocessor"].transform(prediction_frame),
        ) if supports_probabilities else None
    except (KeyError, TypeError, ValueError) as error:
        raise PredictionError(f"Prediction failed: {error}") from error

    rows: list[dict[str, Any]] = []
    classes = getattr(estimator, "classes_", [])
    for index, prediction in enumerate(predictions):
        output: dict[str, Any] = {
            "row": index + 1,
            "prediction": _json_scalar(prediction),
            "probabilities": None,
        }
        if probabilities is not None:
            output["probabilities"] = [
                {
                    "class": _json_scalar(class_name),
                    "probability": float(probability),
                }
                for class_name, probability in zip(classes, probabilities[index], strict=True)
            ]
        rows.append(output)

    return {
        "row_count": len(rows),
        "predictions": rows,
        "metadata": {
            "feature_schema": feature_schema,
            "supports_probabilities": probabilities is not None,
            "ignored_columns": [str(column) for column in frame.columns if column not in feature_names],
        },
    }


def _json_scalar(value: Any) -> Any:
    if isinstance(value, np.generic):
        value = value.item()
    if pd.isna(value):
        return None
    if isinstance(value, (str, int, float, bool)):
        return value
    return str(value)
