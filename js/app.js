/**
 * Rumbo — App (mobile-first, Waze-style)
 */

let map, routeLayer, weatherLayer, warningsLayer;
let nearbyLayer, fuelLayer;
let gpsMarker, originMarker, destMarker;
let debounceTimers = {};
let watchId = null;
let isTracking = false;
let followMode = true;
let firstFix = true;
let currentLat = null, currentLon = null;
let routePreference = 'fastest';
let selectedReportType = null;
let routeStartTime = null;
let routeDurationMin = 0;
let routeSteps = [];
let fuelType = 'gasolina';
let fuelData = [];
let lastNearbyLat = null, lastNearbyLon = null;

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    initEvents();
    initReportModal();
    initTabs();
    startGPSTracking();
});

function initMap() {
    map = L.map('map', {
        center: [40.0, -3.7], zoom: 6,
        zoomControl: true, attributionControl: true
    });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OSM', maxZoom: 19
    }).addTo(map);

    routeLayer    = L.layerGroup().addTo(map);
    weatherLayer  = L.layerGroup().addTo(map);
    warningsLayer = L.layerGroup().addTo(map);
    nearbyLayer   = L.layerGroup().addTo(map);
    fuelLayer     = L.layerGroup().addTo(map);

    // Arrastrar el mapa desactiva auto-centrar
    map.on('dragstart', () => {
        if (followMode) {
            followMode = false;
            document.getElementById('centerBtn').classList.remove('active');
        }
    });
}

function initEvents() {
    document.getElementById('searchBtn').addEventListener('click', searchRoute);
    document.getElementById('newSearchBtn').addEventListener('click', newSearch);

    ['origin', 'destination'].forEach(id => {
        const el = document.getElementById(id);
        el.addEventListener('keydown', e => { if (e.key === 'Enter') searchRoute(); });
        el.addEventListener('input', () => {
            delete el.dataset.lat;
            delete el.dataset.lon;
            delete el.dataset.hasHouse;
            const dot = el.closest('.search-row')?.querySelector('.dot');
            if (dot) { dot.style.background = ''; dot.style.boxShadow = ''; }
            autocomplete(id);
        });
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.search-row') && !e.target.closest('.suggestions'))
            document.querySelectorAll('.suggestions').forEach(s => s.classList.remove('active'));
    });

    document.getElementById('myLocationBtn').addEventListener('click', () => {
        if (currentLat && currentLon) {
            const input = document.getElementById('origin');
            input.value = `${currentLat.toFixed(4)}, ${currentLon.toFixed(4)}`;
            input.dataset.lat = currentLat;
            input.dataset.lon = currentLon;
        }
    });

    document.querySelectorAll('[data-pref]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-pref]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            routePreference = btn.dataset.pref;
        });
    });

    document.getElementById('centerBtn').addEventListener('click', () => {
        followMode = true;
        const btn = document.getElementById('centerBtn');
        btn.classList.add('active');
        if (currentLat && currentLon) {
            map.setView([currentLat, currentLon], 15, { animate: true });
        }
    });

    document.getElementById('exitRoute').addEventListener('click', () => {
        exitRouteMode();
    });

    document.getElementById('fuelBtn').addEventListener('click', () => {
        const btn = document.getElementById('fuelBtn');
        const prefs = document.getElementById('fuelPrefs');
        const active = btn.classList.toggle('active');
        prefs.classList.toggle('hidden', !active);
        if (active) {
            fuelLayer.addTo(map);
            if (fuelData.length) drawFuelMarkers(fuelData);
            else if (currentLat && currentLon) loadNearby(currentLat, currentLon);
        } else {
            fuelLayer.remove();
        }
    });

    document.querySelectorAll('[data-fuel]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-fuel]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            fuelType = btn.dataset.fuel;
            if (fuelData.length) drawFuelMarkers(fuelData);
        });
    });
}

function initTabs() {
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.tab).classList.add('active');
        });
    });
}

