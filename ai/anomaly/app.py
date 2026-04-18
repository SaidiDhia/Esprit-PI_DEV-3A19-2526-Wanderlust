from fastapi import FastAPI
from pydantic import BaseModel, Field
from pyod.models.iforest import IForest
import numpy as np


app = FastAPI(title="Anomaly Detection API")


class AnomalyRequest(BaseModel):
    features: dict[str, float] = Field(default_factory=dict)


model = IForest(contamination=0.06, random_state=42)

# Build a synthetic baseline of regular behavior for initial fitting.
rng = np.random.default_rng(seed=42)
baseline = np.column_stack(
    [
        rng.normal(loc=8.0, scale=4.0, size=1200),   # clicks_per_minute
        rng.normal(loc=38.0, scale=15.0, size=1200), # time_between_actions_seconds
        rng.normal(loc=1.2, scale=0.9, size=1200),   # booking_frequency
        rng.normal(loc=0.12, scale=0.1, size=1200),  # cancel_booking_ratio
        rng.normal(loc=16.0, scale=12.0, size=1200), # session_duration_minutes
    ]
)
baseline[:, 0] = np.clip(baseline[:, 0], 0, None)
baseline[:, 1] = np.clip(baseline[:, 1], 1, None)
baseline[:, 2] = np.clip(baseline[:, 2], 0, None)
baseline[:, 3] = np.clip(baseline[:, 3], 0, 1)
baseline[:, 4] = np.clip(baseline[:, 4], 1, None)
model.fit(baseline)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/anomaly/score")
def score_anomaly(payload: AnomalyRequest) -> dict[str, float]:
    feature_vector = np.array(
        [
            float(payload.features.get("clicks_per_minute", 0.0)),
            float(payload.features.get("time_between_actions_seconds", 300.0)),
            float(payload.features.get("booking_frequency", 0.0)),
            float(payload.features.get("cancel_booking_ratio", 0.0)),
            float(payload.features.get("session_duration_minutes", 0.0)),
        ],
        dtype=float,
    ).reshape(1, -1)

    raw_score = float(model.decision_function(feature_vector)[0])

    # IForest decision function: larger is normal, smaller is anomalous.
    risk_score = 50.0 - (raw_score * 120.0)
    risk_score = max(0.0, min(100.0, risk_score))

    return {
        "risk_score": round(risk_score, 2),
        "raw_score": round(raw_score, 4),
    }
