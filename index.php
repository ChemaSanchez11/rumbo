<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Rumbo: rutas en coche por España con el tiempo a lo largo del trayecto, avisos de tráfico y precios de gasolineras.">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="#FBFBFC" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#15191F" media="(prefers-color-scheme: dark)">
    <title>Rumbo</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">

    <link rel="preload" href="assets/fonts/overpass-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="lib/leaflet/leaflet.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include __DIR__ . '/assets/icons.svg'; ?>

    <!-- Mapa a pantalla completa -->
    <div id="map" role="region" aria-label="Mapa"></div>

    <!-- Barra superior (solo en modo ruta) -->
    <header id="topBar" class="top-bar hidden">
        <button id="exitRoute" class="icon-btn" type="button" aria-label="Salir de la ruta">
            <svg class="icon" aria-hidden="true"><use href="#i-arrow-left"/></svg>
        </button>
        <div class="top-bar-info">
            <span class="tb-eta" id="tbEta" aria-label="Hora de llegada">--:--</span>
            <span class="tb-detail" id="tbDetail">-- km, -- min</span>
        </div>
        <div class="top-bar-weather" id="tbWeather">
            <svg class="icon" aria-hidden="true"><use href="#i-cloud-sun"/></svg>
            <span>--°</span>
        </div>
    </header>

    <!-- Cartel de orientación -->
    <div id="navSign" class="nav-sign hidden" aria-live="polite">
        <div class="nav-sign-inner sign-blue">
            <div class="nav-arrow"></div>
            <div class="nav-info">
                <div class="nav-road"></div>
                <div class="nav-instruction">--</div>
            </div>
            <div class="nav-distance">
                <span class="nav-dist-val">--</span>
                <span class="nav-dist-unit">km</span>
            </div>
        </div>
    </div>

    <!-- Botones flotantes -->
    <div class="map-fabs">
        <button id="centerBtn" class="fab" type="button" aria-pressed="true" aria-label="Seguir mi ubicación">
            <svg class="icon icon-lg" aria-hidden="true"><use href="#i-gps-fix"/></svg>
        </button>
        <button id="fuelBtn" class="fab" type="button" aria-pressed="false" aria-label="Mostrar gasolineras">
            <svg class="icon icon-lg" aria-hidden="true"><use href="#i-gas-pump"/></svg>
        </button>
        <button id="reportBtn" class="fab fab-primary" type="button" aria-label="Reportar incidencia">
            <svg class="icon icon-lg" aria-hidden="true"><use href="#i-warning"/></svg>
        </button>
    </div>

    <!-- Velocímetro -->
    <div id="speedometer" class="speedometer hidden" aria-hidden="true">
        <span class="speed-value" id="speedValue">0</span>
        <span class="speed-unit">km/h</span>
    </div>

    <!-- Panel inferior -->
    <main id="bottomSheet" class="bottom-sheet">
        <button class="sheet-handle" id="sheetHandle" type="button" aria-expanded="true" aria-controls="bottomSheet" aria-label="Plegar panel">
            <span class="handle-bar"></span>
        </button>

        <!-- Búsqueda -->
        <section id="searchPanel" class="sheet-section" aria-labelledby="appTitle">
            <div class="sheet-header">
                <img class="app-mark" src="favicon.svg" alt="" width="32" height="32">
                <h1 class="app-title" id="appTitle">Rumbo</h1>
            </div>

            <div class="search-inputs">
                <div class="search-row">
                    <label class="field-label" for="origin">Origen</label>
                    <div class="input-wrap">
                        <svg class="icon" aria-hidden="true"><use href="#i-navigation-arrow"/></svg>
                        <input type="text" id="origin" class="search-input has-action" placeholder="Tu ubicación o una dirección" autocomplete="off" aria-autocomplete="list" aria-controls="origin-suggestions">
                        <button id="myLocationBtn" class="input-action-btn" type="button" aria-label="Usar mi ubicación">
                            <svg class="icon" aria-hidden="true"><use href="#i-crosshair"/></svg>
                        </button>
                    </div>
                    <div id="origin-suggestions" class="suggestions" role="listbox" aria-label="Sugerencias de origen"></div>
                </div>
                <div class="search-row">
                    <label class="field-label" for="destination">Destino</label>
                    <div class="input-wrap">
                        <svg class="icon" aria-hidden="true"><use href="#i-map-pin"/></svg>
                        <input type="text" id="destination" class="search-input" placeholder="Ciudad, calle o lugar" autocomplete="off" aria-autocomplete="list" aria-controls="destination-suggestions">
                    </div>
                    <div id="destination-suggestions" class="suggestions" role="listbox" aria-label="Sugerencias de destino"></div>
                </div>
            </div>

            <div class="pref-group">
                <span class="field-label" id="prefLabel">Tipo de ruta</span>
                <div class="segmented" role="group" aria-labelledby="prefLabel">
                    <button class="segment" type="button" data-pref="fastest" aria-pressed="true">
                        <svg class="icon icon-sm" aria-hidden="true"><use href="#i-lightning"/></svg>
                        Autovía
                    </button>
                    <button class="segment" type="button" data-pref="shortest" aria-pressed="false">
                        <svg class="icon icon-sm" aria-hidden="true"><use href="#i-path"/></svg>
                        Carretera
                    </button>
                </div>
            </div>

            <div id="fuelPrefs" class="pref-group hidden">
                <span class="field-label" id="fuelLabel">Combustible</span>
                <div class="segmented" role="group" aria-labelledby="fuelLabel">
                    <button class="segment" type="button" data-fuel="gasolina" aria-pressed="true">Gasolina</button>
                    <button class="segment" type="button" data-fuel="diesel" aria-pressed="false">Diésel</button>
                </div>
            </div>

            <button id="searchBtn" class="primary-btn" type="button">
                <svg class="icon" aria-hidden="true"><use href="#i-magnifying-glass"/></svg>
                <span class="btn-label">Buscar ruta</span>
            </button>

            <div id="loading" class="hidden" aria-hidden="true"></div>
            <div id="error" class="inline-error hidden" role="alert"></div>
        </section>

        <!-- Resultados -->
        <section id="resultsPanel" class="sheet-section hidden" aria-label="Resumen de la ruta">
            <div class="trip-summary">
                <div class="trip-main">
                    <span class="trip-figure"><span id="statDistance">--</span><span class="trip-unit">km</span></span>
                    <span class="trip-figure"><span id="statDuration">--</span><span class="trip-unit">min</span></span>
                </div>
                <div class="trip-meta">
                    <span class="trip-chip" title="Temperatura media">
                        <svg class="icon icon-sm" aria-hidden="true"><use href="#i-thermometer"/></svg>
                        <span><span class="num" id="statTemp">--</span>°</span>
                    </span>
                    <span class="trip-chip" title="Avisos en la ruta">
                        <svg class="icon icon-sm" aria-hidden="true"><use href="#i-warning"/></svg>
                        <span class="num" id="statWarnings">--</span>
                    </span>
                </div>
            </div>

            <div class="weather-strip" id="weatherStrip"></div>

            <div class="tab-bar" role="tablist" aria-label="Información de la ruta">
                <button class="tab active" type="button" role="tab" id="tabbtn-warnings" data-tab="tab-warnings" aria-controls="tab-warnings" aria-selected="true">
                    <svg class="icon icon-sm" aria-hidden="true"><use href="#i-warning"/></svg>
                    Avisos
                </button>
                <button class="tab" type="button" role="tab" id="tabbtn-weather" data-tab="tab-weather" aria-controls="tab-weather" aria-selected="false" tabindex="-1">
                    <svg class="icon icon-sm" aria-hidden="true"><use href="#i-cloud-sun"/></svg>
                    Clima
                </button>
                <button class="tab" type="button" role="tab" id="tabbtn-detail" data-tab="tab-detail" aria-controls="tab-detail" aria-selected="false" tabindex="-1">
                    <svg class="icon icon-sm" aria-hidden="true"><use href="#i-list-bullets"/></svg>
                    Detalle
                </button>
            </div>

            <div class="tab-content">
                <div id="tab-warnings" class="tab-pane active" role="tabpanel" aria-labelledby="tabbtn-warnings">
                    <div id="warningsList" class="warning-list"></div>
                </div>
                <div id="tab-weather" class="tab-pane" role="tabpanel" aria-labelledby="tabbtn-weather">
                    <div class="weather-timeline-scroll" id="timelineScroll"></div>
                </div>
                <div id="tab-detail" class="tab-pane" role="tabpanel" aria-labelledby="tabbtn-detail">
                    <div id="weatherCards" class="weather-rows"></div>
                </div>
            </div>

            <button id="newSearchBtn" class="secondary-btn" type="button">Nueva búsqueda</button>
        </section>
    </main>

    <!-- Diálogo de reporte -->
    <div id="reportModal" class="modal hidden">
        <div class="modal-backdrop"></div>
        <div class="modal-sheet" role="dialog" aria-modal="true" aria-labelledby="reportTitle" aria-describedby="reportSub">
            <div class="handle-bar"></div>
            <h2 class="modal-title" id="reportTitle">Reportar incidencia</h2>
            <p class="modal-sub" id="reportSub">Se publica en tu posición actual y la ven otros conductores durante 4 horas.</p>

            <div class="report-grid" id="reportTypes" role="group" aria-label="Tipo de incidencia">
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="policia"><svg class="icon" aria-hidden="true"><use href="#i-police-car"/></svg>Policía</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="accidente"><svg class="icon" aria-hidden="true"><use href="#i-warning-octagon"/></svg>Accidente</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="peligro"><svg class="icon" aria-hidden="true"><use href="#i-warning"/></svg>Peligro</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="obras"><svg class="icon" aria-hidden="true"><use href="#i-traffic-cone"/></svg>Obras</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="trafico"><svg class="icon" aria-hidden="true"><use href="#i-traffic-signal"/></svg>Atasco</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="vehiculo_parado"><svg class="icon" aria-hidden="true"><use href="#i-car"/></svg>Vehículo parado</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="radar"><svg class="icon" aria-hidden="true"><use href="#i-camera"/></svg>Radar</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="nieve"><svg class="icon" aria-hidden="true"><use href="#i-snowflake"/></svg>Nieve</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="viento"><svg class="icon" aria-hidden="true"><use href="#i-wind"/></svg>Viento</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="animales"><svg class="icon" aria-hidden="true"><use href="#i-paw-print"/></svg>Animales</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="peaton"><svg class="icon" aria-hidden="true"><use href="#i-person-simple-walk"/></svg>Peatón</button>
                <button class="rpt-btn" type="button" aria-pressed="false" data-type="inundacion"><svg class="icon" aria-hidden="true"><use href="#i-waves"/></svg>Inundación</button>
            </div>

            <div class="modal-field">
                <label class="field-label" for="reportRoad">Carretera (opcional)</label>
                <input type="text" id="reportRoad" class="modal-input" placeholder="Por ejemplo, A-42" maxlength="20" autocomplete="off">
            </div>
            <div class="modal-field">
                <label class="field-label" for="reportComment">Comentario (opcional)</label>
                <input type="text" id="reportComment" class="modal-input" placeholder="Qué está pasando" maxlength="200" autocomplete="off">
            </div>

            <button id="submitReport" class="primary-btn" type="button" disabled>Enviar reporte</button>
            <button id="closeReportModal" class="text-btn" type="button">Cancelar</button>
        </div>
    </div>

    <div id="toast" class="toast hidden" role="status" aria-live="polite"></div>

    <script src="lib/leaflet/leaflet.js"></script>
    <script src="js/app.js"></script>
</body>
</html>
