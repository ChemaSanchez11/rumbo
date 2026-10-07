<?php
/**
 * Proxy de geocodificación → OpenCage (principal) + Google + Photon + Nominatim
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Normalizar
$query = preg_replace('/([a-zA-ZáéíóúÁÉÍÓÚñÑ])(\d)/', '$1 $2', $query);
$query = preg_replace('/^c\/?\s/i', 'Calle ', $query);
$query = preg_replace('/^av\.?\s/i', 'Avenida ', $query);
$query = preg_replace('/^pº?\s/i', 'Paseo ', $query);

// 1. OpenCage (gratis, sin tarjeta, buen soporte de números)
$results = opencageSearch($query);

// 2. Google (si tiene facturación activa)
if (empty($results)) {
    $results = googleSearch($query);
}

// 3. Photon como fallback
if (empty($results)) {
    $results = photonSearch($query);
}

// 4. Nominatim como último recurso
if (empty($results)) {
    $results = nominatimSearch($query);
}

echo json_encode($results ?: []);

// ============================================================
// OpenCage Geocoding API
// ============================================================
function opencageSearch(string $q): ?array {
    if (empty(OPENCAGE_KEY)) return null;

    $url = OPENCAGE_URL . '?' . http_build_query([
        'q'            => $q,
        'key'          => OPENCAGE_KEY,
        'countrycode'  => 'es',
        'language'     => 'es',
        'limit'        => 6,
        'no_annotations' => 1
    ]);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'method'  => 'GET',
            'header'  => "User-Agent: Rumbo/1.0\r\n"
        ]
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return null;

    $data = json_decode($response, true);
    if (!$data || ($data['status']['code'] ?? 0) !== 200 || empty($data['results'])) {
        return null;
    }

    $results = [];
    foreach ($data['results'] as $r) {
        $geo = $r['geometry'] ?? [];
        $comp = $r['components'] ?? [];
        if (empty($geo)) continue;

        $housenumber = $comp['house_number'] ?? '';
        $road = $comp['road'] ?? '';
        $city = $comp['city'] ?? $comp['town'] ?? $comp['village'] ?? '';
        $state = $comp['state'] ?? '';
        $country = $comp['country'] ?? '';

        // Determinar tipo
        $type = 'point';
        if ($housenumber && $road) $type = 'house';
        elseif ($road) $type = 'street';
        elseif ($city) $type = 'city';

        $results[] = [
            'lat'          => (string)$geo['lat'],
            'lon'          => (string)$geo['lng'],
            'display_name' => $r['formatted'] ?? implode(', ', array_filter([$road . ($housenumber ? ", $housenumber" : ''), $city, $state, $country])),
            'type'         => $type,
            'class'        => 'place',
            'housenumber'  => $housenumber
        ];
    }

    return empty($results) ? null : $results;
}

// ============================================================
// Google Maps Geocoding API (si facturación activa)
// ============================================================
function googleSearch(string $q): ?array {
    if (empty(GOOGLE_API_KEY)) return null;

    $url = GOOGLE_GEOCODE_URL . '?' . http_build_query([
        'address'    => $q,
        'key'        => GOOGLE_API_KEY,
        'language'   => 'es',
        'region'     => 'es',
        'components' => 'country:ES'
    ]);

    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'method' => 'GET', 'header' => "User-Agent: Rumbo/1.0\r\n"]
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return null;

    $data = json_decode($response, true);
    if (!$data || ($data['status'] ?? '') !== 'OK' || empty($data['results'])) return null;

    $results = [];
    foreach ($data['results'] as $r) {
        $loc = $r['geometry']['location'] ?? [];
        if (empty($loc)) continue;

        $housenumber = '';
        foreach ($r['address_components'] ?? [] as $c) {
            if (in_array('street_number', $c['types'] ?? [])) {
                $housenumber = $c['long_name'] ?? '';
            }
        }

        $googleTypes = $r['types'] ?? [];
        $type = 'point';
        if (in_array('street_address', $googleTypes)) $type = $housenumber ? 'house' : 'street';
        elseif (in_array('route', $googleTypes)) $type = 'street';
        elseif (in_array('locality', $googleTypes)) $type = 'city';

        $results[] = [
            'lat'          => (string)$loc['lat'],
            'lon'          => (string)$loc['lng'],
            'display_name' => $r['formatted_address'] ?? '',
            'type'         => $type,
            'class'        => 'place',
            'housenumber'  => $housenumber
        ];
    }

    return empty($results) ? null : $results;
}

// ============================================================
// Photon (Komoot) — sin API key
// ============================================================
function photonSearch(string $q): ?array {
    $url = 'https://photon.komoot.io/api/?' . http_build_query([
        'q' => $q, 'limit' => 6, 'bbox' => '-10,35.5,4.5,44'
    ]);

    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'method' => 'GET', 'header' => "User-Agent: " . NOMINATIM_USER_AGENT . "\r\n"]
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return null;

    $response = fixEncoding($response);
    $data = json_decode($response, true);
    if (!$data || empty($data['features'])) return [];

    $results = [];
    foreach ($data['features'] as $f) {
        $props = $f['properties'] ?? [];
        $coords = $f['geometry']['coordinates'] ?? [];
        if (count($coords) < 2) continue;

        $name = $props['name'] ?? '';
        $housenumber = $props['housenumber'] ?? '';
        $street = $props['street'] ?? '';
        $city = $props['city'] ?? '';
        $state = $props['state'] ?? '';
        $country = $props['country'] ?? '';

        $streetPart = $street ?: $name;
        if ($housenumber && $street) $streetPart = $street . ', ' . $housenumber;

        $parts = array_filter([$name !== $street ? $name : '', $streetPart, $city, $state, $country]);
        $displayName = implode(', ', array_unique($parts));

        $results[] = [
            'lat'          => (string)$coords[1],
            'lon'          => (string)$coords[0],
            'display_name' => $displayName,
            'type'         => $props['type'] ?? '',
            'class'        => $props['osm_key'] ?? '',
            'housenumber'  => $housenumber
        ];
    }

    return $results;
}

// ============================================================
// Nominatim (último recurso)
// ============================================================
function nominatimSearch(string $q): array {
    $url = NOMINATIM_URL . '?' . http_build_query([
        'q' => $q, 'format' => 'json', 'countrycodes' => 'es',
        'limit' => 6, 'addressdetails' => 1, 'accept-language' => 'es'
    ]);

    $ctx = stream_context_create([
        'http' => ['header' => "User-Agent: " . NOMINATIM_USER_AGENT . "\r\n", 'timeout' => 8, 'method' => 'GET']
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return [];
    $data = json_decode($response, true);

    if (empty($data) && preg_match('/^(.+?)\s+\d+[\s,]*$/', $q, $m)) {
        $url2 = NOMINATIM_URL . '?' . http_build_query([
            'q' => trim($m[1]), 'format' => 'json', 'countrycodes' => 'es',
            'limit' => 6, 'addressdetails' => 1, 'accept-language' => 'es'
        ]);
        $r2 = @file_get_contents($url2, false, $ctx);
        if ($r2 !== false) { $d2 = json_decode($r2, true); if (!empty($d2)) $data = $d2; }
    }

    return is_array($data) ? $data : [];
}

function fixEncoding(string $str): string {
    if (mb_check_encoding($str, 'UTF-8')) return $str;
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}