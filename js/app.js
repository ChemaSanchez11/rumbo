/**
 * Rumbo - app (mobile-first). Sistema de diseño en DESIGN.md
 */

let map, tileLayer, routeLayer, weatherLayer, warningsLayer;
let nearbyLayer, fuelLayer;
let gpsMarker, originMarker, destMarker;
let routeLines = [];
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
let etaTimer = null;
let inRouteMode = false;
let fuelType = 'gasolina';
let fuelData = [];
let lastNearbyLat = null, lastNearbyLon = null;
let toastTimer = null;
let errorTimer = null;

const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

// Teselas de CARTO (basadas en OSM). Los servidores de tile.openstreetmap.org
// bloquean apps que no cumplen su política de uso.
const TILES = {
    light: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    dark:  'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
};
const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>';

// Tipo de aviso → icono (Phosphor)
const WARNING_ICONS = {
    policia: 'police-car',
    accidente: 'warning-octagon',
    peligro: 'warning',
    obras: 'traffic-cone',
    trafico: 'traffic-signal',
    vehiculo_parado: 'car',
    radar: 'camera',
    nieve: 'snowflake',
    viento: 'wind',
    inundacion: 'waves',
    animales: 'paw-print',
    corte: 'prohibit',
    desprendimiento: 'mountains',
    peaton: 'person-simple-walk',
    bicicleta: 'bicycle',
    cerrada: 'lock'
};

