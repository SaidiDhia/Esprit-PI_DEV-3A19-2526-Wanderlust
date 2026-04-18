import os
import threading
from fastapi import FastAPI
from pydantic import BaseModel
import torch
from transformers import pipeline


app = FastAPI(title="Toxicity API")


class ToxicityRequest(BaseModel):
    text: str


model_id = os.getenv("MODEL_ID", "unitary/toxic-bert")
execution_device = 0 if torch.cuda.is_available() else -1
execution_device_name = "cuda" if execution_device == 0 else "cpu"
classifier = None
model_state = {
    "status": "not_loaded",
    "message": "Model not loaded",
}
model_lock = threading.Lock()


def get_toxicity_classifier():
    global classifier
    if classifier is None:
        classifier = pipeline("text-classification", model=model_id, device=execution_device)
    return classifier


def ensure_classifier_background() -> None:
    if classifier is not None:
        model_state["status"] = "ready"
        model_state["message"] = "Model loaded"
        return

    if not model_lock.acquire(blocking=False):
        return

    def _load() -> None:
        try:
            model_state["status"] = "loading"
            model_state["message"] = f"Loading {model_id}"
            get_toxicity_classifier()
            model_state["status"] = "ready"
            model_state["message"] = "Model loaded"
        except Exception as exc:
            model_state["status"] = "failed"
            model_state["message"] = f"Load failed: {exc}"
        finally:
            model_lock.release()

    threading.Thread(target=_load, daemon=True).start()


def fallback_toxicity_score(text: str) -> float:
    lower = text.lower()
    toxic_terms = [
        "idiot",
        "stupid",
        "trash",
        "hate",
        "loser",
        "dumb",
        "moron",
        "fuck",
        "shit",
        "bitch",
        "bastard",
        "die",
        "kill",
    ]

    hits = 0
    for term in toxic_terms:
        if term in lower:
            hits += 1

    if hits == 0:
        return 0.0

    return min(0.95, 0.25 + (hits * 0.15))


@app.get("/health")
def health() -> dict[str, str]:
    ensure_classifier_background()
    return {
        "status": "ok",
        "model": model_id,
        "device": execution_device_name,
        "cuda_available": "yes" if torch.cuda.is_available() else "no",
        "model_loaded": "yes" if classifier is not None else "no",
        "model_status": model_state["status"],
    }


@app.post("/toxicity/score")
def toxicity_score(payload: ToxicityRequest) -> dict[str, float | str]:
    text = payload.text.strip()
    if text == "":
        return {"toxicity": 0.0, "label": "NEUTRAL", "model": model_id}

    if classifier is None:
        ensure_classifier_background()
        fallback_score = fallback_toxicity_score(text)
        return {
            "toxicity": round(max(0.0, min(1.0, fallback_score)), 4),
            "label": "FALLBACK",
            "model": model_id,
            "model_status": model_state["status"],
            "note": "Returned fallback score while toxicity model is loading",
        }

    result = get_toxicity_classifier()(text[:512])[0]
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
        "model_status": model_state["status"],
    }
