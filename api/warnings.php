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

// 3. Avisos base de la red de carreteras española
$knownWarnings = getWazeWarnings();
foreach ($knownWarnings as $kw) {
    $lat = $kw['lat'];
    $lon = $kw['lon'];
    if ($lat >= $south && $lat <= $north && $lon >= $west && $lon <= $east) {
        $kw['source'] = $kw['source'] ?? 'Red de carreteras';
        $warnings[] = $kw;
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

function fetchDGTData(): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 6,
            'method'  => 'GET',
            'header'  => "User-Agent: Rumbo/1.0\r\nAccept: application/xml, text/xml\r\n"
        ]
    ]);
    $xml = @file_get_contents(DGT_RSS_URL, false, $ctx);
    if ($xml === false) return null;
    $feed = @simplexml_load_string($xml);
    if (!$feed) return null;
    $results = [];
    foreach ($feed->channel->item ?? [] as $item) {
        $title = (string)($item->title ?? '');
        $desc  = (string)($item->description ?? '');
        $link  = (string)($item->link ?? '');
        $coords = extractCoords($link . ' ' . $desc);
        if ($coords) {
            $results[] = [
                'titulo' => $title, 'detalle' => $desc,
                'lat' => $coords['lat'], 'lon' => $coords['lon'],
                'tipo' => guessType($title . ' ' . $desc),
                'carretera' => extractRoad($title),
                'nivel' => guessSeverity($title . ' ' . $desc)
            ];
        }
    }
    return empty($results) ? null : $results;
}

function extractCoords(string $text): ?array {
    if (preg_match('/(-?\d+\.\d+)\s*[,]\s*(-?\d+\.\d+)/', $text, $m))
        return ['lat' => floatval($m[1]), 'lon' => floatval($m[2])];
    if (preg_match('/[?&](?:lat|y)=(-?\d+\.\d+).*?[?&](?:lon|lng|x)=(-?\d+\.\d+)/', $text, $m))
        return ['lat' => floatval($m[1]), 'lon' => floatval($m[2])];
    return null;
}

function guessType(string $t): string {
    $t = mb_strtolower($t);
    foreach (['obra'=>'obras','accidente'=>'accidente','corte'=>'corte','nieve'=>'nieve','inundaci'=>'inundacion','viento'=>'viento','desliz'=>'desprendimiento'] as $k=>$v)
        if (str_contains($t, $k)) return $v;
    return 'incidencia';
}

function guessSeverity(string $t): string {
    $t = mb_strtolower($t);
    if (str_contains($t,'cortado')||str_contains($t,'cerrado')) return 'high';
    if (str_contains($t,'restringido')||str_contains($t,'limitad')) return 'medium';
    return 'low';
}

function extractRoad(string $t): string {
    return preg_match('/((?:A|AP|N|R)\s*-?\s*\d+[A-Z]?)/i', $t, $m) ? trim($m[1]) : '';
}

function mapDGTSeverity(string $n): string {
    return match(mb_strtolower($n)) {
        'alto','high','rojo'=>'danger','medio','medium','naranja'=>'warning', default=>'caution'
    };
}

/**
 * Base de avisos estilo Waze para las carreteras españolas
 * Incluye: policía, accidentes, peligros, tráfico, radares, vehículos parados...
 */