// ============================================
// HELPERS
// ============================================
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function icon(name, cls = '') {
    return `<svg class="icon ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
}

function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function sevClass(severity) {
    const s = String(severity || '').toLowerCase();
    if (s === 'danger' || s === 'high') return 'sev-danger';
    if (s === 'warning' || s === 'medium') return 'sev-warning';
    if (s === 'good') return 'sev-good';
    return 'sev-caution';
}

function warningIcon(type) {
    return WARNING_ICONS[type] || 'warning-circle';
}

function weatherIcon(code) {
    const c = Number(code);
    if (c === 0) return 'sun';
    if (c <= 2) return 'cloud-sun';
    if (c === 3) return 'cloud';
    if (c <= 48) return 'cloud-fog';
    if (c <= 67) return 'cloud-rain';
    if (c <= 77) return 'cloud-snow';
    if (c <= 82) return 'cloud-rain';
    if (c <= 86) return 'cloud-snow';
    return 'cloud-lightning';
}

/** Clase de placa según la red a la que pertenece la carretera */
function plateClass(ref) {
    const r = String(ref || '').toUpperCase().replace(/\s+/g, '');
    if (/^E-?\d/.test(r)) return 'plate-green';
    if (/^(AP|A|R)-?\d/.test(r) || /^M-?(30|40|45|50)$/.test(r)) return 'plate-blue';
    if (/^N-?[\dIVX]/.test(r)) return 'plate-red';
    if (/^[A-Z]{1,3}-?\d/.test(r)) return 'plate-orange';
    return 'plate-plain';
}

function roadPlate(ref) {
    const first = String(ref || '').split(/[;,]/)[0].trim();
    if (!first) return '';
    return `<span class="road-plate ${plateClass(first)}">${escapeHtml(first)}</span>`;
}

function formatTemp(t) {
    return t === null || t === undefined ? '--' : Math.round(t);
}

function formatDuration(min) {
    const m = Math.round(min);
    if (m < 60) return `${m} min`;
    return `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min`;
}

function setPressed(buttons, active) {
    buttons.forEach(b => b.setAttribute('aria-pressed', b === active ? 'true' : 'false'));
}

function stagger(container) {
    [...container.children].forEach((el, i) => el.style.setProperty('--i', Math.min(i, 12)));
    container.classList.remove('stagger');
    void container.offsetWidth;
    container.classList.add('stagger');
}

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    initEvents();
    initSheet();
    initReportModal();
    initTabs();
    startGPSTracking();
});

function initMap() {
    map = L.map('map', {
        center: [40.0, -3.7], zoom: 6,
        zoomControl: false, attributionControl: true
    });
    L.control.zoom({ position: 'bottomright', zoomInTitle: 'Acercar', zoomOutTitle: 'Alejar' }).addTo(map);

    tileLayer = L.tileLayer(darkQuery.matches ? TILES.dark : TILES.light, {
        attribution: TILE_ATTRIBUTION,
        subdomains: 'abcd',
        maxZoom: 20
    }).addTo(map);

    darkQuery.addEventListener('change', e => {
        tileLayer.setUrl(e.matches ? TILES.dark : TILES.light);
        const color = cssVar('--accent');
        routeLines.forEach(line => line.setStyle({ color }));
    });

    routeLayer    = L.layerGroup().addTo(map);
    weatherLayer  = L.layerGroup().addTo(map);
    warningsLayer = L.layerGroup().addTo(map);
    nearbyLayer   = L.layerGroup().addTo(map);
    fuelLayer     = L.layerGroup().addTo(map);

    // Arrastrar el mapa desactiva el seguimiento
    map.on('dragstart', () => {
        if (followMode) {
            followMode = false;
            document.getElementById('centerBtn').setAttribute('aria-pressed', 'false');
        }
    });

    // Votación en popups de avisos de la comunidad
    map.on('popupopen', e => {
        const el = e.popup.getElement();
        if (!el) return;
        el.querySelectorAll('[data-vote]').forEach(btn => {
            btn.addEventListener('click', () => voteWarning(btn.dataset.report, btn.dataset.vote));
        });
    });

    window.addEventListener('resize', () => updateFabsPosition(inRouteMode));
}

function initEvents() {
    document.getElementById('searchBtn').addEventListener('click', searchRoute);
    document.getElementById('newSearchBtn').addEventListener('click', newSearch);

    ['origin', 'destination'].forEach(id => {
        const el = document.getElementById(id);
        el.addEventListener('keydown', e => {
            if (e.key === 'Enter') searchRoute();
            if (e.key === 'Escape') closeSuggestions();
        });
        el.addEventListener('input', () => {
            delete el.dataset.lat;
            delete el.dataset.lon;
            delete el.dataset.hasHouse;
            el.closest('.search-row')?.classList.remove('is-set');
            autocomplete(id);
        });
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.search-row')) closeSuggestions();
    });

    document.getElementById('myLocationBtn').addEventListener('click', () => {
        const input = document.getElementById('origin');
        if (currentLat && currentLon) {
            input.value = 'Mi ubicación';
            input.dataset.lat = currentLat;
            input.dataset.lon = currentLon;
            input.dataset.hasHouse = '1';
            input.closest('.search-row')?.classList.add('is-set');
            closeSuggestions();
        } else {
            showError('Aún no tenemos tu ubicación. Permite el acceso a la ubicación o escribe una dirección.', true);
        }
    });

    const prefButtons = document.querySelectorAll('[data-pref]');
    prefButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            setPressed(prefButtons, btn);
            routePreference = btn.dataset.pref;
        });
    });

    document.getElementById('centerBtn').addEventListener('click', () => {
        followMode = true;
        document.getElementById('centerBtn').setAttribute('aria-pressed', 'true');
        if (currentLat && currentLon) {
            map.setView([currentLat, currentLon], 15, { animate: true });
        } else {
            showToast('Esperando señal de ubicación', 'gps-fix');
        }
    });

    document.getElementById('exitRoute').addEventListener('click', exitRouteMode);

    document.getElementById('fuelBtn').addEventListener('click', () => {
        const btn = document.getElementById('fuelBtn');
        const active = btn.getAttribute('aria-pressed') !== 'true';
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        btn.setAttribute('aria-label', active ? 'Ocultar gasolineras' : 'Mostrar gasolineras');
        document.getElementById('fuelPrefs').classList.toggle('hidden', !active);
        if (active) {
            fuelLayer.addTo(map);
            if (fuelData.length) drawFuelMarkers(fuelData);
            else if (currentLat && currentLon) loadNearby(currentLat, currentLon);
            else showToast('Las gasolineras aparecerán cuando tengamos tu ubicación', 'gas-pump');
        } else {
            fuelLayer.remove();
        }
    });

    const fuelButtons = document.querySelectorAll('[data-fuel]');
    fuelButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            setPressed(fuelButtons, btn);
            fuelType = btn.dataset.fuel;
            if (fuelData.length) drawFuelMarkers(fuelData);
        });
    });
}

function isFuelActive() {
    return document.getElementById('fuelBtn').getAttribute('aria-pressed') === 'true';
}

function initSheet() {
    const sheet = document.getElementById('bottomSheet');
    const handle = document.getElementById('sheetHandle');
    handle.addEventListener('click', () => {
        const collapsed = sheet.classList.toggle('is-collapsed');
        handle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        handle.setAttribute('aria-label', collapsed ? 'Desplegar panel' : 'Plegar panel');
    });
}

function collapseSheet() {
    const sheet = document.getElementById('bottomSheet');
    const handle = document.getElementById('sheetHandle');
    sheet.classList.add('is-collapsed');
    handle.setAttribute('aria-expanded', 'false');
    handle.setAttribute('aria-label', 'Desplegar panel');
}

function expandSheet() {
    const sheet = document.getElementById('bottomSheet');
    const handle = document.getElementById('sheetHandle');
    sheet.classList.remove('is-collapsed');
    handle.setAttribute('aria-expanded', 'true');
    handle.setAttribute('aria-label', 'Plegar panel');
}

function initTabs() {
    const tabs = [...document.querySelectorAll('.tab')];
    const activate = tab => {
        tabs.forEach(t => {
            const on = t === tab;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            document.getElementById(t.dataset.tab).classList.toggle('active', on);
        });
    };
    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', e => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            const next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            activate(next);
            next.focus();
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
    document.getElementById('centerBtn').setAttribute('aria-pressed', 'true');
    document.getElementById('speedometer').classList.remove('hidden');

    // Ubicación rápida (WiFi/IP) para centrar el mapa al instante
    navigator.geolocation.getCurrentPosition(
        pos => {
            if (firstFix) {
                const { latitude, longitude } = pos.coords;
                currentLat = latitude;
                currentLon = longitude;
                firstFix = false;
                updateGpsMarker(latitude, longitude);
                map.flyTo([latitude, longitude], 15, { duration: 1.2 });
                loadNearby(latitude, longitude);
            }
        },
        () => {},
        { enableHighAccuracy: false, timeout: 5000, maximumAge: 30000 }
    );

    // GPS de alta precisión para seguimiento continuo
    watchId = navigator.geolocation.watchPosition(
        pos => {
            const { latitude, longitude, speed } = pos.coords;
            currentLat = latitude;
            currentLon = longitude;

            updateGpsMarker(latitude, longitude);
            updateSpeedometer(speed ? Math.round(speed * 3.6) : 0);

            if (routeSteps.length) updateNavSign(latitude, longitude);

            if (firstFix) {
                firstFix = false;
                map.flyTo([latitude, longitude], 15, { duration: 1.5 });
                loadNearby(latitude, longitude);
            } else if (followMode) {
                map.setView([latitude, longitude], map.getZoom(), { animate: true, duration: 0.5 });
            }

            // Recargar avisos y gasolineras al moverse más de 3 km
            if (!firstFix && lastNearbyLat !== null) {
                const moved = haversineKm(lastNearbyLat, lastNearbyLon, latitude, longitude);
                if (moved > 3) loadNearby(latitude, longitude);
            }
        },
        err => {
            console.warn('GPS:', err.message);
            if (err.code === err.PERMISSION_DENIED) {
                document.getElementById('speedometer').classList.add('hidden');
            }
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 2000 }
    );
}

function updateGpsMarker(lat, lon) {
    if (gpsMarker) {
        gpsMarker.setLatLng([lat, lon]);
    } else {
        const markerIcon = L.divIcon({
            className: '',
            html: '<div class="gps-dot"></div>',
            iconSize: [20, 20], iconAnchor: [10, 10]
        });
        gpsMarker = L.marker([lat, lon], { icon: markerIcon, zIndexOffset: 1000, keyboard: false }).addTo(map);
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
// CERCANOS (avisos + gasolineras en 15 km)
// ============================================
async function loadNearby(lat, lon) {
    lastNearbyLat = lat;
    lastNearbyLon = lon;

    const dLat = 15 / 111;
    const dLon = 15 / (111 * Math.cos(lat * Math.PI / 180));
    const south = lat - dLat, north = lat + dLat;
    const west  = lon - dLon, east  = lon + dLon;

    try {
        const wRes = await fetch(`api/warnings.php?south=${south.toFixed(4)}&west=${west.toFixed(4)}&north=${north.toFixed(4)}&east=${east.toFixed(4)}`);
        let warnings = await wRes.json();
        if (!Array.isArray(warnings)) warnings = [];
        const filtered = warnings.filter(w => w.lat && w.lon && haversineKm(lat, lon, w.lat, w.lon) <= 15);
        drawWarningMarkers(nearbyLayer, filtered);
    } catch (e) {
        console.warn('Avisos cercanos:', e);
    }

    try {
        const fRes = await fetch(`api/fuel.php?lat=${lat}&lon=${lon}&radius=15`);
        let stations = await fRes.json();
        if (!Array.isArray(stations)) stations = [];
        fuelData = stations;
        if (isFuelActive()) {
            drawFuelMarkers(stations);
            if (!stations.length) showToast('No hay gasolineras con precio en 15 km', 'gas-pump');
        }
    } catch (e) {
        console.warn('Gasolineras:', e);
    }
}

function drawWarningMarkers(layer, list) {
    layer.clearLayers();
    list.forEach(w => {
        if (!w.lat || !w.lon) return;
        const markerIcon = L.divIcon({
            className: '',
            html: `<div class="mk-warning ${sevClass(w.severity)}">${icon(warningIcon(w.type))}</div>`,
            iconSize: [34, 34], iconAnchor: [17, 17], popupAnchor: [0, -18]
        });
        L.marker([w.lat, w.lon], { icon: markerIcon, title: w.title || 'Aviso' }).addTo(layer)
            .bindPopup(buildWarningPopup(w), { maxWidth: 300 });
    });
}

function drawFuelMarkers(stations) {
    fuelLayer.clearLayers();
    const isDiesel = fuelType === 'diesel';

    const fuelTypes = [
        { key: 'gasolina', label: 'Gasolina 95', dbKey: 'gasolina_95', color: 'var(--fuel-gas)' },
        { key: 'gasolina', label: 'Gasolina 98', dbKey: 'gasolina_98', color: 'var(--fuel-gas)' },
        { key: 'diesel',   label: 'Diésel A',    dbKey: 'diesel',      color: 'var(--fuel-diesel)' },
    ];

    stations.forEach(s => {
        const price = getDisplayPrice(s.prices, fuelType);
        const markerIcon = L.divIcon({
            className: '',
            html: `<div class="mk-fuel${isDiesel ? ' is-diesel' : ''}">${escapeHtml(price)}</div>`,
            iconSize: [64, 26], iconAnchor: [32, 13], popupAnchor: [0, -14]
        });

        const rows = fuelTypes
            .filter(ft => s.prices[ft.dbKey] != null)
            .map(ft => `<div class="fp-row${ft.key === fuelType ? ' fp-selected' : ''}">
                    <span class="fp-dot" style="background:${ft.color}"></span>
                    <span class="fp-label">${ft.label}</span>
                    <span class="fp-value">${Number(s.prices[ft.dbKey]).toFixed(3)}</span>
                    <span class="fp-unit">€/L</span>
                </div>`);

        const popup = `
            <div class="fuel-popup">
                <div class="fp-header">
                    <span class="fp-name">${escapeHtml(s.name)}</span>
                    <span class="fp-badge">${escapeHtml(s.distance)} km</span>
                </div>
                <div class="fp-prices">${rows.join('')}</div>
                <div>
                    <div class="fp-addr">${escapeHtml(s.address)}</div>
                    ${s.schedule ? `<div class="fp-sched">${icon('clock', 'icon-sm')}<span>${escapeHtml(s.schedule)}</span></div>` : ''}
                </div>
            </div>`;

        L.marker([s.lat, s.lon], { icon: markerIcon, title: s.name || 'Gasolinera' })
            .addTo(fuelLayer)
            .bindPopup(popup, { maxWidth: 300 });
    });

    if (!isFuelActive()) fuelLayer.remove();
}

function getCheapestPrice(prices) {
    const vals = [prices.gasolina_95, prices.gasolina_98, prices.diesel].filter(v => v !== null && v !== undefined);
    if (!vals.length) return '--';
    return Math.min(...vals).toFixed(3);
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
// CARTEL DE ORIENTACIÓN
// ============================================
function updateNavSign(lat, lon) {
    if (!routeSteps.length) return;

    // Paso más cercano
    let closestIdx = 0;
    let minDist = Infinity;
    routeSteps.forEach((step, i) => {
        const [sLon, sLat] = step.maneuver.location;
        const d = haversineKm(lat, lon, sLat, sLon);
        if (d < minDist) { minDist = d; closestIdx = i; }
    });

    // Mostrar el paso siguiente
    const nextIdx = Math.min(closestIdx + 1, routeSteps.length - 1);
    const step = routeSteps[nextIdx];
    const [sLon, sLat] = step.maneuver.location;
    const distKm = haversineKm(lat, lon, sLat, sLon);

    const sign = document.getElementById('navSign');
    const inner = sign.querySelector('.nav-sign-inner');
    sign.classList.remove('hidden');

    inner.className = 'nav-sign-inner ' + getSignClass(step.ref, step.maneuver.type);
    sign.querySelector('.nav-arrow').innerHTML = icon(getManeuverIcon(step.maneuver.type, step.maneuver.modifier));

    const roadEl = sign.querySelector('.nav-road');
    if (step.ref) {
        roadEl.innerHTML = roadPlate(step.ref);
    } else if (step.name) {
        roadEl.innerHTML = `<span class="road-plate plate-plain">${escapeHtml(step.name)}</span>`;
    } else {
        roadEl.innerHTML = '';
    }

    sign.querySelector('.nav-instruction').textContent = getManeuverText(step.maneuver.type, step.maneuver.modifier);

    if (distKm < 1) {
        sign.querySelector('.nav-dist-val').textContent = Math.round(distKm * 1000);
        sign.querySelector('.nav-dist-unit').textContent = 'm';
    } else {
        sign.querySelector('.nav-dist-val').textContent = distKm.toFixed(1).replace('.', ',');
        sign.querySelector('.nav-dist-unit').textContent = 'km';
    }
}

/** Cartel azul en autovías y autopistas, blanco en carreteras convencionales */
function getSignClass(ref, type) {
    if (type === 'arrive') return 'sign-arrive';
    const cls = plateClass(String(ref || '').split(/[;,]/)[0]);
    if (cls === 'plate-blue' || cls === 'plate-green') return 'sign-blue';
    return 'sign-white';
}

function getManeuverIcon(type, mod) {
    if (type === 'arrive') return 'flag-checkered';
    if (type === 'depart') return 'navigation-arrow';
    if (type === 'roundabout' || type === 'rotary' || type === 'roundabout turn') return 'arrow-clockwise';
    if (type === 'merge') return 'arrows-merge';
    if (type === 'fork') return 'arrows-split';
    if (type === 'uturn' || mod === 'uturn') return 'arrow-u-up-left';
    if (mod === 'left' || mod === 'sharp-left') return 'arrow-bend-up-left';
    if (mod === 'right' || mod === 'sharp-right') return 'arrow-bend-up-right';
    if (mod === 'slight-left') return 'arrow-up-left';
    if (mod === 'slight-right') return 'arrow-up-right';
    return 'arrow-up';
}

function getManeuverText(type, mod) {
    if (type === 'arrive') return 'Destino';
    if (type === 'depart') return 'Salida';
    if (type === 'roundabout' || type === 'rotary') return 'Rotonda';
    if (type === 'merge') return 'Incorpórese';
    if (type === 'uturn' || mod === 'uturn') return 'Cambie de sentido';
    if (type === 'fork') {
        return mod?.includes('left') ? 'Bifurcación a la izquierda' : 'Bifurcación a la derecha';
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
// AUTOCOMPLETADO
// ============================================
function sugIcon(type, cls) {
    if (type === 'house') return 'map-pin';
    if (type === 'street' || type === 'highway' || cls === 'highway') return 'path';
    if (type === 'railway' || cls === 'railway') return 'navigation-arrow';
    return 'map-pin';
}

function closeSuggestions() {
    document.querySelectorAll('.suggestions').forEach(s => s.classList.remove('active'));
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
            if (!Array.isArray(data) || !data.length) { box.classList.remove('active'); return; }

            box.innerHTML = data.map((r, i) => {
                const parts = (r.display_name || '').split(',');
                const main = parts.slice(0, 2).join(',').trim();
                const detail = parts.slice(2, 4).join(',').trim();
                return `<button type="button" class="suggestion-item" role="option" data-i="${i}">
                    <span class="sug-icon">${icon(sugIcon(r.type, r.class))}</span>
                    <span class="sug-body">
                        <span class="sug-main">${escapeHtml(main)}</span>
                        ${detail ? `<span class="sug-detail">${escapeHtml(detail)}</span>` : ''}
                    </span>
                </button>`;
            }).join('');

            box.classList.add('active');

            box.querySelectorAll('.suggestion-item').forEach(el => {
                el.addEventListener('click', () => {
                    const r = data[parseInt(el.dataset.i, 10)];
                    // Se mantiene lo que escribió el usuario (nº de casa)
                    input.dataset.lat = r.lat;
                    input.dataset.lon = r.lon;
                    input.dataset.hasHouse = (r.housenumber || r.type === 'house') ? '1' : '0';
                    box.classList.remove('active');
                    input.closest('.search-row')?.classList.add('is-set');
                    input.focus();
                });
            });
        } catch (e) {
            box.classList.remove('active');
        }
    }, 350);
}

// ============================================
// BUSCAR RUTA
// ============================================
async function searchRoute() {
    closeSuggestions();
    hideError();

    const oInput = document.getElementById('origin');
    const dInput = document.getElementById('destination');
    let oLat = oInput.dataset.lat, oLon = oInput.dataset.lon;
    let dLat = dInput.dataset.lat, dLon = dInput.dataset.lon;
    let approxMsg = '';

    if (!oInput.value.trim() && currentLat && currentLon) {
        oLat = currentLat; oLon = currentLon;
    }
    if (!dInput.value.trim()) {
        showError('Escribe un destino para calcular la ruta.');
        dInput.focus();
        return;
    }

    // ¿El usuario escribió número pero la sugerencia no lo tiene?
    const oHasNumber = /\d+/.test(oInput.value.replace(/\d{5,}/, ''));
    const dHasNumber = /\d+/.test(dInput.value.replace(/\d{5,}/, ''));

    showLoading(true);

    try {
        if (!oLat || !oLon) {
            const r = await geocode(oInput.value);
            if (!r) { showError('No encontramos el origen. Prueba con otra dirección o usa tu ubicación.'); return; }
            oLat = r.lat; oLon = r.lon;
            if (r.approx) approxMsg = 'Origen aproximado: el número no está en el mapa.';
        } else if (oHasNumber && oInput.dataset.hasHouse === '0') {
            approxMsg = 'Origen aproximado: el número no está en el mapa.';
        }

        if (!dLat || !dLon) {
            const r = await geocode(dInput.value);
            if (!r) { showError('No encontramos el destino. Prueba con otra dirección.'); return; }
            dLat = r.lat; dLon = r.lon;
            if (r.approx) approxMsg += (approxMsg ? ' ' : '') + 'Destino aproximado: el número no está en el mapa.';
        } else if (dHasNumber && dInput.dataset.hasHouse === '0') {
            approxMsg += (approxMsg ? ' ' : '') + 'Destino aproximado: el número no está en el mapa.';
        }

        const pref = routePreference === 'shortest' ? '&shortest=true' : '';
        const routeRes = await fetch(`api/route.php?olat=${oLat}&olon=${oLon}&dlat=${dLat}&dlon=${dLon}${pref}`);
        const route = await routeRes.json();
        if (route.error) { showError(route.error); return; }

        const pts = route.samplePoints;
        const cParam = pts.map(p => `${p.lat},${p.lon}`).join(';');
        const wRes = await fetch(`api/weather.php?coords=${encodeURIComponent(cParam)}`);
        let weather = await wRes.json();
        if (!Array.isArray(weather)) weather = [];

        const b = route.bounds;
        const wnRes = await fetch(`api/warnings.php?south=${b.south}&west=${b.west}&north=${b.north}&east=${b.east}`);
        let warnings = await wnRes.json();
        if (!Array.isArray(warnings)) warnings = [];

        // Solo avisos a menos de 3 km de la ruta
        warnings = filterToRoute(warnings, route.geometry.coordinates, 3);

        drawRoute(route, oLat, oLon, dLat, dLon);
        drawWeatherMarkers(pts, weather);
        drawWarningMarkers(warningsLayer, warnings);
        fillResults(route, weather, warnings);

        routeSteps = route.steps || [];
        enterRouteMode(parseFloat(oLat), parseFloat(oLon), route, weather);

        if (approxMsg) showToast(approxMsg, 'warning-circle');
    } catch (e) {
        console.error(e);
        showError('No se pudo calcular la ruta. Comprueba la conexión y vuelve a intentarlo.');
    } finally {
        showLoading(false);
    }
}

async function geocode(q) {
    if (!q.trim()) return null;
    try {
        const r = await fetch(`api/geocode.php?q=${encodeURIComponent(q)}`);
        const d = await r.json();
        if (Array.isArray(d) && d.length) {
            const result = d[0];
            const hasNumber = /\d+/.test(q.replace(/\d{5,}/, '')); // se ignora el código postal
            const isApprox = hasNumber && result.type !== 'house';
            return { lat: result.lat, lon: result.lon, approx: isApprox };
        }
    } catch (e) {}
    return null;
}

/**
 * Filtra avisos: solo los que están a <= maxKm de algún punto de la ruta.
 */
function filterToRoute(warnings, routeCoords, maxKm) {
    if (!routeCoords || !routeCoords.length) return warnings;

    const step = Math.max(1, Math.floor(routeCoords.length / 200));
    const sampled = [];
    for (let i = 0; i < routeCoords.length; i += step) sampled.push(routeCoords[i]);

    const maxDeg = maxKm / 111;

    return warnings.filter(w => {
        if (!w.lat || !w.lon) return false;
        for (let i = 0; i < sampled.length; i++) {
            const dLat = w.lat - sampled[i][1];
            const dLon = w.lon - sampled[i][0];
            const dist = Math.sqrt(dLat * dLat + (dLon * Math.cos(w.lat * Math.PI / 180)) ** 2);
            if (dist <= maxDeg) return true;
        }
        return false;
    });
}

// ============================================
// MODO RUTA
// ============================================
function enterRouteMode(oLat, oLon, routeData, weatherData) {
    inRouteMode = true;
    const myLat = currentLat || oLat;
    const myLon = currentLon || oLon;
    map.setView([myLat, myLon], 15, { animate: true });

    followMode = true;
    document.getElementById('centerBtn').setAttribute('aria-pressed', 'true');

    // Los avisos de la ruta sustituyen a los cercanos
    nearbyLayer.remove();

    document.getElementById('topBar').classList.remove('hidden');

    routeDurationMin = routeData.duration;
    routeStartTime = Date.now();
    updateETA();
    if (etaTimer) clearInterval(etaTimer);
    etaTimer = setInterval(updateETA, 30000);

    document.getElementById('tbDetail').textContent = `${String(routeData.distance).replace('.', ',')} km, ${formatDuration(routeData.duration)}`;

    if (weatherData.length) {
        const main = weatherData[Math.floor(weatherData.length / 2)];
        document.getElementById('tbWeather').innerHTML =
            `<span class="tb-weather-icon ${sevClass(main.severity)}">${icon(weatherIcon(main.weather_code))}</span>
             <span>${formatTemp(main.temperature)}°</span>`;
    }

    document.getElementById('searchPanel').classList.add('hidden');
    const results = document.getElementById('resultsPanel');
    results.classList.remove('hidden');
    results.scrollTop = 0;
    results.classList.remove('is-entering');
    void results.offsetWidth;
    results.classList.add('is-entering');
    // En móvil el panel se pliega para dejar ver el mapa; queda a la vista el resumen
    const sheet = document.getElementById('bottomSheet');
    sheet.classList.add('in-route');
    if (window.innerWidth < 900) collapseSheet();

    const sign = document.getElementById('navSign');
    if (currentLat && currentLon) updateNavSign(currentLat, currentLon);
    else updateNavSign(oLat, oLon);
    sign.classList.remove('is-entering');
    void sign.offsetWidth;
    sign.classList.add('is-entering');

    updateFabsPosition(true);
}

function exitRouteMode() {
    inRouteMode = false;
    document.getElementById('bottomSheet').classList.remove('in-route');
    expandSheet();
    document.getElementById('topBar').classList.add('hidden');
    document.getElementById('navSign').classList.add('hidden');
    document.getElementById('resultsPanel').classList.add('hidden');
    const search = document.getElementById('searchPanel');
    search.classList.remove('hidden');
    search.classList.remove('is-entering');
    void search.offsetWidth;
    search.classList.add('is-entering');

    routeLayer.clearLayers();
    weatherLayer.clearLayers();
    warningsLayer.clearLayers();
    routeLines = [];
    routeSteps = [];
    routeStartTime = null;
    if (etaTimer) { clearInterval(etaTimer); etaTimer = null; }

    nearbyLayer.addTo(map);
    if (isFuelActive()) fuelLayer.addTo(map);

    updateFabsPosition(false);
    if (currentLat && currentLon) map.setView([currentLat, currentLon], 14);
    else map.setView([40.0, -3.7], 6);
}

function newSearch() {
    exitRouteMode();
    document.getElementById('destination').focus();
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

/** Baja los botones flotantes y el velocímetro por debajo de la barra y el cartel */
function updateFabsPosition(inRoute) {
    let offset = 0;
    if (inRoute) {
        const fabsRect = document.querySelector('.map-fabs').getBoundingClientRect();
        const fabsLeft = fabsRect.left || (window.innerWidth - 64);
        ['topBar', 'navSign'].forEach(id => {
            const el = document.getElementById(id);
            if (el.classList.contains('hidden')) return;
            const r = el.getBoundingClientRect();
            const overlaps = r.right > fabsLeft - 72;
            if (overlaps) offset = Math.max(offset, r.bottom - 4);
        });
    }
    document.documentElement.style.setProperty('--top-offset', `${Math.round(offset)}px`);
}

// ============================================
// DIBUJO
// ============================================
function drawRoute(data, oLat, oLon, dLat, dLon) {
    routeLayer.clearLayers();
    const coords = data.geometry.coordinates.map(c => [c[1], c[0]]);
    const color = cssVar('--accent');

    routeLines = [
        L.polyline(coords, { color, weight: 14, opacity: 0.18, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(routeLayer),
        L.polyline(coords, { color, weight: 6, opacity: 0.95, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(routeLayer)
    ];

    originMarker = L.marker([parseFloat(oLat), parseFloat(oLon)], {
        title: 'Origen',
        icon: L.divIcon({ className: '', html: `<div class="mk-endpoint mk-origin">${icon('navigation-arrow', 'icon-sm')}</div>`, iconSize: [34, 34], iconAnchor: [17, 17], popupAnchor: [0, -18] })
    }).addTo(routeLayer).bindPopup('<div class="popup-title">Origen</div>');

    destMarker = L.marker([parseFloat(dLat), parseFloat(dLon)], {
        title: 'Destino',
        icon: L.divIcon({ className: '', html: `<div class="mk-endpoint mk-dest">${icon('flag-checkered', 'icon-sm')}</div>`, iconSize: [34, 34], iconAnchor: [17, 17], popupAnchor: [0, -18] })
    }).addTo(routeLayer).bindPopup('<div class="popup-title">Destino</div>');
}

function drawWeatherMarkers(pts, w) {
    weatherLayer.clearLayers();
    pts.forEach((p, i) => {
        const d = w[i]; if (!d) return;
        const sev = sevClass(d.severity);
        const markerIcon = L.divIcon({
            className: '',
            html: `<div class="mk-weather ${sev}">${icon(weatherIcon(d.weather_code))}</div>`,
            iconSize: [34, 34], iconAnchor: [17, 17], popupAnchor: [0, -18]
        });
        L.marker([p.lat, p.lon], { icon: markerIcon, title: d.condition }).addTo(weatherLayer)
            .bindPopup(`<div class="popup-title ${sev}">${icon(weatherIcon(d.weather_code))}${escapeHtml(d.condition)}</div>
                <div class="popup-temp">${formatTemp(d.temperature)}°C</div>
                <div class="popup-detail">Sensación ${formatTemp(d.feels_like)}°, viento ${escapeHtml(d.wind_speed)} km/h</div>
                <div class="popup-source">${escapeHtml(pointLabel(p, i))}</div>`);
    });
}

function pointLabel(p, i) {
    if (p.label === 'Inicio') return 'Salida';
    if (p.label === 'Destino') return 'Destino';
    if (p.km !== undefined) return `Km ${Math.round(p.km)}`;
    return `Punto ${i + 1}`;
}

function buildWarningPopup(w) {
    const sev = sevClass(w.severity);
    let html = `<div class="popup-title ${sev}">${icon(warningIcon(w.type))}${escapeHtml(w.title || 'Aviso')}</div>`;
    if (w.detail) html += `<div class="popup-detail">${escapeHtml(w.detail)}</div>`;
    if (w.road) html += `<div class="popup-road">${roadPlate(w.road)}</div>`;
    if (w.source) html += `<div class="popup-source">Fuente: ${escapeHtml(w.source)}</div>`;

    // Votación solo para avisos de la comunidad
    if (w.id && String(w.id).startsWith('rpt_')) {
        const id = escapeHtml(w.id);
        const vc = w.votes_confirm || 0;
        const vd = w.votes_dismiss || 0;
        html += `<div class="warning-vote" data-vote-box="${id}">
            <span class="vote-label">¿Sigue ahí?</span>
            <button type="button" class="vote-btn" data-vote="confirm" data-report="${id}">${icon('thumbs-up', 'icon-sm')}Sí${vc > 0 ? ` <span class="num">${vc}</span>` : ''}</button>
            <button type="button" class="vote-btn" data-vote="dismiss" data-report="${id}">${icon('thumbs-down', 'icon-sm')}No${vd > 0 ? ` <span class="num">${vd}</span>` : ''}</button>
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
            document.querySelectorAll('[data-vote-box]').forEach(el => {
                if (el.dataset.voteBox !== reportId) return;
                el.innerHTML = vote === 'confirm'
                    ? `<span class="vote-thanks">${icon('check', 'icon-sm')}Gracias por confirmarlo</span>`
                    : `<span class="vote-thanks">${icon('check', 'icon-sm')}Anotado. Se retirará con más votos</span>`;
            });
        } else {
            showToast('No se pudo registrar el voto', 'warning-circle');
        }
    } catch (e) {
        console.error('Voto:', e);
        showToast('No se pudo registrar el voto', 'warning-circle');
    }
}

