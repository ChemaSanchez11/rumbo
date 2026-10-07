<?php
/**
 * Proxy de clima → Open-Meteo (sin API key)
 * Acepta múltiples coordenadas separadas por ;
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$coordsRaw = $_GET['coords'] ?? '';
if (empty($coordsRaw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan coordenadas']);
    exit;
}

// Parsear "lat1,lon1;lat2,lon2;..."
$pairs = explode(';', $coordsRaw);
$lats = [];
$lons = [];

foreach ($pairs as $pair) {
    $parts = explode(',', trim($pair));
    if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
        $lats[] = $parts[0];
        $lons[] = $parts[1];
    }
}

if (empty($lats)) {
    http_response_code(400);
    echo json_encode(['error' => 'Coordenadas inválidas']);
    exit;
}

// Open-Meteo soporta coordenadas múltiples separadas por coma
$url = OPENMETEO_URL . '?' . http_build_query([
    'latitude'  => implode(',', $lats),
    'longitude' => implode(',', $lons),
    'current'   => 'temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m,wind_direction_10m,apparent_temperature,precipitation',
    'timezone'  => 'Europe/Madrid'
]);

$ctx = stream_context_create([
    'http' => [
        'timeout' => 10,
        'method'  => 'GET',
        'header'  => "User-Agent: Rumbo/1.0\r\n"
    ]
]);

$response = @file_get_contents($url, false, $ctx);
if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'No se pudo contactar con Open-Meteo']);
    exit;
}

$weatherRaw = json_decode($response, true);

// Open-Meteo devuelve un array si hay múltiples coordenadas, un objeto si hay una
if (isset($weatherRaw['current'])) {
    $weatherRaw = [$weatherRaw];
}

// Formatear resultado limpio
$results = [];
foreach ($weatherRaw as $i => $w) {
    $current = $w['current'] ?? [];
    $code = $current['weather_code'] ?? 0;

    $results[] = [
        'lat'         => $lats[$i] ?? $w['latitude'],
        'lon'         => $lons[$i] ?? $w['longitude'],
        'temperature' => $current['temperature_2m'] ?? null,
        'feels_like'  => $current['apparent_temperature'] ?? null,
        'humidity'    => $current['relative_humidity_2m'] ?? null,
        'wind_speed'  => $current['wind_speed_10m'] ?? null,
        'wind_dir'    => $current['wind_direction_10m'] ?? null,
        'precipitation' => $current['precipitation'] ?? 0,
        'weather_code'=> $code,
        'condition'   => weatherCodeToSpanish($code),
        'icon'        => weatherCodeToIcon($code),
        'severity'    => weatherSeverity($code)
    ];
}

echo json_encode($results);

// --- Funciones auxiliares ---

function weatherCodeToSpanish(int $code): string {
    return match($code) {
        0       => 'Despejado',
        1       => 'Mayormente despejado',
        2       => 'Parcialmente nublado',
        3       => 'Nublado',
        45, 48  => 'Niebla',
        51      => 'Llovizna ligera',
        53      => 'Llovizna moderada',
        55      => 'Llovizna intensa',
        56, 57  => 'Llovizna helada',
        61      => 'Lluvia ligera',
        63      => 'Lluvia moderada',
        65      => 'Lluvia intensa',
        66, 67  => 'Lluvia helada',
        71      => 'Nieve ligera',
        73      => 'Nieve moderada',
        75      => 'Nieve intensa',
        77      => 'Granos de nieve',
        80      => 'Chubascos ligeros',
        81      => 'Chubascos moderados',
        82      => 'Chubascos violentos',
        85, 86  => 'Chubascos de nieve',
        95      => 'Tormenta eléctrica',
        96, 99  => 'Tormenta con granizo',
        default => 'Condición desconocida'
    };
}

function weatherCodeToIcon(int $code): string {
    return match(true) {
        $code === 0           => '☀️',
        $code <= 3            => '⛅',
        $code <= 48           => '🌫️',
        $code <= 57           => '🌦️',
        $code <= 67           => '🌧️',
        $code <= 77           => '🌨️',
        $code <= 82           => '🌧️',
        $code <= 86           => '🌨️',
        $code <= 99           => '⛈️',
        default               => '❓'
    };
}

function weatherSeverity(int $code): string {
    return match(true) {
        $code === 0                => 'good',
        $code <= 3                 => 'good',
        $code <= 48                => 'caution',
        $code <= 55                => 'caution',
        $code <= 65                => 'warning',
        $code <= 75                => 'warning',
        $code <= 82                => 'warning',
        $code >= 95                => 'danger',
        default                    => 'caution'
    };
}