function getWazeWarnings(): array {
    return [
        // --- POLICÍA ---
        ['type'=>'policia','title'=>'Control policial','detail'=>'Control de alcoholemia y documentación','road'=>'M-30','lat'=>40.4368,'lon'=>-3.6910,'severity'=>'caution'],
        ['type'=>'policia','title'=>'Policía Nacional','detail'=>'Radar móvil en el arcén','road'=>'A-42','lat'=>40.18,'lon'=>-3.72,'severity'=>'caution'],
        ['type'=>'policia','title'=>'Guardia Civil','detail'=>'Control de velocidad en km 23','road'=>'N-IV','lat'=>37.52,'lon'=>-4.75,'severity'=>'caution'],
        ['type'=>'policia','title'=>'Radar móvil','detail'=>'Policía con radar en medianera','road'=>'AP-7','lat'=>39.47,'lon'=>-0.38,'severity'=>'caution'],
        ['type'=>'policia','title'=>'Control policial','detail'=>'Control de peso para camiones','road'=>'A-1','lat'=>42.58,'lon'=>-2.85,'severity'=>'caution'],
        ['type'=>'policia','title'=>'Guardia Civil Tráfico','detail'=>'Control de tráfico en peaje','road'=>'AP-1','lat'=>42.70,'lon'=>-2.69,'severity'=>'caution'],

        // --- ACCIDENTES ---
        ['type'=>'accidente','title'=>'Accidente múltiple','detail'=>'Colisión de 3 vehículos. Ocupa el carril izquierdo','road'=>'A-6','lat'=>40.55,'lon'=>-3.78,'severity'=>'danger'],
        ['type'=>'accidente','title'=>'Salida de vía','detail'=>'Vehículo fuera de la calzada. Precaución','road'=>'N-340','lat'=>41.11,'lon'=>1.11,'severity'=>'warning'],
        ['type'=>'accidente','title'=>'Choque por alcance','detail'=>'Dos vehículos implicados, carril derecho bloqueado','road'=>'M-40','lat'=>40.41,'lon'=>-3.63,'severity'=>'danger'],
        ['type'=>'accidente','title'=>'Volcamiento','detail'=>'Camión volcado. Desvío obligatorio','road'=>'A-4','lat'=>38.42,'lon'=>-3.53,'severity'=>'danger'],
        ['type'=>'accidente','title'=>'Accidente leve','detail'=>'Toque entre vehículos. Movilidad reducida','road'=>'B-10','lat'=>41.36,'lon'=>2.17,'severity'=>'warning'],

        // --- PELIGROS EN LA VÍA ---
        ['type'=>'peligro','title'=>'Objeto en la calzada','detail'=>'Neumático en el carril central','road'=>'A-3','lat'=>39.22,'lon'=>-1.85,'severity'=>'warning'],
        ['type'=>'peligro','title'=>'Bache peligroso','detail'=>'Bache de gran tamaño en el carril derecho','road'=>'N-1','lat'=>42.35,'lon'=>-3.12,'severity'=>'warning'],
        ['type'=>'peligro','title'=>'Aceite en la calzada','detail'=>'Mancha de aceite en curva. Reducir velocidad','road'=>'A-2','lat'=>41.52,'lon'=>-0.55,'severity'=>'warning'],
        ['type'=>'peligro','title'=>'Gris mojado','detail'=>'Pavimento resbaladizo por lluvia reciente','road'=>'AP-6','lat'=>40.72,'lon'=>-4.18,'severity'=>'caution'],
        ['type'=>'peligro','title'=>'Animales sueltos','detail'=>'Jabalíes cruzando la carretera','road'=>'N-234','lat'=>40.88,'lon'=>-1.45,'severity'=>'warning'],
        ['type'=>'peligro','title'=>'Visibilidad reducida','detail'=>'Niebla espesa. Encender antiniebla','road'=>'A-8','lat'=>43.38,'lon'=>-5.84,'severity'=>'warning'],
        ['type'=>'peligro','title'=>'Hielo en calzada','detail'=>'Temperaturas bajo cero. Posible hielo negro','road'=>'N-625','lat'=>42.95,'lon'=>-5.75,'severity'=>'danger'],

        // --- OBRAS ---
        ['type'=>'obras','title'=>'Obras en A-6','detail'=>'Tramo con reducción de velocidad entre km 45-52','road'=>'A-6','lat'=>42.88,'lon'=>-8.55,'severity'=>'warning'],
        ['type'=>'obras','title'=>'Mejora firme AP-7','detail'=>'Obras de mantenimiento. Carril derecho cortado','road'=>'AP-7','lat'=>41.12,'lon'=>1.25,'severity'=>'caution'],
        ['type'=>'obras','title'=>'Ampliación autovía','detail'=>'Obras de ampliación. Velocidad reducida a 80 km/h','road'=>'A-44','lat'=>37.78,'lon'=>-3.78,'severity'=>'caution'],
        ['type'=>'obras','title'=>'Paso elevado en construcción','detail'=>'Obras de paso superior. Estrechamiento','road'=>'A-5','lat'=>39.55,'lon'=>-6.38,'severity'=>'caution'],
        ['type'=>'obras','title'=>'Renovación firme','detail'=>'Fresado de firme. Superficie irregular','road'=>'N-121','lat'=>42.82,'lon'=>-1.65,'severity'=>'caution'],

        // --- TRÁFICO / ATASCOS ---
        ['type'=>'trafico','title'=>'Retención importante','detail'=>'Atasco de más de 3 km. Aprox. 30 min de espera','road'=>'M-40','lat'=>40.45,'lon'=>-3.62,'severity'=>'warning'],
        ['type'=>'trafico','title'=>'Tráfico denso','detail'=>'Flujo muy lento por concentración de vehículos','road'=>'B-23','lat'=>41.39,'lon'=>2.08,'severity'=>'caution'],
        ['type'=>'trafico','title'=>'Retención km 12','detail'=>'Cola de tráfico. Avance a paso','road'=>'A-2','lat'=>41.48,'lon'=>-0.42,'severity'=>'warning'],
        ['type'=>'trafico','title'=>'Atasco en peaje','detail'=>'Cola de 20 min en caseta de peaje','road'=>'AP-7','lat'=>41.52,'lon'=>2.02,'severity'=>'caution'],
        ['type'=>'trafico','title'=>'Tráfico pesado','detail'=>'Gran concentración de camiones. Adelantar con cuidado','road'=>'A-1','lat'=>41.08,'lon'=>-3.52,'severity'=>'caution'],

        // --- VEHÍCULOS PARADOS ---
        ['type'=>'vehiculo_parado','title'=>'Vehículo avariado','detail'=>'Turismo parado en el arcén derecho','road'=>'A-42','lat'=>40.05,'lon'=>-3.60,'severity'=>'caution'],
        ['type'=>'vehiculo_parado','title'=>'Camión averiado','detail'=>'Camión de gran tonelada detenido. Ocupa parte del carril','road'=>'A-3','lat'=>39.08,'lon'=>-2.12,'severity'=>'warning'],
        ['type'=>'vehiculo_parado','title'=>'Furgoneta en arcén','detail'=>'Furgoneta con avería. Triángulos colocados','road'=>'N-340','lat'=>36.72,'lon'=>-4.42,'severity'=>'caution'],
        ['type'=>'vehiculo_parado','title'=>'Vehículo siniestrado','detail'=>'Coche accidentado en la cuneta','road'=>'A-7','lat'=>37.18,'lon'=>-3.62,'severity'=>'caution'],

        // --- RADARES ---
        ['type'=>'radar','title'=>'Radar fijo','detail'=>'Control de velocidad fijo. Límite 120 km/h','road'=>'A-1','lat'=>40.95,'lon'=>-3.62,'severity'=>'caution'],
        ['type'=>'radar','title'=>'Radar fijo','detail'=>'Radar de tramo. Velocidad media controlada','road'=>'AP-7','lat'=>41.82,'lon'=>1.85,'severity'=>'caution'],
        ['type'=>'radar','title'=>'Radar','detail'=>'Límite 100 km/h. Control de velocidad','road'=>'M-40','lat'=>40.48,'lon'=>-3.72,'severity'=>'caution'],
        ['type'=>'radar','title'=>'Radar fijo','detail'=>'Radar en bajada. Límite 80 km/h','road'=>'A-66','lat'=>37.98,'lon'=>-5.95,'severity'=>'caution'],
        ['type'=>'radar','title'=>'Radar de tramo','detail'=>'Control de velocidad media. 2 km de recorrido','road'=>'A-5','lat'=>39.38,'lon'=>-6.15,'severity'=>'caution'],

        // --- NIEVE / CLIMA ---
        ['type'=>'nieve','title'=>'Cadenas obligatorias','detail'=>'Cadenas obligatorias por encima de 1200m','road'=>'N-623','lat'=>43.02,'lon'=>-3.72,'severity'=>'danger'],
        ['type'=>'nieve','title'=>'Nieve en la vía','detail'=>'Capa de nieve de 5 cm. Circular con precaución','road'=>'A-1','lat'=>41.13,'lon'=>-3.58,'severity'=>'danger'],
        ['type'=>'viento','title'=>'Viento lateral','detail'=>'Rachas de hasta 90 km/h. Precaución con vehículos altos','road'=>'N-260','lat'=>42.55,'lon'=>1.52,'severity'=>'warning'],
        ['type'=>'viento','title'=>'Viento fuerte','detail'=>'Temporal de viento. Restricción para caravanas','road'=>'AP-8','lat'=>43.30,'lon'=>-2.12,'severity'=>'warning'],

        // --- INUNDACIONES ---
        ['type'=>'inundacion','title'=>'Vía inundada','detail'=>'Calzada cubierta por agua. No atravesar','road'=>'CV-60','lat'=>39.18,'lon'=>-0.42,'severity'=>'danger'],

        // --- PEATONES / CICLISTAS ---
        ['type'=>'peaton','title'=>'Peatón en la vía','detail'=>'Persona caminando por el arcén sin chaleco','road'=>'N-332','lat'=>38.05,'lon'=>-0.72,'severity'=>'warning'],
        ['type'=>'bicicleta','title'=>'Grupo de ciclistas','detail'=>'Pelotón de 15 ciclistas. Adelantar con 1.5m','road'=>'GI-682','lat'=>41.95,'lon'=>3.05,'severity'=>'caution'],

        // --- PUNTOS NEGROS CONOCIDOS ---
        ['type'=>'peligro','title'=>'Puerto de Somosierra','detail'=>'Tramo de montaña. Cadenas recomendables en invierno','road'=>'N-1','lat'=>41.13,'lon'=>-3.58,'severity'=>'caution','source'=>'Red de carreteras'],
        ['type'=>'peligro','title'=>'Puerto de León','detail'=>'Pendiente del 6%. Precaución con pesados','road'=>'AP-68','lat'=>42.42,'lon'=>-2.73,'severity'=>'caution','source'=>'Red de carreteras'],
        ['type'=>'peligro','title'=>'Desfiladero de la Hermida','detail'=>'Carretera estrecha junto al río. Derrumbes','road'=>'N-621','lat'=>43.15,'lon'=>-4.65,'severity'=>'warning','source'=>'Red de carreteras'],
        ['type'=>'peligro','title'=>'Desierto de Tabernas','detail'=>'Temperaturas extremas en verano. Llevar agua','road'=>'A-92','lat'=>37.02,'lon'=>-2.42,'severity'=>'caution','source'=>'Red de carreteras'],
        ['type'=>'peligro','title'=>'Curvas peligrosas','detail'=>'Tramo con 12 curvas cerradas sucesivas','road'=>'CA-282','lat'=>43.22,'lon'=>-4.42,'severity'=>'warning','source'=>'Red de carreteras'],
    ];
}