// ============================================
// RESULTADOS
// ============================================
function fillResults(route, weather, warnings) {
    document.getElementById('statDistance').textContent = String(route.distance).replace('.', ',');
    document.getElementById('statDuration').textContent = Math.round(route.duration);
    document.getElementById('statWarnings').textContent = warnings.length;

    const temps = weather.filter(w => w.temperature !== null).map(w => w.temperature);
    document.getElementById('statTemp').textContent = temps.length ? (temps.reduce((a, b) => a + b, 0) / temps.length).toFixed(0) : '--';

    // Resumen de condiciones
    const conds = {};
    weather.forEach(w => { if (!conds[w.condition]) conds[w.condition] = w; });
    document.getElementById('weatherStrip').innerHTML = Object.values(conds).map(w =>
        `<span class="wstrip-item ${sevClass(w.severity)}">${icon(weatherIcon(w.weather_code), 'icon-sm')}${escapeHtml(w.condition)}</span>`
    ).join('');

    // Avisos
    const wList = document.getElementById('warningsList');
    if (!warnings.length) {
        wList.innerHTML = `<div class="empty-state">
            ${icon('check')}
            <div class="empty-title">Sin avisos en la ruta</div>
            <div class="empty-desc">Si ves algo en la carretera, repórtalo con el botón de aviso del mapa.</div>
        </div>`;
    } else {
        wList.innerHTML = warnings.map(w => `
            <button type="button" class="warning-item" data-lat="${Number(w.lat)}" data-lon="${Number(w.lon)}">
                <span class="wi-badge ${sevClass(w.severity)}">${icon(warningIcon(w.type))}</span>
                <span class="wi-body">
                    <span class="wi-head"><span class="wi-title">${escapeHtml(w.title || 'Aviso')}</span>${w.road ? roadPlate(w.road) : ''}</span>
                    ${w.detail ? `<span class="wi-desc">${escapeHtml(w.detail)}</span>` : ''}
                    ${w.source ? `<span class="wi-source">${escapeHtml(w.source)}</span>` : ''}
                </span>
            </button>`).join('');
        wList.querySelectorAll('.warning-item').forEach(el => {
            el.addEventListener('click', () => focusMapAt(el, 14));
        });
        stagger(wList);
    }

    // Hitos kilométricos
    const timeline = document.getElementById('timelineScroll');
    timeline.innerHTML = route.samplePoints.map((p, i) => {
        const w = weather[i]; if (!w) return '';
        const cap = p.label === 'Inicio' ? 'Salida' : p.label === 'Destino' ? 'Meta' : `Km ${Math.round(p.km)}`;
        return `<button type="button" class="wt-point ${sevClass(w.severity)}" data-lat="${Number(p.lat)}" data-lon="${Number(p.lon)}" aria-label="${escapeHtml(pointLabel(p, i))}: ${escapeHtml(w.condition)}, ${formatTemp(w.temperature)} grados">
            <span class="wt-cap">${escapeHtml(cap)}</span>
            <span class="wt-icon">${icon(weatherIcon(w.weather_code), 'icon-lg')}</span>
            <span class="wt-temp">${formatTemp(w.temperature)}°</span>
        </button>`;
    }).join('');
    timeline.querySelectorAll('.wt-point').forEach(el => {
        el.addEventListener('click', () => focusMapAt(el, 10));
    });

    // Detalle
    const cards = document.getElementById('weatherCards');
    cards.innerHTML = route.samplePoints.map((p, i) => {
        const w = weather[i]; if (!w) return '';
        return `<button type="button" class="wcard ${sevClass(w.severity)}" data-lat="${Number(p.lat)}" data-lon="${Number(p.lon)}">
            <span class="wcard-icon">${icon(weatherIcon(w.weather_code), 'icon-lg')}</span>
            <span>
                <span class="wcard-loc">${escapeHtml(pointLabel(p, i))}</span>
                <span class="wcard-cond">${escapeHtml(w.condition)}${w.precipitation > 0 ? `, ${escapeHtml(w.precipitation)} mm` : ''}</span>
            </span>
            <span class="wcard-meta">
                <span class="wcard-temp">${formatTemp(w.temperature)}°</span>
                <span class="wcard-wind">${icon('wind', 'icon-sm')}<span class="num">${escapeHtml(w.wind_speed)}</span> km/h</span>
            </span>
        </button>`;
    }).join('');
    cards.querySelectorAll('.wcard').forEach(el => {
        el.addEventListener('click', () => focusMapAt(el, 10));
    });
}