// ============================================
// GPS
// ============================================
function startGPSTracking() {
    if (!navigator.geolocation) return;
    if (watchId !== null) return;

    isTracking = true;
    document.getElementById('centerBtn').classList.add('active');
    document.getElementById('speedometer').classList.remove('hidden');

    // Ubicación rápida (WiFi/IP) para centrar el mapa al instante
    navigator.geolocation.getCurrentPosition(
        pos => {
            if (firstFix) {
                const { latitude, longitude } = pos.coords;
                currentLat = latitude;
                currentLon = longitude;
                firstFix = false;
                updateGpsMarker(latitude, longitude, null);
                map.flyTo([latitude, longitude], 15, { duration: 1.2 });
                loadNearby(latitude, longitude);
            }
        },
        () => {},
        { enableHighAccuracy: false, timeout: 5000, maximumAge: 30000 }
    );

    // GPS de alta precisión para tracking continuo
    watchId = navigator.geolocation.watchPosition(
        pos => {
            const { latitude, longitude, speed, heading } = pos.coords;
            currentLat = latitude;
            currentLon = longitude;

            updateGpsMarker(latitude, longitude, heading);

            const kmh = speed ? Math.round(speed * 3.6) : 0;
            updateSpeedometer(kmh);

            // Actualizar señal de navegación
            if (routeSteps.length) updateNavSign(latitude, longitude);

            if (firstFix) {
                firstFix = false;
                map.flyTo([latitude, longitude], 15, { duration: 1.5 });
                loadNearby(latitude, longitude);
            } else if (followMode) {
                map.setView([latitude, longitude], map.getZoom(), { animate: true, duration: 0.5 });
            }

            // Recargar avisos/gasolineras si te mueves > 3km
            if (!firstFix && lastNearbyLat !== null) {
                const moved = haversineKm(lastNearbyLat, lastNearbyLon, latitude, longitude);
                if (moved > 3) loadNearby(latitude, longitude);
            }
        },
        err => {
            console.warn('GPS:', err.message);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 2000 }
    );
}

function updateGpsMarker(lat, lon, heading) {
    if (gpsMarker) {
        gpsMarker.setLatLng([lat, lon]);
    } else {
        const icon = L.divIcon({
            className: '',
            html: `<div class="gps-dot ${isTracking ? 'tracking' : ''}"></div>`,
            iconSize: [22, 22], iconAnchor: [11, 11]
        });
        gpsMarker = L.marker([lat, lon], { icon, zIndex: 9999 }).addTo(map);
    }
}

function updateSpeedometer(kmh) {
    const el = document.getElementById('speedometer');
    document.getElementById('speedValue').textContent = kmh;
    el.classList.remove('ok', 'warn', 'over');
    if (kmh <= 100) el.classList.add('ok');
    else if (kmh <= 120) el.classList.add('warn');
    else el.classList.add('over');
}

// ============================================
// NEARBY (avisos + gasolineras en 15km)
// ============================================
async function loadNearby(lat, lon) {
    lastNearbyLat = lat;
    lastNearbyLon = lon;

    const dLat = 15 / 111;
    const dLon = 15 / (111 * Math.cos(lat * Math.PI / 180));
    const south = lat - dLat, north = lat + dLat;
    const west  = lon - dLon, east  = lon + dLon;

    // Avisos cercanos
    try {
        const wRes = await fetch(`api/warnings.php?south=${south.toFixed(4)}&west=${west.toFixed(4)}&north=${north.toFixed(4)}&east=${east.toFixed(4)}`);
        let warnings = await wRes.json();
        if (!Array.isArray(warnings)) warnings = [];
        const filtered = warnings.filter(w => w.lat && w.lon && haversineKm(lat, lon, w.lat, w.lon) <= 15);
        drawNearbyWarnings(filtered);
    } catch (e) {
        console.warn('Avisos cercanos:', e);
    }

    // Gasolineras
    try {
        const fRes = await fetch(`api/fuel.php?lat=${lat}&lon=${lon}&radius=15`);
        let stations = await fRes.json();
        if (!Array.isArray(stations)) stations = [];
        fuelData = stations;
        if (document.getElementById('fuelBtn').classList.contains('active')) drawFuelMarkers(stations);
    } catch (e) {
        console.warn('Gasolineras:', e);
    }
}

function drawNearbyWarnings(list) {
    nearbyLayer.clearLayers();
    list.forEach(w => {
        if (!w.lat || !w.lon) return;
        const icon = L.divIcon({
            className: '',
            html: `<div class="waze-marker ${w.icon}">${w.emoji || '⚠️'}</div>`,
            iconSize: [40, 48], iconAnchor: [20, 40]
        });
        L.marker([w.lat, w.lon], { icon }).addTo(nearbyLayer)
            .bindPopup(buildWarningPopup(w), { maxWidth: 280 });
    });
}

