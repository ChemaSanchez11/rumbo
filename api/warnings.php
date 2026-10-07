<?php
/**
 * Avisos estilo Waze para España
 * Tipos: policía, accidente, peligro, obras, tráfico, vehículo parado, radar, clima
 *
 * GET  → devuelve avisos dentro de los bounds
 * POST → guarda un reporte de usuario
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$reportsFile = __DIR__ . '/../data/reports.json';

// --- POST: guardar reporte o votar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos']);
        exit;
    }

    // --- VOTACIÓN: ¿Sigue aquí? ---
    if (isset($input['action']) && $input['action'] === 'vote') {
        $reportId = $input['report_id'] ?? '';
        $vote = $input['vote'] ?? '';

        if (empty($reportId) || !in_array($vote, ['confirm', 'dismiss'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos']);
            exit;
        }

        $reports = [];
        if (file_exists($reportsFile)) {
            $reports = json_decode(file_get_contents($reportsFile), true) ?: [];
        }

        $found = false;
        foreach ($reports as &$r) {
            if ($r['id'] === $reportId) {
                if ($vote === 'confirm') {
                    $r['votes_confirm'] = ($r['votes_confirm'] ?? 0) + 1;
                } else {
                    $r['votes_dismiss'] = ($r['votes_dismiss'] ?? 0) + 1;
                }
                $found = true;
                break;
            }
        }
        unset($r);

        if (!$found) {
            http_response_code(404);
            echo json_encode(['error' => 'Reporte no encontrado']);
            exit;
        }

        // Eliminar reportes con 5+ votos de "ya no está"
        $reports = array_values(array_filter($reports, fn($r) => ($r['votes_dismiss'] ?? 0) < 5));

        $dir = dirname($reportsFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($reportsFile, json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        echo json_encode(['ok' => true, 'votes_confirm' => $reports[array_search($reportId, array_column($reports, 'id'))]['votes_confirm'] ?? 0]);
        exit;
    }

    // --- NUEVO REPORTE ---
    if (!isset($input['lat'], $input['lon'], $input['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos']);
        exit;
    }

    $report = [
        'id'            => uniqid('rpt_', true),
        'type'          => $input['type'],
        'lat'           => floatval($input['lat']),
        'lon'           => floatval($input['lon']),
        'comment'       => mb_substr(trim($input['comment'] ?? ''), 0, 200),
        'road'          => trim($input['road'] ?? ''),
        'time'          => date('c'),
        'votes'         => 1,
        'votes_confirm' => 0,
        'votes_dismiss' => 0,
        'source'        => 'usuario'
    ];

    $reports = [];
    if (file_exists($reportsFile)) {
        $reports = json_decode(file_get_contents($reportsFile), true) ?: [];
    }

    // Limitar a 500 reportes (eliminar los más antiguos)
    $reports[] = $report;
    if (count($reports) > 500) {
        $reports = array_slice($reports, -500);
    }

    // Crear directorio si no existe
    $dir = dirname($reportsFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    file_put_contents($reportsFile, json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['ok' => true, 'report' => $report]);
    exit;
}

// --- GET: devolver avisos ---
$south = floatval($_GET['south'] ?? 0);
$west  = floatval($_GET['west']  ?? 0);
$north = floatval($_GET['north'] ?? 0);
$east  = floatval($_GET['east']  ?? 0);

if (!$south || !$west || !$north || !$east) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan límites del área']);
    exit;
}

$warnings = [];

// 1. Intentar datos reales de la DGT
$dgtData = fetchDGTData();
if ($dgtData) {
    foreach ($dgtData as $inc) {
        $lat = floatval($inc['lat'] ?? 0);
        $lon = floatval($inc['lon'] ?? 0);
        if ($lat >= $south && $lat <= $north && $lon >= $west && $lon <= $east) {
            $warnings[] = formatWarning(
                $inc['tipo'] ?? 'incidencia',
                $inc['titulo'] ?? 'Incidencia vial',
                $inc['detalle'] ?? '',
                $inc['carretera'] ?? '',
                $lat, $lon,
                mapDGTSeverity($inc['nivel'] ?? ''),
                'DGT'
            );
        }
    }
}

// 2. Avisos de la comunidad (reportes de usuarios)
if (file_exists($reportsFile)) {
    $userReports = json_decode(file_get_contents($reportsFile), true) ?: [];
    // Solo los de las últimas 4 horas y sin demasiados votos negativos
    $cutoff = time() - 14400;
    foreach ($userReports as $r) {
        if (($r['votes_dismiss'] ?? 0) >= 5) continue; // Descartado por comunidad
        $reportTime = strtotime($r['time'] ?? 'now');
        if ($reportTime < $cutoff) continue;
        $lat = floatval($r['lat'] ?? 0);
        $lon = floatval($r['lon'] ?? 0);
        if ($lat >= $south && $lat <= $north && $lon >= $west && $lon <= $east) {
            $typeInfo = getTypeInfo($r['type'] ?? 'peligro');
            $w = formatWarning(
                $r['type'] ?? 'peligro',
                $typeInfo['title'],
                $r['comment'] ?? $typeInfo['desc'],
                $r['road'] ?? '',
                $lat, $lon,
                $typeInfo['severity'],
                'Comunidad'
            );
            $w['id'] = $r['id'] ?? '';
            $w['votes_confirm'] = $r['votes_confirm'] ?? 0;
            $w['votes_dismiss'] = $r['votes_dismiss'] ?? 0;
            $warnings[] = $w;
        }
    }
}

echo json_encode($warnings);

// ===========================================================
// Funciones
// ===========================================================

function formatWarning($type, $title, $detail, $road, $lat, $lon, $severity, $source): array {
    $info = getTypeInfo($type);
    return [
        'type'     => $type,
        'title'    => $title ?: $info['title'],
        'detail'   => $detail,
        'road'     => $road,
        'lat'      => $lat,
        'lon'      => $lon,
        'severity' => $severity ?: $info['severity'],
        'icon'     => $info['icon'],
        'emoji'    => $info['emoji'],
        'source'   => $source
    ];
}

function getTypeInfo(string $type): array {
    return match($type) {
        'policia'       => ['icon' => 'police',     'emoji' => '👮', 'title' => 'Policía',                'desc' => 'Presencia policial en la vía',               'severity' => 'caution'],
        'accidente'     => ['icon' => 'accident',    'emoji' => '💥', 'title' => 'Accidente',              'desc' => 'Accidente de tráfico reportado',              'severity' => 'danger'],
        'peligro'       => ['icon' => 'hazard',      'emoji' => '⚠️', 'title' => 'Peligro en la vía',      'desc' => 'Peligro reportado en la calzada',             'severity' => 'warning'],
        'obras'         => ['icon' => 'construction','emoji' => '🚧', 'title' => 'Obras',                   'desc' => 'Obras en la carretera',                      'severity' => 'warning'],
        'trafico'       => ['icon' => 'traffic',     'emoji' => '🔴', 'title' => 'Atasco / Retención',      'desc' => 'Retención de tráfico',                       'severity' => 'warning'],
        'vehiculo_parado'=> ['icon' => 'stopped',    'emoji' => '🚙', 'title' => 'Vehículo parado',         'desc' => 'Vehículo detenido en la calzada o arcén',     'severity' => 'caution'],
        'radar'         => ['icon' => 'camera',      'emoji' => '📸', 'title' => 'Radar / Velocidad',       'desc' => 'Control de velocidad',                       'severity' => 'caution'],
        'nieve'         => ['icon' => 'snow',        'emoji' => '❄️', 'title' => 'Nieve / Hielo',           'desc' => 'Presencia de nieve o hielo en la vía',        'severity' => 'danger'],
        'viento'        => ['icon' => 'wind',        'emoji' => '💨', 'title' => 'Viento fuerte',           'desc' => 'Rachas de viento fuerte',                     'severity' => 'warning'],
        'inundacion'    => ['icon' => 'flood',       'emoji' => '🌊', 'title' => 'Inundación',              'desc' => 'Vía afectada por agua',                      'severity' => 'danger'],
        'animales'      => ['icon' => 'animal',      'emoji' => '🐗', 'title' => 'Animales en la vía',      'desc' => 'Animales sueltos en la calzada',              'severity' => 'warning'],
        'corte'         => ['icon' => 'closed',      'emoji' => '🚫', 'title' => 'Carretera cortada',       'desc' => 'Vía cortada al tráfico',                      'severity' => 'danger'],
        'desprendimiento'=> ['icon' => 'rockslide',  'emoji' => '⛰️', 'title' => 'Desprendimiento',         'desc' => 'Desprendimiento de tierras o rocas',          'severity' => 'danger'],
        'peaton'        => ['icon' => 'pedestrian',  'emoji' => '🚶', 'title' => 'Peatón en la vía',        'desc' => 'Peatón en la calzada',                        'severity' => 'warning'],
        'bicicleta'     => ['icon' => 'bike',        'emoji' => '🚲', 'title' => 'Ciclista',                'desc' => 'Ciclistas en la vía',                         'severity' => 'caution'],
        'cerrada'       => ['icon' => 'closed',      'emoji' => '🔒', 'title' => 'Vía cerrada',             'desc' => 'Carretera cerrada',                           'severity' => 'danger'],
        default         => ['icon' => 'alert',       'emoji' => '⚠️', 'title' => 'Aviso',                   'desc' => 'Incidencia en la vía',                        'severity' => 'caution']
    };
}

/**
 * Incidencias oficiales de la DGT (DATEX II): obras, cortes, accidentes,
 * retenciones, meteorología... Se guardan ya procesadas 5 minutos en caché.
 */
