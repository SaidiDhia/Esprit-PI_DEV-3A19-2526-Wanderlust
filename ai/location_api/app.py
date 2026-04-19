import json
import os
from pathlib import Path
import threading
from typing import Any

import osmium
from fastapi import FastAPI, Query


app = FastAPI(title="Tunisia OSM Places API")

_DATA_CACHE: list[dict[str, Any]] | None = None
_PBF_PATH = os.getenv("OSM_PBF_PATH", "/data/osm/tunisia-latest.osm.pbf")
_CACHE_PATH = os.getenv("LOCATION_CACHE_PATH", "/data/cache/tunisia_places_index.json")
_INDEX_LOCK = threading.Lock()
_INDEX_STATE: dict[str, str] = {
    "status": "idle",
    "message": "Index not started",
}

_TUNISIA_SOUTH = 30.1
_TUNISIA_NORTH = 37.6
_TUNISIA_WEST = 7.4
_TUNISIA_EAST = 11.8

_PLACE_TAG_KEYS = ("place", "amenity", "tourism", "shop", "leisure", "historic")


def _inside_tunisia(lat: float, lng: float) -> bool:
    return _TUNISIA_SOUTH <= lat <= _TUNISIA_NORTH and _TUNISIA_WEST <= lng <= _TUNISIA_EAST


def _normalize_text(value: str) -> str:
    return value.strip().lower()


class _PlaceNodeHandler(osmium.SimpleHandler):
    def __init__(self) -> None:
        super().__init__()
        self.items: list[dict[str, Any]] = []
        self._dedupe: set[str] = set()

    def node(self, node: osmium.osm.Node) -> None:
        tags = node.tags
        name = str(tags.get("name") or "").strip()
        if not name:
            return

        category_key = ""
        category_value = ""
        for key in _PLACE_TAG_KEYS:
            value = str(tags.get(key) or "").strip()
            if value:
                category_key = key
                category_value = value
                break

        if not category_key:
            return

        if not node.location.valid():
            return

        lat = float(node.location.lat)
        lng = float(node.location.lon)
        if not _inside_tunisia(lat, lng):
            return

        key = f"{name.lower()}|{lat:.6f}|{lng:.6f}|{category_key}:{category_value}"
        if key in self._dedupe:
            return
        self._dedupe.add(key)

        self.items.append(
            {
                "name": name,
                "latitude": lat,
                "longitude": lng,
                "category": f"{category_key}:{category_value}",
                "name_lc": _normalize_text(name),
            }
        )


def _read_json(path: Path) -> list[dict[str, Any]]:
    try:
        with path.open("r", encoding="utf-8") as f:
            payload = json.load(f)
    except Exception:
        return []

    if not isinstance(payload, list):
        return []

    rows: list[dict[str, Any]] = []
    for row in payload:
        if not isinstance(row, dict):
            continue
        name = str(row.get("name") or "").strip()
        if not name:
            continue
        try:
            lat = float(row.get("latitude"))
            lng = float(row.get("longitude"))
        except (TypeError, ValueError):
            continue
        if not _inside_tunisia(lat, lng):
            continue

        category = str(row.get("category") or "").strip()
        rows.append(
            {
                "name": name,
                "latitude": lat,
                "longitude": lng,
                "category": category,
                "name_lc": _normalize_text(name),
            }
        )

    return rows


