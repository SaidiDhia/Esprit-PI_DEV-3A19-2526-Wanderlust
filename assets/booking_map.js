import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

const TUNISIA_BOUNDS = {
    south: 30.1,
    west: 7.5,
    north: 37.6,
    east: 11.8,
};
const TUNISIA_CENTER = [34.0, 9.5];

function isInTunisia(latitude, longitude) {
    return latitude >= TUNISIA_BOUNDS.south
        && latitude <= TUNISIA_BOUNDS.north
        && longitude >= TUNISIA_BOUNDS.west
        && longitude <= TUNISIA_BOUNDS.east;
}

function escapeHtml(value) {
    return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function normalizePlaces(rawPlaces) {
    if (!Array.isArray(rawPlaces)) {
        return [];
    }

    return rawPlaces
        .map((place) => {
            const latitude = Number(place.latitude);
            const longitude = Number(place.longitude);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                return null;
            }

            return {
                id: place.id,
                title: place.title || 'Untitled place',
                city: place.city || '',
                category: place.category || 'Stay',
                pricePerDay: Number(place.pricePerDay) || 0,
                avgRating: Number.isFinite(Number(place.avgRating)) ? Number(place.avgRating) : null,
                reviewsCount: Number(place.reviewsCount) || 0,
                imageUrl: place.imageUrl || null,
                latitude,
                longitude,
                url: place.url || '#',
            };
        })
        .filter((place) => place !== null);
}

function renderRatingStars(rating) {
    if (!Number.isFinite(rating) || rating <= 0) {
        return '<span style="font-size:12px;color:#64748b;">No ratings yet</span>';
    }

    const rounded = Math.round(rating);
    const fullStars = Math.max(0, Math.min(5, rounded));
    const emptyStars = 5 - fullStars;
    const stars = '★'.repeat(fullStars) + '☆'.repeat(emptyStars);

    return `<span style="font-size:14px;color:#f59e0b;letter-spacing:1px;">${stars}</span><span style="font-size:12px;color:#0f172a;margin-left:6px;">${rating.toFixed(1)}</span>`;
}

function renderTunisiaBaseLayer(map) {
    // This uses the Leaflet package installed in assets/vendor via importmap.
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
}