function fetchDGTData(): ?array {
    $cacheFile = __DIR__ . '/../data/dgt_cache.json';
    if (file_exists($cacheFile) && time() - filemtime($cacheFile) < 300) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 25,
            'method'  => 'GET',
            'header'  => "User-Agent: Rumbo/1.0\r\nAccept: application/xml, text/xml\r\n"
        ]
    ]);

    $results = null;
    foreach (DGT_DATEX_URLS as $url) {
        $xml = @file_get_contents($url, false, $ctx);
        if ($xml === false || strlen($xml) < 200) continue;
        $results = parseDatex($xml);
        if ($results !== null) break;
    }

    if ($results !== null) {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($cacheFile, json_encode($results, JSON_UNESCAPED_UNICODE));
        return $results;
    }

    // Si la DGT no responde, mejor datos algo antiguos que ninguno
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    return null;
}

/**
 * Lee un documento DATEX II (v1 de infocar o v3 del Punto de Acceso Nacional)
 * sin depender de los prefijos de espacio de nombres.
 */
function parseDatex(string $xml): ?array {
    $reader = new XMLReader();
    if (!@$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) return null;

    $results = [];
    $found = false;
    $situationSeverity = '';
    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
        // En DATEX II v3 la gravedad va en la situación, fuera de cada registro
        if ($reader->localName === 'situation') { $situationSeverity = ''; continue; }
        if ($reader->localName === 'overallSeverity') { $situationSeverity = trim($reader->readString()); continue; }
        if ($reader->localName !== 'situationRecord') continue;
        $found = true;
        $node = $reader->expand();
        if (!$node) continue;

        $doc = new DOMDocument();
        $doc->appendChild($doc->importNode($node, true));
        $xp = new DOMXPath($doc);
        $text = function (string $names) use ($xp): string {
            $q = implode(' or ', array_map(fn($n) => "local-name()='$n'", explode('|', $names)));
            $n = $xp->query("//*[$q]")->item(0);
            return $n ? trim($n->textContent) : '';
        };

        if (strtolower($text('validityStatus')) === 'suspended') continue;

        $lat = floatval($text('latitude'));
        $lon = floatval($text('longitude'));
        if (!$lat || !$lon) continue;

        $root = $doc->documentElement;
        $xsiType = $root->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
        $xsiType = preg_replace('/^.*:/', '', $xsiType);
        $subtype = $text('roadMaintenanceType|constructionWorkType|accidentType|abnormalTrafficType|vehicleObstructionType|obstructionType|environmentalObstructionType|animalPresenceType|poorEnvironmentType|weatherRelatedRoadConditionType|roadOrCarriagewayOrLaneManagementType|networkManagementType|complianceOption');

        // Carretera: número de vía (v3) o descriptores con el nombre del tramo (v1)
        $road = $text('roadNumber');
        if ($road === '') {
            $descriptors = [];
            foreach ($xp->query("//*[local-name()='value']") as $v) $descriptors[] = $v->textContent;
            $road = extractRoad(implode(' ', $descriptors));
        }

        $type = datexType($xsiType, $subtype);
        $comment = $text('generalPublicComment');
        $results[] = [
            'titulo'    => getTypeInfo($type)['title'],
            'detalle'   => $comment !== '' ? mb_substr($comment, 0, 200) : datexSubtypeText($subtype),
            'lat'       => round($lat, 5),
            'lon'       => round($lon, 5),
            'tipo'      => $type,
            'carretera' => $road,
            'nivel'     => $text('overallSeverity|severity') ?: $situationSeverity,
        ];
    }
    $reader->close();

    return $found ? $results : null;
}

