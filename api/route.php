<?php
/**
 * Proxy de enrutamiento → OSRM + muestreo de puntos a lo largo de la ruta
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$olat = floatval($_GET['olat'] ?? 0);
$olon = floatval($_GET['olon'] ?? 0);
$dlat = floatval($_GET['dlat'] ?? 0);
$dlon = floatval($_GET['dlon'] ?? 0);
$shortest = isset($_GET['shortest']) && $_GET['shortest'] === 'true';

if (!$olat || !$olon || !$dlat || !$dlon) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan coordenadas de origen o destino']);
    exit;
}

// Llamar a OSRM — geometría completa en GeoJSON
// Si se pide "carreteras" (shortest), pedimos alternativas para elegir la más larga
$params = [
    'overview'    => 'full',
    'geometries'  => 'geojson',
    'steps'       => 'true',
    'annotations' => 'true'
];
if ($shortest) {
    $params['alternatives'] = '3';
}
$url = OSRM_URL . "/{$olon},{$olat};{$dlon},{$dlat}" . '?' . http_build_query($params);

$ctx = stream_context_create([
    'http' => [
        'timeout' => 12,
        'method'  => 'GET',
        'header'  => "User-Agent: Rumbo/1.0\r\n"
    ]
]);

$response = @file_get_contents($url, false, $ctx);
if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'No se pudo contactar con OSRM']);
    exit;
}

$data = json_decode($response, true);
if (!$data || ($data['code'] ?? '') !== 'Ok' || empty($data['routes'])) {
    echo json_encode(['error' => 'No se encontró una ruta válida', 'raw' => $data]);
    exit;
}

// Seleccionar ruta: para "carreteras" elegimos la alternativa más larga (más secundaria)
$routes = $data['routes'];
if ($shortest && count($routes) > 1) {
    // Ordenar por distancia descendente y coger la más larga (más pintoresca)
    usort($routes, fn($a, $b) => $b['distance'] <=> $a['distance']);
    $route = $routes[0];
} else {
    $route = $routes[0];
}
$coords = $route['geometry']['coordinates']; // [[lon, lat], ...]

// --- Muestrear puntos a lo largo de la ruta ---
$distanceM = $route['distance'];
$intervalKm = max(20, min(SAMPLE_INTERVAL_KM, $distanceM / 1000 / 4));
$intervalM = $intervalKm * 1000;

$samplePoints = [];
$accumulated = 0;
$lastSampleIdx = 0;
$samplePoints[] = [
    'lat'      => $coords[0][1],
    'lon'      => $coords[0][0],
    'km'       => 0,
    'label'    => 'Inicio'
];

for ($i = 1; $i < count($coords); $i++) {
    $d = haversine(
        $coords[$i - 1][1], $coords[$i - 1][0],
        $coords[$i][1],     $coords[$i][0]
    );
    $accumulated += $d;

    if ($accumulated >= $intervalM * (count($samplePoints))) {
        $km = round($accumulated / 1000, 1);
        $samplePoints[] = [
            'lat'   => $coords[$i][1],
            'lon'   => $coords[$i][0],
            'km'    => $km,
            'label' => "{$km} km"
        ];
    }

    if (count($samplePoints) >= MAX_SAMPLES) break;
}

// Añadir destino final
$last = end($coords);
$totalKm = round($distanceM / 1000, 1);
$samplePoints[] = [
    'lat'   => $last[1],
    'lon'   => $last[0],
    'km'    => $totalKm,
    'label' => 'Destino'
];

// --- Calcular bounding box de la ruta ---
$lats = array_column($samplePoints, 'lat');
$lons = array_column($samplePoints, 'lon');

echo json_encode([
    'distance'     => $totalKm,
    'duration'     => round($route['duration'] / 60, 1), // minutos
    'geometry'     => $route['geometry'],
    'samplePoints' => $samplePoints,
    'bounds'       => [
        'south' => min($lats) - 0.1,
        'west'  => min($lons) - 0.1,
        'north' => max($lats) + 0.1,
        'east'  => max($lons) + 0.1
    ],
    'steps'        => $route['legs'][0]['steps'] ?? []
]);

/**
 * Distancia Haversine en metros
 */
function haversine($lat1, $lon1, $lat2, $lon2): float {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}