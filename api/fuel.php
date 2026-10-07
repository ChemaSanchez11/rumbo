<?php
/**
 * Proxy de gasolineras → API del Ministerio de Industria
 * Devuelve estaciones en un radio de N km con precios
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$lat = floatval($_GET['lat'] ?? 0);
$lon = floatval($_GET['lon'] ?? 0);
$radius = floatval($_GET['radius'] ?? 15);

if (!$lat || !$lon) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan coordenadas']);
    exit;
}

// Cache 6 horas (el dataset es ~4MB y se actualiza 2 veces al día)
$cacheFile = __DIR__ . '/../data/fuel_cache.json';
$cacheAge = file_exists($cacheFile) ? time() - filemtime($cacheFile) : 99999;

if ($cacheAge > 21600) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'method'  => 'GET',
            'header'  => "User-Agent: Rumbo/1.0\r\nAccept: application/json\r\n"
        ]
    ]);

    $response = @file_get_contents(FUEL_API_URL, false, $ctx);
    if ($response !== false) {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($cacheFile, $response);
    }
} else {
    $response = file_get_contents($cacheFile);
}

if (empty($response)) {
    echo json_encode([]);
    exit;
}

$data = json_decode($response, true);
$stations = $data['ListaEESSPrecio'] ?? [];

$results = [];
foreach ($stations as $s) {
    $sLat = floatval(str_replace(',', '.', $s['Latitud'] ?? '0'));
    $sLon = floatval(str_replace(',', '.', $s['Longitud (WGS84)'] ?? '0'));

    if (!$sLat || !$sLon) continue;

    $dist = haversine($lat, $lon, $sLat, $sLon) / 1000;
    if ($dist > $radius) continue;

    $p95    = $s['Precio Gasolina 95 E5'] ?? '';
    $p98    = $s['Precio Gasolina 98 E5'] ?? '';
    $pDiesel = $s['Precio Gasoleo A'] ?? '';

    if (empty($p95) && empty($p98) && empty($pDiesel)) continue;

    $results[] = [
        'name'     => $s['Rótulo'] ?? 'Gasolinera',
        'address'  => trim(($s['Dirección'] ?? '') . ', ' . ($s['Localidad'] ?? '')),
        'lat'      => $sLat,
        'lon'      => $sLon,
        'distance' => round($dist, 1),
        'schedule' => $s['Horario'] ?? '',
        'prices'   => [
            'gasolina_95' => $p95 ? round(floatval(str_replace(',', '.', $p95)), 3) : null,
            'gasolina_98' => $p98 ? round(floatval(str_replace(',', '.', $p98)), 3) : null,
            'diesel'      => $pDiesel ? round(floatval(str_replace(',', '.', $pDiesel)), 3) : null,
        ]
    ];
}

usort($results, fn($a, $b) => $a['distance'] <=> $b['distance']);
$results = array_slice($results, 0, 30);

echo json_encode($results);

function haversine($lat1, $lon1, $lat2, $lon2): float {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}