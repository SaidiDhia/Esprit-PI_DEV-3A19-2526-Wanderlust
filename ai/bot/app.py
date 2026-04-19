import os
import threading
from fastapi import FastAPI
from pydantic import BaseModel
import torch
from transformers import pipeline


app = FastAPI(title="Bot Behavior API")


class BehaviorClassificationRequest(BaseModel):
    behavior: str


bot_model_id = os.getenv("BOT_MODEL_ID", "facebook/bart-large-mnli")
execution_device = 0 if torch.cuda.is_available() else -1
execution_device_name = "cuda" if execution_device == 0 else "cpu"
bot_classifier = None
bot_model_state = {
    "status": "not_loaded",
    "message": "Model not loaded",
}
bot_model_lock = threading.Lock()


def get_bot_classifier():
    global bot_classifier
    if bot_classifier is None:
        bot_classifier = pipeline("zero-shot-classification", model=bot_model_id, device=execution_device)
    return bot_classifier


def ensure_bot_classifier_background() -> None:
    if bot_classifier is not None:
        bot_model_state["status"] = "ready"
        bot_model_state["message"] = "Model loaded"
        return

    if not bot_model_lock.acquire(blocking=False):
        return

    def _load() -> None:
        try:
            bot_model_state["status"] = "loading"
            bot_model_state["message"] = "Loading facebook/bart-large-mnli"
            get_bot_classifier()
            bot_model_state["status"] = "ready"
            bot_model_state["message"] = "Model loaded"
        except Exception as exc:
            bot_model_state["status"] = "failed"
            bot_model_state["message"] = f"Load failed: {exc}"
        finally:
            bot_model_lock.release()

    threading.Thread(target=_load, daemon=True).start()


def classify_behavior_fallback(text: str) -> tuple[str, float, dict[str, float]]:
    lower = text.lower()
    bot_terms = [
        "automation",
        "script",
        "bot",
        "identical requests",
        "hundreds",
        "per second",
        "per minute",
        "no mouse",
        "repetitive",
        "same headers",
    ]

    hits = 0
    for term in bot_terms:
        if term in lower:
            hits += 1

    if hits >= 2:
        confidence = min(0.95, 0.55 + (hits * 0.1))
        return "bot", confidence, {"bot behavior": round(confidence, 4), "human behavior": round(1.0 - confidence, 4)}

    confidence = 0.65
    return "human", confidence, {"human behavior": round(confidence, 4), "bot behavior": round(1.0 - confidence, 4)}


@app.get("/health")
def health() -> dict[str, str]:
    ensure_bot_classifier_background()
    return {
        "status": "ok",
        "bot_model": bot_model_id,
        "device": execution_device_name,
        "cuda_available": "yes" if torch.cuda.is_available() else "no",
        "bot_model_loaded": "yes" if bot_classifier is not None else "no",
        "bot_model_status": bot_model_state["status"],
    }


@app.post("/behavior/classify-human-bot")
def classify_human_or_bot(payload: BehaviorClassificationRequest) -> dict[str, float | str | dict[str, float]]:
    text = payload.behavior.strip()
    if text == "":
        return {
            "classification": "unknown",
            "confidence": 0.0,
            "scores": {},
            "model": bot_model_id,
        }

    if bot_classifier is None:
        ensure_bot_classifier_background()
        fallback_classification, fallback_confidence, fallback_scores = classify_behavior_fallback(text)
        return {
            "classification": fallback_classification,
            "confidence": round(fallback_confidence, 4),
            "scores": fallback_scores,
            "model": bot_model_id,
            "model_status": bot_model_state["status"],
            "note": "Returned fallback classification while BART model is loading",
        }

    labels = ["human behavior", "bot behavior"]
    result = get_bot_classifier()(text[:1024], candidate_labels=labels, multi_label=False)

    ranked_labels = result.get("labels", [])
    ranked_scores = result.get("scores", [])

    scores: dict[str, float] = {}
    for index, label in enumerate(ranked_labels):
        score_value = 0.0
        if index < len(ranked_scores):
            score_value = float(ranked_scores[index])
        scores[str(label)] = round(score_value, 4)

    top_label = str(ranked_labels[0]) if ranked_labels else "unknown"
    top_score = float(ranked_scores[0]) if ranked_scores else 0.0

    normalized_class = "unknown"
    if top_label == "human behavior":
        normalized_class = "human"
    elif top_label == "bot behavior":
        normalized_class = "bot"

    return {
        "classification": normalized_class,
        "confidence": round(top_score, 4),
        "scores": scores,
        "model": bot_model_id,
        "model_status": bot_model_state["status"],
    }


@app.get("/behavior/model-status")
def behavior_model_status() -> dict[str, str]:
    ensure_bot_classifier_background()
    return {
        "model": bot_model_id,
        "status": bot_model_state["status"],
        "message": bot_model_state["message"],
    }
