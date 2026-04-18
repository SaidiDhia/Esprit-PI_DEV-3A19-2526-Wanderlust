import os
import time
from fastapi import FastAPI
from pydantic import BaseModel, Field
import redis


app = FastAPI(title="Rules Engine API")


class TrackActionRequest(BaseModel):
    user_id: str
    module: str
    action: str


class RulesScoreRequest(BaseModel):
    user_id: str
    metrics: dict[str, float | int | bool | str | None] = Field(default_factory=dict)


redis_url = os.getenv("REDIS_URL", "redis://localhost:6379/0")
redis_client = redis.Redis.from_url(redis_url, decode_responses=True)


@app.get("/health")
def health() -> dict[str, str]:
    try:
        redis_client.ping()
        return {"status": "ok", "redis": "connected"}
    except Exception:
        return {"status": "degraded", "redis": "disconnected"}


def _count_recent(key: str, window_seconds: int, now: int) -> int:
    redis_client.zremrangebyscore(key, 0, now - window_seconds)
    return int(redis_client.zcard(key))


@app.post("/rules/track-action")
def track_action(payload: TrackActionRequest) -> dict[str, int]:
    now = int(time.time())
    event_member = f"{now}:{time.time_ns()}:{payload.module}:{payload.action}"
    user_key = f"risk:{payload.user_id}:actions"
    booking_key = f"risk:{payload.user_id}:bookings"

    redis_client.zadd(user_key, {event_member: now})
    redis_client.expire(user_key, 600)

    if payload.module == "booking" and payload.action in {"booking_request_created", "booking_request_updated"}:
        redis_client.zadd(booking_key, {event_member: now})
        redis_client.expire(booking_key, 600)

    requests_10s = _count_recent(user_key, 10, now)
    bookings_5s = _count_recent(booking_key, 5, now)

    return {
        "requests_10s": requests_10s,
        "bookings_5s": bookings_5s,
    }


@app.post("/rules/score")
def rules_score(payload: RulesScoreRequest) -> dict[str, float | int]:
    now = int(time.time())
    user_key = f"risk:{payload.user_id}:actions"
    booking_key = f"risk:{payload.user_id}:bookings"

    requests_10s = _count_recent(user_key, 10, now)
    bookings_5s = _count_recent(booking_key, 5, now)

    failed_logins_2m = int(payload.metrics.get("failed_logins_2m") or 0)
    new_device = bool(payload.metrics.get("new_device") or False)
    geo_jump = bool(payload.metrics.get("geo_jump") or False)
    cancel_ratio = float(payload.metrics.get("cancel_booking_ratio") or 0.0)

    click_speed_score = 0.0
    if requests_10s > 20:
        click_speed_score += 20.0
    if bookings_5s > 3:
        click_speed_score += 30.0

    login_failure_score = 0.0
    if failed_logins_2m >= 5:
        login_failure_score += 25.0
    if new_device:
        login_failure_score += 20.0
    if geo_jump:
        login_failure_score += 20.0

    cancellation_abuse_score = 0.0
    if cancel_ratio > 0.7:
        cancellation_abuse_score += 70.0

    click_speed_score = min(click_speed_score, 100.0)
    login_failure_score = min(login_failure_score, 100.0)
    cancellation_abuse_score = min(cancellation_abuse_score, 100.0)

    return {
        "requests_10s": requests_10s,
        "bookings_5s": bookings_5s,
        "click_speed_score": click_speed_score,
        "login_failure_score": login_failure_score,
        "cancellation_abuse_score": cancellation_abuse_score,
    }