function drawFuelMarkers(stations) {
    fuelLayer.clearLayers();
    const isDiesel = fuelType === 'diesel';
    const markerColor = isDiesel ? '#f59e0b' : '#3b82f6';

    stations.forEach(s => {
        const price = getDisplayPrice(s.prices, fuelType);
        const icon = L.divIcon({
            className: '',
            html: `<div class="fuel-marker" style="background:linear-gradient(135deg,${markerColor},${markerColor}dd);--marker-color:${markerColor}dd"><span class="fuel-price">${price}</span></div>`,
            iconSize: [52, 24], iconAnchor: [26, 24]
        });

        const fuelTypes = [
            { key: 'gasolina', label: 'Gasolina 95', dbKey: 'gasolina_95', color: '#3b82f6' },
            { key: 'gasolina', label: 'Gasolina 98', dbKey: 'gasolina_98', color: '#818cf8' },
            { key: 'diesel',    label: 'Diésel A',    dbKey: 'diesel',      color: '#f59e0b' },
        ];
        const rows = fuelTypes
            .filter(ft => s.prices[ft.dbKey] != null)
            .map(ft => {
                const isSel = ft.key === fuelType;
                return `<div class="fp-row${isSel ? ' fp-selected' : ''}">
                    <span class="fp-dot" style="background:${ft.color}"></span>
                    <span class="fp-label">${ft.label}</span>
                    <span class="fp-value">${s.prices[ft.dbKey].toFixed(3)}</span>
                    <span class="fp-unit">€/L</span>
                </div>`;
            });

        const popup = `
            <div class="fuel-popup">
                <div class="fp-header">
                    <div class="fp-brand">
                        <span class="fp-pump">⛽</span>
                        <span class="fp-name">${s.name}</span>
                    </div>
                    <span class="fp-badge">${s.distance} km</span>
                </div>
                <div class="fp-prices">${rows.join('')}</div>
                <div class="fp-footer">
                    <div class="fp-addr">${s.address}</div>
                    ${s.schedule ? `<div class="fp-sched">🕐 ${s.schedule}</div>` : ''}
                </div>
            </div>`;

        L.marker([s.lat, s.lon], { icon }).addTo(fuelLayer).bindPopup(popup, { maxWidth: 280, className: 'fuel-popup-wrapper' });
    });

    if (!document.getElementById('fuelBtn').classList.contains('active')) fuelLayer.remove();
}

function getCheapestPrice(prices) {
    const vals = [prices.gasolina_95, prices.gasolina_98, prices.diesel].filter(v => v !== null);
    if (!vals.length) return '--';
    const min = Math.min(...vals);
    return min.toFixed(3);
}

function getDisplayPrice(prices, type) {
    if (type === 'diesel' && prices.diesel != null) return prices.diesel.toFixed(3);
    if (type === 'gasolina') {
        const g95 = prices.gasolina_95;
        const g98 = prices.gasolina_98;
        if (g95 != null && g98 != null) return Math.min(g95, g98).toFixed(3);
        if (g95 != null) return g95.toFixed(3);
        if (g98 != null) return g98.toFixed(3);
    }
    return getCheapestPrice(prices);
}

function haversineKm(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) ** 2 +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// ============================================
// NAV SIGN
// ============================================
function updateNavSign(lat, lon) {
    if (!routeSteps.length) return;

    // Encontrar el paso más cercano
    let closestIdx = 0;
    let minDist = Infinity;
    routeSteps.forEach((step, i) => {
        const [sLon, sLat] = step.maneuver.location;
        const d = haversineKm(lat, lon, sLat, sLon);
        if (d < minDist) { minDist = d; closestIdx = i; }
    });

    // Mostrar el siguiente paso (el que viene)
    const nextIdx = Math.min(closestIdx + 1, routeSteps.length - 1);
    const step = routeSteps[nextIdx];
    const [sLon, sLat] = step.maneuver.location;
    const distKm = haversineKm(lat, lon, sLat, sLon);

    const sign = document.getElementById('navSign');
    const inner = sign.querySelector('.nav-sign-inner');
    sign.classList.remove('hidden');

    // Estilo según tipo de carretera
    const signClass = getSignClass(step.ref, step.maneuver.type);
    inner.className = 'nav-sign-inner ' + signClass;

    // Flecha de maniobra
    sign.querySelector('.nav-arrow').innerHTML = getManeuverIcon(step.maneuver.type, step.maneuver.modifier);

    // Info
    const roadName = step.ref || step.name || '';
    sign.querySelector('.nav-road').textContent = roadName;
    sign.querySelector('.nav-instruction').textContent = getManeuverText(step.maneuver.type, step.maneuver.modifier, distKm);

    // Distancia
    if (distKm < 1) {
        sign.querySelector('.nav-dist-val').textContent = Math.round(distKm * 1000);
        sign.querySelector('.nav-dist-unit').textContent = 'm';
    } else {
        sign.querySelector('.nav-dist-val').textContent = distKm.toFixed(1);
        sign.querySelector('.nav-dist-unit').textContent = 'km';
    }
}

