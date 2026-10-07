<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0b1120">
    <title>Rumbo</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <!-- ============ MAPA (fondo completo) ============ -->
    <div id="map"></div>

    <!-- ============ TOP BAR (solo en modo ruta) ============ -->
    <div id="topBar" class="top-bar hidden">
        <button id="exitRoute" class="top-bar-btn" title="Salir de ruta">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        </button>
        <div class="top-bar-info">
            <span class="tb-eta" id="tbEta">--:--</span>
            <span class="tb-detail" id="tbDetail">-- km · -- min</span>
        </div>
        <div class="top-bar-weather" id="tbWeather">
            <span class="tb-weather-icon">☀️</span>
            <span class="tb-weather-temp">--°</span>
        </div>
    </div>

    <!-- ============ SEÑAL DE NAVEGACIÓN ============ -->
    <div id="navSign" class="nav-sign hidden">
        <div class="nav-sign-inner">
            <div class="nav-arrow"></div>
            <div class="nav-info">
                <div class="nav-road">--</div>
                <div class="nav-instruction">--</div>
            </div>
            <div class="nav-distance">
                <span class="nav-dist-val">--</span>
                <span class="nav-dist-unit">km</span>
            </div>
        </div>
    </div>

    <!-- ============ 3 BOTONES FLOTANTES: CENTRAR + GASOLINERAS + REPORTAR ============ -->
    <div class="map-fabs">
        <button id="centerBtn" class="fab fab-blue active" title="Centrar en mi ubicación">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <circle cx="12" cy="12" r="3"/>
                <path d="M12 2v4m0 12v4M2 12h4m12 0h4"/>
            </svg>
        </button>
        <button id="fuelBtn" class="fab fab-green" title="Gasolineras">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h8a2 2 0 002-2V4a2 2 0 00-2-2z"/>
                <path d="M14 9h1a2 2 0 012 2v2a1 1 0 002 0V9l-3-3"/>
                <path d="M8 13h4"/>
            </svg>
        </button>
        <button id="reportBtn" class="fab fab-red" title="Reportar incidencia">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
        </button>
    </div>

    <!-- Velocímetro -->
    <div id="speedometer" class="speedometer hidden">
        <span class="speed-value" id="speedValue">0</span>
        <span class="speed-unit">km/h</span>
    </div>



    <!-- ============ BOTTOM SHEET ============ -->
    <div id="bottomSheet" class="bottom-sheet">
        <!-- Handle -->
        <div class="sheet-handle" id="sheetHandle">
            <div class="handle-bar"></div>
        </div>

        <!-- Panel de búsqueda (estado inicial) -->
        <div id="searchPanel" class="sheet-section">
            <div class="sheet-header">
                <h1 class="app-title">Rumbo</h1>
                <p class="app-sub">Clima · Avisos · Rutas por España</p>
            </div>

            <div class="search-inputs">
                <div class="search-row">
                    <div class="dot dot-green"></div>
                    <input type="text" id="origin" class="search-input" placeholder="Tu ubicación" autocomplete="off">
                    <button id="myLocationBtn" class="input-action-btn" title="Mi ubicación">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4"/></svg>
                    </button>
                    <div id="origin-suggestions" class="suggestions"></div>
                </div>
                <div class="search-row">
                    <div class="dot dot-red"></div>
                    <input type="text" id="destination" class="search-input" placeholder="¿A dónde vas?" autocomplete="off">
                    <div id="destination-suggestions" class="suggestions"></div>
                </div>
            </div>

            <div class="pref-row">
                <button class="pref-chip active" data-pref="fastest">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    Autovía
                </button>
                <button class="pref-chip" data-pref="shortest">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    Carretera
                </button>
            </div>

            <div id="fuelPrefs" class="pref-row fuel-prefs hidden">
                <button class="pref-chip active" data-fuel="gasolina">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4"/></svg>
                    Gasolina
                </button>
                <button class="pref-chip" data-fuel="diesel">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h8a2 2 0 002-2V4a2 2 0 00-2-2z"/><path d="M8 13h4"/></svg>
                    Diésel
                </button>
            </div>

            <button id="searchBtn" class="primary-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                Buscar Ruta
            </button>

            <!-- Loading / Error -->
            <div id="loading" class="loading-bar hidden"><div class="loading-bar-inner"></div></div>
            <div id="error" class="inline-error hidden"></div>
        </div>

        <!-- Panel de resultados (después de buscar) -->
        <div id="resultsPanel" class="sheet-section hidden">
            <!-- Stats -->
            <div class="result-stats">
                <div class="rstat">
                    <span class="rstat-val" id="statDistance">--</span>
                    <span class="rstat-label">km</span>
                </div>
                <div class="rstat">
                    <span class="rstat-val" id="statDuration">--</span>
                    <span class="rstat-label">min</span>
                </div>
                <div class="rstat">
                    <span class="rstat-val" id="statTemp">--</span>
                    <span class="rstat-label">°C</span>
                </div>
                <div class="rstat">
                    <span class="rstat-val" id="statWarnings">--</span>
                    <span class="rstat-label">avisos</span>
                </div>
            </div>

            <!-- Clima overview -->
            <div class="weather-strip" id="weatherStrip"></div>

            <!-- Tabs -->
            <div class="tab-bar">
                <button class="tab active" data-tab="tab-warnings">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Avisos
                </button>
                <button class="tab" data-tab="tab-weather">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72l1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                    Clima
                </button>
                <button class="tab" data-tab="tab-detail">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    Detalle
                </button>
            </div>

            <div class="tab-content">
                <!-- Avisos -->
                <div id="tab-warnings" class="tab-pane active">
                    <div id="warningsList"></div>
                </div>
                <!-- Clima timeline -->
                <div id="tab-weather" class="tab-pane">
                    <div class="weather-timeline-scroll" id="timelineScroll"></div>
                </div>
                <!-- Detalle -->
                <div id="tab-detail" class="tab-pane">
                    <div id="weatherCards"></div>
                </div>
            </div>

            <button id="newSearchBtn" class="secondary-btn">
                Nueva búsqueda
            </button>
        </div>
    </div>

    <!-- ============ MODAL REPORTAR ============ -->
    <div id="reportModal" class="modal hidden">
        <div class="modal-backdrop"></div>
        <div class="modal-sheet">
            <div class="handle-bar" style="margin: 0 auto 16px;"></div>
            <h3 class="modal-title">Reportar Incidencia</h3>
            <p class="modal-sub">Tu reporte ayuda a otros conductores</p>

            <div class="report-grid" id="reportTypes">
                <button class="rpt-btn" data-type="policia"><span>👮</span>Policía</button>
                <button class="rpt-btn" data-type="accidente"><span>💥</span>Accidente</button>
                <button class="rpt-btn" data-type="peligro"><span>⚠️</span>Peligro</button>
                <button class="rpt-btn" data-type="obras"><span>🚧</span>Obras</button>
                <button class="rpt-btn" data-type="trafico"><span>🔴</span>Atasco</button>
                <button class="rpt-btn" data-type="vehiculo_parado"><span>🚙</span>Veh. parado</button>
                <button class="rpt-btn" data-type="radar"><span>📸</span>Radar</button>
                <button class="rpt-btn" data-type="nieve"><span>❄️</span>Nieve</button>
                <button class="rpt-btn" data-type="viento"><span>💨</span>Viento</button>
                <button class="rpt-btn" data-type="animales"><span>🐗</span>Animales</button>
                <button class="rpt-btn" data-type="peaton"><span>🚶</span>Peatón</button>
                <button class="rpt-btn" data-type="inundacion"><span>🌊</span>Inundación</button>
            </div>

            <input type="text" id="reportRoad" class="modal-input" placeholder="Carretera (opcional, ej: A-42)">
            <input type="text" id="reportComment" class="modal-input" placeholder="Comentario (opcional)">

            <button id="submitReport" class="primary-btn" disabled>Enviar Reporte</button>

            <button id="closeReportModal" class="text-btn" style="margin-top: 8px;">Cancelar</button>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="js/app.js"></script>
</body>
</html>