function datexType(string $xsiType, string $subtype): string {
    $sub = strtolower($subtype);
    switch ($xsiType) {
        case 'MaintenanceWorks':
        case 'ConstructionWorks':
        case 'Roadworks':
            return 'obras';
        case 'Accident':
            return 'accidente';
        case 'AbnormalTraffic':
            return 'trafico';
        case 'VehicleObstruction':
            return 'vehiculo_parado';
        case 'AnimalPresenceObstruction':
            return 'animales';
        case 'EnvironmentalObstruction':
            if (str_contains($sub, 'flood')) return 'inundacion';
            if (str_contains($sub, 'rock') || str_contains($sub, 'slide') || str_contains($sub, 'avalanche')) return 'desprendimiento';
            return 'peligro';
        case 'PoorEnvironmentConditions':
        case 'WeatherRelatedRoadConditions':
            if (str_contains($sub, 'snow') || str_contains($sub, 'ice') || str_contains($sub, 'frost')) return 'nieve';
            if (str_contains($sub, 'wind') || str_contains($sub, 'gust')) return 'viento';
            if (str_contains($sub, 'flood')) return 'inundacion';
            return 'peligro';
        case 'RoadOrCarriagewayOrLaneManagement':
        case 'NetworkManagement':
        case 'ReroutingManagement':
            if (str_contains($sub, 'closed') || str_contains($sub, 'closure')) return 'corte';
            if (str_contains($sub, 'snow') || str_contains($sub, 'chain')) return 'nieve';
            return 'peligro';
        default:
            return 'incidencia';
    }
}