function getSignClass(ref, type) {
    if (type === 'arrive') return 'sign-arrive';
    if (!ref) return 'sign-blue';
    const r = ref.toUpperCase().trim();
    if (r.startsWith('AP') || r.startsWith('E-')) return 'sign-green';
    if (r.startsWith('A')) return 'sign-blue';
    return 'sign-white';
}

function getManeuverIcon(type, mod) {
    const arrow = `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M16 26V8"/><path d="M8 14l8-8 8 8"/></svg>`;

    if (type === 'arrive')    return `<svg viewBox="0 0 32 32" fill="currentColor"><circle cx="16" cy="16" r="6"/><path d="M16 4v4m0 16v4M4 16h4m16 0h4" stroke="currentColor" stroke-width="2" fill="none"/></svg>`;
    if (type === 'depart')    return `<div style="font-size:28px">📍</div>`;
    if (type === 'roundabout') return `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 6a10 10 0 11-8 4"/><path d="M6 8l2 4 4-2"/></svg>`;

    let rotation = 0;
    if (mod === 'left' || mod === 'sharp-left') rotation = -90;
    else if (mod === 'right' || mod === 'sharp-right') rotation = 90;
    else if (mod === 'slight-left') rotation = -45;
    else if (mod === 'slight-right') rotation = 45;
    else if (type === 'uturn') rotation = 180;

    return `<div style="transform:rotate(${rotation}deg)">${arrow}</div>`;
}

function getManeuverText(type, mod, distKm) {
    if (type === 'arrive') return 'Destino';
    if (type === 'depart') return 'Salida';
    if (type === 'roundabout') return 'Rotonda';
    if (type === 'merge') return 'Incorpórese';
    if (type === 'uturn') return 'Cambie de sentido';
    if (type === 'fork') {
        return mod?.includes('left') ? 'Bifurcación izq.' : 'Bifurcación dcha.';
    }
    const turns = {
        'sharp-left':  'Gire a la izquierda',
        'left':        'Gire a la izquierda',
        'slight-left': 'Manténgase a la izquierda',
        'straight':    'Siga recto',
        'slight-right':'Manténgase a la derecha',
        'right':       'Gire a la derecha',
        'sharp-right': 'Gire a la derecha',
    };
    return turns[mod] || 'Siga recto';
}

// ============================================
// AUTOCOMPLETE
// ============================================
function sugIcon(type, cls) {
    if (type === 'city' || type === 'town') return '🏙️';
    if (type === 'village' || type === 'hamlet' || type === 'locality') return '🏘️';
    if (type === 'house')             return '🏠';
    if (type === 'street' || type === 'highway' || cls === 'highway') return '🛣️';
    if (type === 'building' || cls === 'building') return '🏢';
    if (type === 'railway' || cls === 'railway') return '🚆';
    if (type === 'aerodrome')         return '✈️';
    if (cls === 'boundary' || cls === 'place') return '📍';
    return '📍';
}

function autocomplete(id) {
    const input = document.getElementById(id);
    const box = document.getElementById(id + '-suggestions');
    const q = input.value.trim();

    if (debounceTimers[id]) clearTimeout(debounceTimers[id]);
    if (q.length < 3) { box.classList.remove('active'); return; }

    debounceTimers[id] = setTimeout(async () => {
        try {
            const res = await fetch(`api/geocode.php?q=${encodeURIComponent(q)}`);
            const data = await res.json();
            if (!data.length) { box.classList.remove('active'); return; }

            box.innerHTML = data.map((r, i) => {
                const parts = (r.display_name || '').split(',');
                const main = parts.slice(0, 2).join(',').trim();
                const detail = parts.slice(2, 4).join(',').trim();
                const icon = sugIcon(r.type, r.class);
                return `<div class="suggestion-item" data-i="${i}">
                    <div class="sug-icon">${icon}</div>
                    <div class="sug-body">
                        <div class="sug-main">${main}</div>
                        ${detail ? `<div class="sug-detail">${detail}</div>` : ''}
                    </div>
                    <span class="sug-badge">${r.type || ''}</span>
                </div>`;
            }).join('');

            // Posicionar el dropdown debajo del input
            const rect = input.getBoundingClientRect();
            box.style.top = (rect.bottom + 6) + 'px';
            box.style.left = rect.left + 'px';
            box.style.width = rect.width + 'px';

            box.classList.add('active');

            box.querySelectorAll('.suggestion-item').forEach(el => {
                el.addEventListener('click', () => {
                    const r = data[parseInt(el.dataset.i)];
                    // Mantener lo que el usuario escribió (nº casa)
                    input.dataset.lat = r.lat;
                    input.dataset.lon = r.lon;
                    // Guardar si la sugerencia tiene número de casa real
                    input.dataset.hasHouse = (r.housenumber || r.type === 'house') ? '1' : '0';
                    box.classList.remove('active');
                    const dot = input.closest('.search-row')?.querySelector('.dot');
                    if (dot) { dot.style.background = 'var(--accent)'; dot.style.boxShadow = '0 0 8px var(--accent)'; }
                });
            });
        } catch (e) {}
    }, 350);
}

