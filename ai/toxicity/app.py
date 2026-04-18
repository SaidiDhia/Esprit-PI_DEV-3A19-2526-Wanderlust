import os
from fastapi import FastAPI
from pydantic import BaseModel
from transformers import pipeline


app = FastAPI(title="Toxicity API")


class ToxicityRequest(BaseModel):
    text: str


model_id = os.getenv("MODEL_ID", "distilbert-base-uncased-finetuned-sst-2-english")
classifier = pipeline("text-classification", model=model_id)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "model": model_id}


@app.post("/toxicity/score")
def toxicity_score(payload: ToxicityRequest) -> dict[str, float | str]:
    text = payload.text.strip()
    if text == "":
        return {"toxicity": 0.0, "label": "NEUTRAL", "model": model_id}

    result = classifier(text[:512])[0]
    label = str(result.get("label", "")).upper()
    score = float(result.get("score", 0.0))

    # First-class toxicity labels from toxic models.
    if label in {"NON-TOXIC", "NOT_TOXIC", "NONTOXIC", "SAFE", "LABEL_0"} or "NON" in label:
        toxicity = 1.0 - score
    elif label in {"TOXIC", "LABEL_1"} or ("TOXIC" in label and "NON" not in label):
        toxicity = score
    # Backward-compatible mapping for sentiment-style models.
    elif "NEG" in label:
        toxicity = score
    else:
        toxicity = (1.0 - score) * 0.2
    toxicity = max(0.0, min(1.0, toxicity))

    return {
        "toxicity": round(toxicity, 4),
        "label": label,
        "model": model_id,
    }
