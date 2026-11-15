from __future__ import annotations

import asyncio
import json
import shutil
import tempfile
import unittest
from io import BytesIO
from pathlib import Path
from unittest.mock import patch
from uuid import uuid4

import joblib
import numpy as np
import pandas as pd
from fastapi import HTTPException
from starlette.datastructures import UploadFile

from app.config import Settings
from app.main import model_metadata as model_metadata_endpoint
from app.main import predict as predict_endpoint
from app.main import train as train_endpoint
from app.training import (
    ModelArtifactError,
    PredictionError,
    TrainingError,
    load_saved_model,
    predict_frame,
    read_dataset,
    read_prediction_csv,
    train_model,
)


class TrainingWorkflowTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.artifacts_dir = Path(self.temporary_directory.name)

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def test_supported_regression_estimators_fit_evaluate_and_save_pipeline(self) -> None:
        frame = pd.DataFrame(
            {
                "signal": np.arange(60, dtype=float),
                "category": ["north", "south"] * 30,
                "target": np.arange(60, dtype=float) * 2 + 5,
            },
        )
        frame.loc[::7, "signal"] = np.nan
        frame.loc[::11, "category"] = None
        frame.loc[0, "target"] = np.nan

        for estimator in ("linear_regression", "decision_tree", "random_forest"):
            with self.subTest(estimator=estimator):
                job_id = uuid4()
                result = train_model(
                    frame=frame,
                    job_id=job_id,
                    target_column="target",
                    problem_type="regression",
                    model_name=estimator,
                    params={"n_estimators": 5} if estimator == "random_forest" else {},
                    artifacts_dir=self.artifacts_dir,
                )

                self.assertEqual(result["status"], "completed")
                self.assertEqual(result["estimator"], estimator)
                self.assertEqual(set(result["metrics"]), {"r2", "mae", "rmse"})
                self.assertTrue((self.artifacts_dir / result["artifact_path"]).is_file())
                self.assertIsNotNone(joblib.load(self.artifacts_dir / result["artifact_path"]))

    def test_supported_classification_estimators_fit_evaluate_and_save_pipeline(self) -> None:
        frame = pd.DataFrame(
            {
                "signal": np.arange(60, dtype=float),
                "category": ["north", "south"] * 30,
                "target": [0] * 30 + [1] * 30,
            },
        )
        frame.loc[::7, "signal"] = np.nan
        frame.loc[::11, "category"] = None
        frame.loc[0, "target"] = np.nan

        for estimator in ("logistic_regression", "decision_tree", "random_forest"):
            with self.subTest(estimator=estimator):
                job_id = uuid4()
                result = train_model(
                    frame=frame,
                    job_id=job_id,
                    target_column="target",
                    problem_type="classification",
                    model_name=estimator,
                    params={"n_estimators": 5} if estimator == "random_forest" else {},
                    artifacts_dir=self.artifacts_dir,
                )

                self.assertEqual(result["status"], "completed")
                self.assertEqual(result["estimator"], estimator)
                self.assertEqual(
                    set(result["metrics"]),
                    {"accuracy", "precision_weighted", "recall_weighted", "f1_weighted"},
                )
                self.assertTrue((self.artifacts_dir / result["artifact_path"]).is_file())

    def test_missing_target_and_unsupported_estimator_are_reported(self) -> None:
        frame = pd.DataFrame({"feature": [1, 2, 3, 4], "target": [0, 1, 0, 1]})

        with self.assertRaisesRegex(TrainingError, "was not found"):
            train_model(frame, uuid4(), "missing", "classification", "model", {}, self.artifacts_dir)

        with self.assertRaisesRegex(TrainingError, "Unsupported estimator"):
            train_model(
                frame,
                uuid4(),
                "target",
                "classification",
                "model",
                {"estimator": "unsupported"},
                self.artifacts_dir,
            )

        with self.assertRaisesRegex(TrainingError, "n_estimators must be an integer"):
            train_model(
                frame,
                uuid4(),
                "target",
                "classification",
                "random_forest",
                {"n_estimators": 501},
                self.artifacts_dir,
            )

    def test_training_rejects_classification_classes_that_cannot_be_split(self) -> None:
        frame = pd.DataFrame(
            {
                "feature": [1, 2, 3, 4],
                "target": ["a", "a", "a", "b"],
            },
        )

        with self.assertRaisesRegex(TrainingError, "at least two rows"):
            train_model(frame, uuid4(), "target", "classification", "model", {}, self.artifacts_dir)

    def test_invalid_csv_and_spreadsheet_files_are_reported(self) -> None:
        for filename in ("broken.csv", "broken.xlsx", "broken.xls"):
            with self.subTest(filename=filename):
                with self.assertRaisesRegex(TrainingError, "could not be parsed"):
                    contents = b"" if filename.endswith(".csv") else b"not a valid spreadsheet"
                    read_dataset(contents, filename)

    def test_authenticated_training_endpoint_trains_and_persists_uploaded_csv(self) -> None:
        job_id = uuid4()
        metadata = json.dumps(
            {
                "job_id": str(job_id),
                "dataset_id": "dataset-1",
                "model_name": "integration-test",
                "target_column": "target",
                "algorithm": "regression",
                "params": {"estimator": "linear_regression"},
                "organization_id": "organization-1",
                "project_id": "project-1",
                "dataset_name": "private",
            },
        )
        contents = "signal,target\n" + "".join(f"{row},{row * 2}\n" for row in range(20))
        uploaded_file = UploadFile(filename="private.csv", file=BytesIO(contents.encode()))
        configured_settings = Settings(
            service_token="integration-test-token",
            artifacts_dir=self.artifacts_dir,
        )

        with patch("app.main.get_settings", return_value=configured_settings):
            result = asyncio.run(
                train_endpoint(
                    metadata=metadata,
                    dataset_file=uploaded_file,
                    authorization="Bearer integration-test-token",
                ),
            )

        self.assertEqual(result["status"], "completed")
        self.assertEqual(result["job_id"], str(job_id))
        self.assertEqual(result["metrics"]["r2"], 1.0)
        self.assertTrue((self.artifacts_dir / result["artifact_path"]).is_file())

    def test_training_endpoint_rejects_missing_or_invalid_service_credentials(self) -> None:
        configured_settings = Settings(service_token="expected-token")
        uploaded_file = UploadFile(filename="private.csv", file=BytesIO(b""))

        with patch("app.main.get_settings", return_value=configured_settings):
            with self.assertRaises(HTTPException) as exception:
                asyncio.run(
                    train_endpoint(
                        metadata="{}",
                        dataset_file=uploaded_file,
                        authorization="Bearer wrong-token",
                    ),
                )

        self.assertEqual(exception.exception.status_code, 401)


class PredictionWorkflowTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.artifacts_dir = Path(self.temporary_directory.name)
        self.regression_id = uuid4()
        self.regression_frame = pd.DataFrame(
            {
                "signal": np.arange(60, dtype=float),
                "temperature": np.arange(60, dtype=float) / 2,
                "target": np.arange(60, dtype=float) * 2 + 5,
            },
        )
        train_model(
            self.regression_frame,
            self.regression_id,
            "target",
            "regression",
            "linear_regression",
            {"estimator": "linear_regression"},
            self.artifacts_dir,
        )

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def test_regression_prediction_reorders_features_and_imputes_missing_values(self) -> None:
        configured_settings = Settings(
            service_token="integration-test-token",
            artifacts_dir=self.artifacts_dir,
        )
        uploaded_file = UploadFile(
            filename="batch.csv",
            file=BytesIO(b"temperature,signal,extra\n5,10,ignored\n,20,ignored\n"),
        )

        with patch("app.main.get_settings", return_value=configured_settings):
            result = asyncio.run(
                predict_endpoint(
                    model_id=self.regression_id,
                    input_file=uploaded_file,
                    authorization="Bearer integration-test-token",
                ),
            )

        self.assertEqual(result["row_count"], 2)
        self.assertEqual(result["metadata"]["ignored_columns"], ["extra"])
        self.assertEqual([row["row"] for row in result["predictions"]], [1, 2])
        self.assertTrue(all(isinstance(row["prediction"], float) for row in result["predictions"]))
        self.assertTrue(all(row["probabilities"] is None for row in result["predictions"]))
        pipeline, schema = load_saved_model(self.regression_id, self.artifacts_dir)
        ordered_result = predict_frame(
            pd.DataFrame({"signal": [10, 20], "temperature": [5, np.nan]}),
            pipeline,
            schema,
        )
        self.assertEqual(
            [row["prediction"] for row in result["predictions"]],
            [row["prediction"] for row in ordered_result["predictions"]],
        )

    def test_classification_prediction_returns_probabilities_when_supported(self) -> None:
        model_id = uuid4()
        frame = pd.DataFrame(
            {
                "signal": np.arange(60, dtype=float),
                "category": ["north", "south"] * 30,
                "target": ["low"] * 30 + ["high"] * 30,
            },
        )
        train_model(
            frame,
            model_id,
            "target",
            "classification",
            "logistic_regression",
            {"estimator": "logistic_regression"},
            self.artifacts_dir,
        )
        pipeline, schema = load_saved_model(model_id, self.artifacts_dir)
        result = predict_frame(pd.DataFrame({"category": ["north"], "signal": [10]}), pipeline, schema)

        self.assertEqual(result["row_count"], 1)
        self.assertTrue(result["metadata"]["supports_probabilities"])
        self.assertAlmostEqual(sum(item["probability"] for item in result["predictions"][0]["probabilities"]), 1.0)

    def test_missing_features_and_invalid_numeric_values_are_rejected(self) -> None:
        pipeline, schema = load_saved_model(self.regression_id, self.artifacts_dir)

        with self.assertRaisesRegex(PredictionError, "Missing required feature columns"):
            predict_frame(pd.DataFrame({"signal": [1]}), pipeline, schema)
        with self.assertRaisesRegex(PredictionError, "must contain numeric values"):
            predict_frame(
                pd.DataFrame({"signal": ["not-a-number"], "temperature": [2]}),
                pipeline,
                schema,
            )

    def test_model_metadata_reports_training_feature_schema(self) -> None:
        configured_settings = Settings(
            service_token="integration-test-token",
            artifacts_dir=self.artifacts_dir,
        )
        with patch("app.main.get_settings", return_value=configured_settings):
            result = model_metadata_endpoint(
                model_id=self.regression_id,
                authorization="Bearer integration-test-token",
            )

        self.assertEqual(
            [feature["name"] for feature in result["feature_schema"]],
            ["signal", "temperature"],
        )
        self.assertEqual(result["problem_type"], "regression")

    def test_artifact_with_another_model_id_is_rejected(self) -> None:
        other_model_id = uuid4()
        shutil.copyfile(
            self.artifacts_dir / f"{self.regression_id}.joblib",
            self.artifacts_dir / f"{other_model_id}.joblib",
        )

        with self.assertRaisesRegex(ModelArtifactError, "does not belong"):
            load_saved_model(other_model_id, self.artifacts_dir)

    def test_missing_artifact_authentication_and_csv_batch_limit_are_rejected(self) -> None:
        configured_settings = Settings(
            service_token="integration-test-token",
            artifacts_dir=self.artifacts_dir,
            max_rows_per_job=1,
        )
        with patch("app.main.get_settings", return_value=configured_settings):
            with self.assertRaises(HTTPException) as missing_artifact:
                model_metadata_endpoint(
                    model_id=uuid4(),
                    authorization="Bearer integration-test-token",
                )
            self.assertEqual(missing_artifact.exception.status_code, 404)

            with self.assertRaises(HTTPException) as unauthorized:
                asyncio.run(
                    predict_endpoint(
                        model_id=self.regression_id,
                        input_file=UploadFile(filename="batch.csv", file=BytesIO(b"signal,temperature\n1,2\n")),
                        authorization="Bearer wrong-token",
                    ),
                )
            self.assertEqual(unauthorized.exception.status_code, 401)

        with self.assertRaisesRegex(PredictionError, "row limit"):
            read_prediction_csv(b"signal,temperature\n1,2\n3,4\n", "batch.csv", 1)


if __name__ == "__main__":
    unittest.main()