// ============================================
// SEARCH ROUTE
// ============================================
async function searchRoute() {
    document.querySelectorAll('.suggestions').forEach(s => s.classList.remove('active'));

    const oInput = document.getElementById('origin');
    const dInput = document.getElementById('destination');
    let oLat = oInput.dataset.lat, oLon = oInput.dataset.lon;
    let dLat = dInput.dataset.lat, dLon = dInput.dataset.lon;
    let approxMsg = '';

    // Verificar si el usuario puso número pero la sugerencia no lo tiene
    const oHasNumber = /\d+/.test(oInput.value.replace(/\d{5,}/, ''));
    const dHasNumber = /\d+/.test(dInput.value.replace(/\d{5,}/, ''));

    if (!oLat || !oLon) {
        const r = await geocode(oInput.value);
        if (!r) { showError('Origen no encontrado'); return; }
        oLat = r.lat; oLon = r.lon;
        if (r.approx) approxMsg = 'Origen: ubicación aproximada (número no disponible)';
    } else if (oHasNumber && oInput.dataset.hasHouse === '0') {
        approxMsg = 'Origen: número de casa no disponible en el mapa';
    }

    if (!dLat || !dLon) {
        const r = await geocode(dInput.value);
        if (!r) { showError('Destino no encontrado'); return; }
        dLat = r.lat; dLon = r.lon;
        if (r.approx) approxMsg += (approxMsg ? ' · ' : '') + 'Destino: ubicación aproximada';
    } else if (dHasNumber && dInput.dataset.hasHouse === '0') {
        approxMsg += (approxMsg ? ' · ' : '') + 'Destino: número no disponible';
    }

    if (approxMsg) showError(approxMsg);

    showLoading(true); hideError();

    try {
        const pref = routePreference === 'shortest' ? '&shortest=true' : '';
        const routeRes = await fetch(`api/route.php?olat=${oLat}&olon=${oLon}&dlat=${dLat}&dlon=${dLon}${pref}`);
        const route = await routeRes.json();
        if (route.error) { showError(route.error); return; }

        const pts = route.samplePoints;
        const cParam = pts.map(p => `${p.lat},${p.lon}`).join(';');
        const wRes = await fetch(`api/weather.php?coords=${encodeURIComponent(cParam)}`);
        let weather = await wRes.json();
        if (weather.error) weather = [];

        const b = route.bounds;
        const wnRes = await fetch(`api/warnings.php?south=${b.south}&west=${b.west}&north=${b.north}&east=${b.east}`);
        let warnings = await wnRes.json();
        if (!Array.isArray(warnings)) warnings = [];

        // Filtrar: solo avisos a menos de 3 km de la ruta
        const routeCoords = route.geometry.coordinates; // [[lon,lat], ...]
        warnings = filterToRoute(warnings, routeCoords, 3);

        drawRoute(route, oLat, oLon, dLat, dLon);
        drawWeatherMarkers(pts, weather);
        drawWazeMarkers(warnings);
        fillResults(route, weather, warnings);

        // Modo ruta
        routeSteps = route.steps || [];
        enterRouteMode(parseFloat(oLat), parseFloat(oLon), route, weather);

    } catch (e) {
        console.error(e);
        showError('Error calculando la ruta');
    } finally {
        showLoading(false);
    }
}

async function geocode(q) {
    if (!q.trim()) return null;
    try {
        const r = await fetch(`api/geocode.php?q=${encodeURIComponent(q)}`);
        const d = await r.json();
        if (d.length) {
            const result = d[0];
            // Si el usuario puso número y Nominatim no lo tiene, marcar como aproximado
            const hasNumber = /\d+/.test(q.replace(/\d{5,}/, '')); // ignorar CP
            const isApprox = hasNumber && result.type !== 'house';
            return { lat: result.lat, lon: result.lon, approx: isApprox };
        }
    } catch (e) {}
    return null;
}

/**
 * Filtra avisos: solo los que están a <= maxKm de algún punto de la ruta.
 * Usa distancia euclínea rápida (~111 km/lat).
 */