def _write_json(path: Path, rows: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as f:
        json.dump(rows, f, ensure_ascii=False, separators=(",", ":"))


def _build_index_from_pbf(pbf_path: Path, cache_path: Path) -> list[dict[str, Any]]:
    if not pbf_path.is_file():
        return []

    handler = _PlaceNodeHandler()
    handler.apply_file(str(pbf_path), locations=False)
    rows = handler.items
    rows.sort(key=lambda item: (str(item.get("name_lc") or ""), str(item.get("category") or "")))
    try:
        _write_json(cache_path, rows)
    except Exception:
        pass
    return rows


def _load_data(force_reindex: bool = False) -> list[dict[str, Any]]:
    global _DATA_CACHE
    if _DATA_CACHE is not None and not force_reindex:
        return _DATA_CACHE

    pbf_path = Path(_PBF_PATH)
    cache_path = Path(_CACHE_PATH)

    if not force_reindex and cache_path.is_file() and pbf_path.is_file():
        try:
            if cache_path.stat().st_mtime >= pbf_path.stat().st_mtime:
                rows = _read_json(cache_path)
                if rows:
                    _DATA_CACHE = rows
                    return _DATA_CACHE
        except Exception:
            pass

    rows = _build_index_from_pbf(pbf_path, cache_path)
    _DATA_CACHE = rows
    return _DATA_CACHE


def _run_index_build(force_reindex: bool = False) -> None:
    global _DATA_CACHE
    if not _INDEX_LOCK.acquire(blocking=False):
        return

    try:
        _INDEX_STATE["status"] = "indexing"
        _INDEX_STATE["message"] = "Building Tunisia place index from OSM PBF"

        rows = _load_data(force_reindex=force_reindex)
        _DATA_CACHE = rows

        if rows:
            _INDEX_STATE["status"] = "ready"
            _INDEX_STATE["message"] = f"Indexed {len(rows)} places"
        else:
            _INDEX_STATE["status"] = "failed"
            _INDEX_STATE["message"] = "Index build returned zero rows"
    except Exception as exc:
        _INDEX_STATE["status"] = "failed"
        _INDEX_STATE["message"] = f"Index build failed: {exc}"
    finally:
        _INDEX_LOCK.release()


def _start_background_index(force_reindex: bool = False) -> None:
    if _INDEX_LOCK.locked():
        return

    thread = threading.Thread(target=_run_index_build, kwargs={"force_reindex": force_reindex}, daemon=True)
    thread.start()


@app.on_event("startup")
def startup_event() -> None:
    cache_rows = _read_json(Path(_CACHE_PATH))
    if cache_rows:
        global _DATA_CACHE
        _DATA_CACHE = cache_rows
        _INDEX_STATE["status"] = "ready"
        _INDEX_STATE["message"] = f"Loaded {len(cache_rows)} places from cache"
        return

    _INDEX_STATE["status"] = "indexing"
    _INDEX_STATE["message"] = "Starting first index build from OSM PBF"
    _start_background_index(force_reindex=False)


@app.get("/health")
def health() -> dict[str, Any]:
    if _DATA_CACHE is None and _INDEX_STATE.get("status") in {"idle", "failed"}:
        _start_background_index(force_reindex=False)

    item_count = len(_DATA_CACHE or [])
    return {
        "status": "ok" if item_count > 0 else "degraded",
        "items": item_count,
        "pbf_path": _PBF_PATH,
        "cache_path": _CACHE_PATH,
        "index_status": _INDEX_STATE.get("status", "unknown"),
        "index_message": _INDEX_STATE.get("message", ""),
    }


@app.post("/locations/reindex")
def reindex_locations() -> dict[str, Any]:
    _start_background_index(force_reindex=True)
    return {
        "status": "accepted",
        "index_status": _INDEX_STATE.get("status", "unknown"),
        "index_message": _INDEX_STATE.get("message", ""),
    }


@app.get("/locations/search")
def search_locations(
    q: str = Query(default="", min_length=1),
    limit: int = Query(default=8, ge=1, le=20),
    country: str = Query(default="tn"),
) -> dict[str, Any]:
    term = _normalize_text(q)
    if not term or _normalize_text(country) != "tn":
        return {"items": []}

    rows = _DATA_CACHE or []
    if not rows:
        if _INDEX_STATE.get("status") in {"idle", "failed"}:
            _start_background_index(force_reindex=False)
        return {"items": []}

    matches: list[dict[str, Any]] = []
    for row in rows:
        name_lc = str(row.get("name_lc") or "")
        category = str(row.get("category") or "")

        starts_name = name_lc.startswith(term)
        contains_name = term in name_lc
        contains_category = term in category.lower()
        if not (starts_name or contains_name or contains_category):
            continue

        score = 0
        if starts_name:
            score += 30
        if contains_name:
            score += 10
        if contains_category:
            score += 3

        matches.append(
            {
                "score": score,
                "name_sort": name_lc,
                "name": str(row.get("name") or ""),
                "latitude": float(row.get("latitude")),
                "longitude": float(row.get("longitude")),
                "category": category,
            }
        )

    matches.sort(key=lambda item: (-int(item["score"]), str(item["name_sort"])))

    items: list[dict[str, str]] = []
    dedupe: set[str] = set()
    for match in matches:
        if not match["name"]:
            continue
        key = f"{match['name']}|{match['latitude']:.8f}|{match['longitude']:.8f}"
        if key in dedupe:
            continue
        dedupe.add(key)

        label = str(match["name"])
        if match["category"]:
            label = f"{label} - {match['category']}"

        items.append(
            {
                "name": label,
                "latitude": f"{match['latitude']:.8f}",
                "longitude": f"{match['longitude']:.8f}",
            }
        )
        if len(items) >= limit:
            break

    return {"items": items}