function initBookingMap() {
    const resultsRoot = document.getElementById('stays-results');
    const toggleButton = document.getElementById('map-view-toggle');
    const gridView = document.getElementById('stays-grid-view');
    const mapView = document.getElementById('stays-map-view');
    const mapContainer = document.getElementById('stays-map');
    const markerCount = document.getElementById('stays-map-count');

    if (!resultsRoot || !toggleButton || !gridView || !mapView || !mapContainer) {
        return;
    }

    let parsedPlaces;
    try {
        parsedPlaces = JSON.parse(resultsRoot.dataset.mapPlaces || '[]');
    } catch {
        parsedPlaces = [];
    }

    const userLatitude = Number(resultsRoot.dataset.userLat || '');
    const userLongitude = Number(resultsRoot.dataset.userLng || '');
    const radiusKm = Number(resultsRoot.dataset.radiusKm || '');
    const hasUserLocation = Number.isFinite(userLatitude)
        && Number.isFinite(userLongitude)
        && isInTunisia(userLatitude, userLongitude);
    const hasRadiusFilter = Number.isFinite(radiusKm) && radiusKm > 0;

    const places = normalizePlaces(parsedPlaces).filter((place) => isInTunisia(place.latitude, place.longitude));
    if (markerCount) {
        if (hasUserLocation && hasRadiusFilter) {
            markerCount.textContent = `${places.length} place${places.length === 1 ? '' : 's'} within ${radiusKm.toFixed(1)} km`;
        } else {
            markerCount.textContent = `${places.length} place${places.length === 1 ? '' : 's'} in Tunisia`;
        }
    }

    let map = null;
    let mapReady = false;
    let mapModeEnabled = false;

    // Keep view switching resilient even if utility classes are unavailable.
    const showGridView = () => {
        gridView.style.display = '';
        mapView.style.display = 'none';
        mapView.classList.remove('visible');
    };

    const showMapView = () => {
        gridView.style.display = 'none';
        mapView.style.display = 'block';
        mapView.classList.add('visible');
    };

    showGridView();

    const setMapMode = (enabled) => {
        mapModeEnabled = enabled;

        if (enabled) {
            showMapView();
            toggleButton.textContent = 'Grid view';

            if (!mapReady) {
                if (typeof L === 'undefined' || typeof L.map !== 'function') {
                    showMapView();
                    mapContainer.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;padding:1rem;text-align:center;color:#0f172a;font-weight:600;">Offline map library failed to load.</div>';
                    return;
                }

                const tunisiaBounds = L.latLngBounds(
                    [TUNISIA_BOUNDS.south, TUNISIA_BOUNDS.west],
                    [TUNISIA_BOUNDS.north, TUNISIA_BOUNDS.east]
                );

                map = L.map(mapContainer, {
                    zoomControl: true,
                    scrollWheelZoom: true,
                    maxBounds: tunisiaBounds,
                    maxBoundsViscosity: 1.0,
                    minZoom: 6,
                    maxZoom: 18,
                    worldCopyJump: false,
                }).setView(TUNISIA_CENTER, 6);

                renderTunisiaBaseLayer(map);

                let userMarker = null;
                let radiusCircle = null;
                const bounds = [];

                if (hasUserLocation) {
                    userMarker = L.circleMarker([userLatitude, userLongitude], {
                        radius: 7,
                        color: '#0f172a',
                        weight: 2,
                        fillColor: '#22c55e',
                        fillOpacity: 0.95,
                    }).addTo(map).bindPopup('Your location');

                    bounds.push([userLatitude, userLongitude]);

                    if (hasRadiusFilter) {
                        radiusCircle = L.circle([userLatitude, userLongitude], {
                            radius: radiusKm * 1000,
                            color: '#0ea5e9',
                            weight: 2,
                            fillColor: '#38bdf8',
                            fillOpacity: 0.14,
                        }).addTo(map);
                    }
                }

                if (places.length > 0) {
                    places.forEach((place) => {
                        const ratingHtml = renderRatingStars(place.avgRating);
                        const reviewsCountLabel = `${place.reviewsCount} review${place.reviewsCount === 1 ? '' : 's'}`;
                        const imageHtml = place.imageUrl
                            ? `<img src="${escapeHtml(place.imageUrl)}" alt="${escapeHtml(place.title)}" style="width:74px;height:62px;object-fit:cover;border-radius:8px;border:1px solid #cbd5e1;flex-shrink:0;">`
                            : '';
                        const popupHtml = `
                            <div style="min-width: 230px;">
                                <div style="display:flex;gap:10px;align-items:flex-start;">
                                    ${imageHtml}
                                    <div style="min-width:0;">
                                        <p style="font-weight:700;margin:0 0 4px;">${escapeHtml(place.title)}</p>
                                        <p style="font-size:12px;margin:0;color:#475569;">${escapeHtml(place.city)} • ${escapeHtml(place.category)}</p>
                                        <p style="margin:6px 0 0;line-height:1;">${ratingHtml}</p>
                                        <p style="font-size:11px;margin:6px 0 0;color:#64748b;">${escapeHtml(reviewsCountLabel)}</p>
                                        <p style="font-size:12px;margin:8px 0 0;color:#0f172a;">$${place.pricePerDay.toFixed(2)} / night</p>
                                    </div>
                                </div>
                                <a href="${escapeHtml(place.url)}" style="display:inline-block;margin-top:8px;font-size:12px;color:#0369a1;font-weight:700;">Open place</a>
                            </div>
                        `;

                        L.circleMarker([place.latitude, place.longitude], {
                            radius: 7,
                            color: '#0f172a',
                            weight: 1,
                            fillColor: '#0284c7',
                            fillOpacity: 0.9,
                        }).addTo(map).bindPopup(popupHtml);
                        bounds.push([place.latitude, place.longitude]);
                    });

                    if (bounds.length === 1) {
                        map.setView(bounds[0], 12);
                    } else if (bounds.length > 1) {
                        map.fitBounds(bounds, { padding: [30, 30] });
                    }
                } else if (!hasUserLocation) {
                    map.fitBounds(tunisiaBounds, { padding: [20, 20] });
                }

                if (hasUserLocation && hasRadiusFilter && radiusCircle) {
                    map.fitBounds(radiusCircle.getBounds(), { padding: [24, 24] });
                } else if (hasUserLocation && userMarker) {
                    map.setView([userLatitude, userLongitude], 11);
                }

                mapReady = true;
            }

            window.setTimeout(() => {
                if (map) {
                    map.invalidateSize();
                }
            }, 100);

            return;
        }

        showGridView();
        toggleButton.textContent = 'Map view';
    };

    toggleButton.addEventListener('click', () => {
        setMapMode(!mapModeEnabled);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBookingMap);
} else {
    initBookingMap();
}