function filterToRoute(warnings, routeCoords, maxKm) {
    if (!routeCoords || !routeCoords.length) return warnings;

    // Muestrear la ruta (cada N puntos para no hacer 10000 comparaciones)
    const step = Math.max(1, Math.floor(routeCoords.length / 200));
    const sampled = [];
    for (let i = 0; i < routeCoords.length; i += step) {
        sampled.push(routeCoords[i]);
    }

    const maxDeg = maxKm / 111; // aproximación

    return warnings.filter(w => {
        if (!w.lat || !w.lon) return false;
        for (let i = 0; i < sampled.length; i++) {
            const dLat = w.lat - sampled[i][1];
            const dLon = w.lon - sampled[i][0];
            // Corregir longitud por cos(lat)
            const dist = Math.sqrt(dLat * dLat + (dLon * Math.cos(w.lat * Math.PI / 180)) ** 2);
            if (dist <= maxDeg) return true;
        }
        return false;
    });
}

// ============================================
// ROUTE MODE
// ============================================
function enterRouteMode(oLat, oLon, routeData, weatherData) {
    // Zoom a tu ubicación GPS real (como Google Maps), o al origen si no hay GPS
    const myLat = currentLat || oLat;
    const myLon = currentLon || oLon;
    map.setView([myLat, myLon], 15, { animate: true });

    // Activar follow
    followMode = true;
    document.getElementById('centerBtn').classList.add('active');

    // Ocultar avisos cercanos (la ruta tiene los suyos)
    nearbyLayer.remove();

    // Top bar
    const topBar = document.getElementById('topBar');
    topBar.classList.remove('hidden');

    // ETA
    routeDurationMin = routeData.duration;
    routeStartTime = Date.now();
    updateETA();
    setInterval(updateETA, 30000);

    // Info top bar
    document.getElementById('tbDetail').textContent = `${routeData.distance} km · ${Math.round(routeData.duration)} min`;

    // Clima predominante
    if (weatherData.length) {
        const main = weatherData[Math.floor(weatherData.length / 2)];
        document.getElementById('tbWeather').innerHTML = `
            <span class="tb-weather-icon">${main.icon}</span>
            <span class="tb-weather-temp">${main.temperature}°</span>`;
    }

    // Cambiar paneles
    document.getElementById('searchPanel').classList.add('hidden');
    document.getElementById('resultsPanel').classList.remove('hidden');

    // Ajustar FABs
    updateFabsPosition(true);

    // Señal de navegación
    if (currentLat && currentLon) updateNavSign(currentLat, currentLon);
    else updateNavSign(oLat, oLon);
}

function exitRouteMode() {
    document.getElementById('topBar').classList.add('hidden');
    document.getElementById('navSign').classList.add('hidden');
    document.getElementById('searchPanel').classList.remove('hidden');
    document.getElementById('resultsPanel').classList.add('hidden');

    routeLayer.clearLayers();
    weatherLayer.clearLayers();
    warningsLayer.clearLayers();
    routeSteps = [];

    // Restaurar capas cercanas
    nearbyLayer.addTo(map);
    if (document.getElementById('fuelBtn').classList.contains('active')) fuelLayer.addTo(map);

    updateFabsPosition(false);
    map.setView([40.0, -3.7], 6);
}

function newSearch() {
    exitRouteMode();
}

function updateETA() {
    if (!routeStartTime) return;
    const elapsed = (Date.now() - routeStartTime) / 60000;
    const remaining = Math.max(0, routeDurationMin - elapsed);
    const eta = new Date(Date.now() + remaining * 60000);
    const h = eta.getHours().toString().padStart(2, '0');
    const m = eta.getMinutes().toString().padStart(2, '0');
    document.getElementById('tbEta').textContent = `${h}:${m}`;
}

function updateFabsPosition(inRoute) {
    const offset = inRoute ? '70px' : '0px';
    document.querySelectorAll('.map-fabs, .fab-red').forEach(el => {
        el.style.setProperty('--top-offset', offset);
    });
}