function datexSubtypeText(string $subtype): string {
    $map = [
        'roadworks' => 'Obras en la calzada',
        'resurfacingWork' => 'Trabajos de asfaltado',
        'maintenanceWork' => 'Trabajos de mantenimiento',
        'roadMarkingWork' => 'Pintado de marcas viales',
        'overheadWorks' => 'Trabajos en altura',
        'repairWork' => 'Reparaciones en la vía',
        'emergencyRepairWork' => 'Reparación urgente',
        'stationaryTraffic' => 'Tráfico detenido',
        'queuingTraffic' => 'Retención',
        'slowTraffic' => 'Circulación lenta',
        'heavyTraffic' => 'Tráfico denso',
        'roadClosed' => 'Carretera cortada',
        'laneClosures' => 'Carriles cortados',
        'carriagewayClosures' => 'Calzada cortada',
        'singleAlternateLineTraffic' => 'Paso alternativo',
        'contraflow' => 'Circulación en sentido contrario por obras',
        'narrowLanes' => 'Carriles estrechos',
        'snowChainsMandatory' => 'Cadenas obligatorias',
        'snowOnTheRoad' => 'Nieve en la calzada',
        'ice' => 'Hielo en la calzada',
        'fog' => 'Niebla',
        'strongWinds' => 'Viento fuerte',
        'flooding' => 'Calzada inundada',
        'rockfalls' => 'Caída de piedras',
        'brokenDownVehicle' => 'Vehículo averiado',
        'accident' => 'Accidente',
    ];
    return $map[$subtype] ?? '';
}

function extractRoad(string $t): string {
    return preg_match('/\b([A-Z]{1,3}-?\d{1,4}[A-Z]?)\b/', mb_strtoupper($t), $m) ? $m[1] : '';
}

function mapDGTSeverity(string $n): string {
    return match(mb_strtolower($n)) {
        'highest', 'high', 'alto', 'rojo' => 'danger',
        'medium', 'medio', 'naranja'      => 'warning',
        'low', 'lowest', 'bajo', 'amarillo' => 'caution',
        default                           => ''   // sin dato: la gravedad propia del tipo
    };
}