function focusMapAt(el, zoom) {
    followMode = false;
    document.getElementById('centerBtn').setAttribute('aria-pressed', 'false');
    map.setView([parseFloat(el.dataset.lat), parseFloat(el.dataset.lon)], zoom);
}

// ============================================
// DIÁLOGO DE REPORTE
// ============================================
function initReportModal() {
    const modal = document.getElementById('reportModal');
    const submitBtn = document.getElementById('submitReport');
    const openBtn = document.getElementById('reportBtn');
    const typeButtons = document.querySelectorAll('.rpt-btn');

    const open = () => {
        modal.classList.remove('hidden');
        typeButtons[0].focus();
    };
    const close = () => {
        modal.classList.add('hidden');
        openBtn.focus();
    };

    openBtn.addEventListener('click', open);
    document.getElementById('closeReportModal').addEventListener('click', close);
    modal.querySelector('.modal-backdrop').addEventListener('click', close);
    modal.addEventListener('keydown', e => {
        if (e.key === 'Escape') close();
        if (e.key === 'Tab') {
            // Mantener el foco dentro del diálogo
            const focusables = [...modal.querySelectorAll('button:not(:disabled), input')];
            const first = focusables[0], last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    typeButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            setPressed(typeButtons, btn);
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

        submitBtn.disabled = true;
        submitBtn.classList.add('is-loading');
        try {
            const r = await fetch('api/warnings.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const d = await r.json();
            if (d.ok) {
                setPressed(typeButtons, null);
                document.getElementById('reportRoad').value = '';
                document.getElementById('reportComment').value = '';
                selectedReportType = null;
                close();
                showToast('Reporte enviado. Gracias por avisar', 'check');
                if (currentLat && currentLon && !inRouteMode) loadNearby(currentLat, currentLon);
            } else {
                submitBtn.disabled = false;
                showToast('No se pudo enviar el reporte', 'warning-circle');
            }
        } catch (e) {
            submitBtn.disabled = false;
            showToast('No se pudo enviar el reporte. Revisa la conexión', 'warning-circle');
        } finally {
            submitBtn.classList.remove('is-loading');
        }
    });
}

// ============================================
// UI
// ============================================
function showLoading(v) {
    const btn = document.getElementById('searchBtn');
    btn.disabled = v;
    btn.classList.toggle('is-loading', v);
    btn.setAttribute('aria-busy', v ? 'true' : 'false');
    btn.querySelector('.btn-label').textContent = v ? 'Calculando ruta' : 'Buscar ruta';
}

function showError(message, isNotice = false) {
    const el = document.getElementById('error');
    el.innerHTML = `${icon(isNotice ? 'warning-circle' : 'warning')}<span>${escapeHtml(message)}</span>`;
    el.classList.toggle('is-notice', isNotice);
    el.classList.remove('hidden');
    expandSheet();
    if (errorTimer) clearTimeout(errorTimer);
    errorTimer = setTimeout(() => el.classList.add('hidden'), 6000);
}

function hideError() {
    document.getElementById('error').classList.add('hidden');
}

function showToast(message, iconName = 'check') {
    const el = document.getElementById('toast');
    el.innerHTML = `${icon(iconName)}<span>${escapeHtml(message)}</span>`;
    el.classList.remove('hidden');
    el.style.animation = 'none';
    void el.offsetWidth;
    el.style.animation = '';
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.add('hidden'), 3500);
}