// ============================================
// DRAWING
// ============================================
function drawRoute(data, oLat, oLon, dLat, dLon) {
    routeLayer.clearLayers();
    const coords = data.geometry.coordinates.map(c => [c[1], c[0]]);

    L.polyline(coords, { color: '#00d4aa', weight: 12, opacity: 0.12, smoothFactor: 1 }).addTo(routeLayer);
    L.polyline(coords, { color: '#00d4aa', weight: 5, opacity: 0.85, smoothFactor: 1, lineCap: 'round' }).addTo(routeLayer);

    originMarker = L.marker([parseFloat(oLat), parseFloat(oLon)], {
        icon: L.divIcon({ className: '', html: '<div class="waze-marker" style="background:#00d4aa">🟢</div>', iconSize: [40, 48], iconAnchor: [20, 40] })
    }).addTo(routeLayer).bindPopup('<div class="popup-title">Origen</div>');

    destMarker = L.marker([parseFloat(dLat), parseFloat(dLon)], {
        icon: L.divIcon({ className: '', html: '<div class="waze-marker" style="background:#ef4444">🏁</div>', iconSize: [40, 48], iconAnchor: [20, 40] })
    }).addTo(routeLayer).bindPopup('<div class="popup-title">Destino</div>');
}

function drawWeatherMarkers(pts, w) {
    weatherLayer.clearLayers();
    pts.forEach((p, i) => {
        const d = w[i]; if (!d) return;
        const icon = L.divIcon({ className: '', html: `<div class="weather-marker ${d.severity}">${d.icon}</div>`, iconSize: [36, 36], iconAnchor: [18, 18] });
        L.marker([p.lat, p.lon], { icon }).addTo(weatherLayer)
            .bindPopup(`<div class="popup-title">${d.icon} ${d.condition}</div><div class="popup-temp">${d.temperature}°C</div><div class="popup-detail">Sensación: ${d.feels_like}° · Viento: ${d.wind_speed} km/h<br>${p.label || ''}</div>`);
    });
}

function drawWazeMarkers(list) {
    warningsLayer.clearLayers();
    list.forEach(w => {
        if (!w.lat || !w.lon) return;
        const icon = L.divIcon({ className: '', html: `<div class="waze-marker ${w.icon}">${w.emoji || '⚠️'}</div>`, iconSize: [40, 48], iconAnchor: [20, 40] });
        L.marker([w.lat, w.lon], { icon }).addTo(warningsLayer)
            .bindPopup(buildWarningPopup(w), { maxWidth: 280 });
    });
}

function buildWarningPopup(w) {
    let html = `<div class="popup-title">${w.emoji || '⚠️'} ${w.title}</div>`;
    html += `<div class="popup-detail">${w.detail || ''}`;
    if (w.road) html += `<br><strong>🛣️ ${w.road}</strong>`;
    html += `</div>`;
    if (w.source) html += `<div class="popup-source">${w.source}</div>`;

    // Votación solo para avisos de comunidad (reportes de usuario)
    if (w.id && w.id.startsWith('rpt_')) {
        const vc = w.votes_confirm || 0;
        const vd = w.votes_dismiss || 0;
        html += `<div class="warning-vote" id="vote-${w.id}">
            <span class="vote-label">¿Sigue aquí?</span>
            <button class="vote-btn vote-confirm" onclick="voteWarning('${w.id}','confirm')">👍 ${vc > 0 ? vc : ''} Sí</button>
            <button class="vote-btn vote-dismiss" onclick="voteWarning('${w.id}','dismiss')">👎 ${vd > 0 ? vd : ''} No</button>
        </div>`;
    }
    return html;
}

async function voteWarning(reportId, vote) {
    try {
        const r = await fetch('api/warnings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'vote', report_id: reportId, vote })
        });
        const d = await r.json();
        if (d.ok) {
            const el = document.getElementById(`vote-${reportId}`);
            if (el) {
                el.innerHTML = vote === 'confirm'
                    ? '<span class="vote-thanks">✅ Gracias por confirmar</span>'
                    : '<span class="vote-thanks">🗑️ Se eliminará pronto</span>';
            }
        }
    } catch (e) {
        console.error('Vote error:', e);
    }
}

// ============================================
// FILL RESULTS
// ============================================
function fillResults(route, weather, warnings) {
    document.getElementById('statDistance').textContent = route.distance;
    document.getElementById('statDuration').textContent = Math.round(route.duration);
    document.getElementById('statWarnings').textContent = warnings.length;

    const temps = weather.filter(w => w.temperature !== null).map(w => w.temperature);
    document.getElementById('statTemp').textContent = temps.length ? (temps.reduce((a, b) => a + b, 0) / temps.length).toFixed(0) : '--';

    // Weather strip
    const conds = {};
    weather.forEach(w => { if (!conds[w.condition]) conds[w.condition] = w; });
    document.getElementById('weatherStrip').innerHTML = Object.values(conds).map(w =>
        `<div class="wstrip-item wstrip-sev ${w.severity}"><span class="wstrip-icon">${w.icon}</span>${w.condition}</div>`
    ).join('');

    // Warnings tab
    const wList = document.getElementById('warningsList');
    if (!warnings.length) {
        wList.innerHTML = '<div class="no-items">Sin avisos en la ruta</div>';
    } else {
        wList.innerHTML = warnings.map(w => `
            <div class="warning-item" data-lat="${w.lat}" data-lon="${w.lon}">
                <span class="wi-emoji">${w.emoji || '⚠️'}</span>
                <div class="wi-body">
                    <div class="wi-title">${w.title}</div>
                    <div class="wi-desc">${w.detail || ''}</div>
                    ${w.road ? `<span class="wi-road">🛣️ ${w.road}</span>` : ''}
                    ${w.source ? `<div class="wi-source">${w.source}</div>` : ''}
                </div>
            </div>`).join('');
        wList.querySelectorAll('.warning-item').forEach(el => {
            el.addEventListener('click', () => {
                map.setView([parseFloat(el.dataset.lat), parseFloat(el.dataset.lon)], 14);
            });
        });
    }

    // Timeline tab
    document.getElementById('timelineScroll').innerHTML = route.samplePoints.map((p, i) => {
        const w = weather[i]; if (!w) return '';
        return `<div class="wt-point" data-lat="${p.lat}" data-lon="${p.lon}">
            <span class="wt-icon">${w.icon}</span>
            <span class="wt-temp">${w.temperature}°</span>
            <span class="wt-label">${p.label || ''}</span>
        </div>`;
    }).join('');
    document.getElementById('timelineScroll').querySelectorAll('.wt-point').forEach(el => {
        el.addEventListener('click', () => map.setView([parseFloat(el.dataset.lat), parseFloat(el.dataset.lon)], 10));
    });

    // Detail tab
    document.getElementById('weatherCards').innerHTML = route.samplePoints.map((p, i) => {
        const w = weather[i]; if (!w) return '';
        return `<div class="wcard" data-lat="${p.lat}" data-lon="${p.lon}">
            <div class="wcard-bar ${w.severity}"></div>
            <span class="wcard-icon">${w.icon}</span>
            <div class="wcard-body">
                <div class="wcard-loc">${p.label || `Punto ${i + 1}`}</div>
                <div class="wcard-cond">${w.condition}${w.precipitation > 0 ? ` · ${w.precipitation}mm` : ''}</div>
            </div>
            <div class="wcard-meta">
                <div class="wcard-temp">${w.temperature}°</div>
                <div class="wcard-wind">💨 ${w.wind_speed} km/h</div>
            </div>
        </div>`;
    }).join('');
    document.getElementById('weatherCards').querySelectorAll('.wcard').forEach(el => {
        el.addEventListener('click', () => map.setView([parseFloat(el.dataset.lat), parseFloat(el.dataset.lon)], 10));
    });
}

// ============================================
// REPORT MODAL
// ============================================
function initReportModal() {
    const modal = document.getElementById('reportModal');
    const submitBtn = document.getElementById('submitReport');

    document.getElementById('reportBtn').addEventListener('click', () => modal.classList.remove('hidden'));
    document.getElementById('closeReportModal').addEventListener('click', () => modal.classList.add('hidden'));
    modal.querySelector('.modal-backdrop').addEventListener('click', () => modal.classList.add('hidden'));

    document.querySelectorAll('.rpt-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.rpt-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            selectedReportType = btn.dataset.type;
            submitBtn.disabled = false;
        });
    });

    submitBtn.addEventListener('click', async () => {
        if (!selectedReportType) return;
        let lat = currentLat, lon = currentLon;
        if (!lat || !lon) { const c = map.getCenter(); lat = c.lat; lon = c.lng; }

        const body = {
            type: selectedReportType, lat, lon,
            road: document.getElementById('reportRoad').value,
            comment: document.getElementById('reportComment').value
        };

        try {
            const r = await fetch('api/warnings.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const d = await r.json();
            if (d.ok) {
                modal.classList.add('hidden');
                document.querySelectorAll('.rpt-btn').forEach(b => b.classList.remove('active'));
                submitBtn.disabled = true;
                document.getElementById('reportRoad').value = '';
                document.getElementById('reportComment').value = '';
                selectedReportType = null;
                alert('¡Reporte enviado!');
            }
        } catch (e) { showError('Error al enviar'); }
    });
}

// ============================================
// UI HELPERS
// ============================================
function showLoading(v) { document.getElementById('loading').classList.toggle('hidden', !v); }
function showError(m) { const el = document.getElementById('error'); el.textContent = m; el.classList.remove('hidden'); setTimeout(() => el.classList.add('hidden'), 4000); }
function hideError() { document.getElementById('error').classList.add('hidden'); }