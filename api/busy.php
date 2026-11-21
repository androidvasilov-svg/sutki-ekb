<?php
// Занятые даты квартир для календаря на сайте:
//   /api/busy.php?flat=<slug> — одна квартира: {"ok":true,"busy":[[заезд, выезд], …]}
//   /api/busy.php?flat=all    — все известные: {"ok":true,"flats":{"<slug>":[[заезд, выезд], …], …}}
// Забирает экспорт RealtyCalendar и отдаёт только будущие занятые даты, без данных о бронях.

date_default_timezone_set('Asia/Yekaterinburg');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('X-Robots-Tag: noindex');

// Адрес страницы квартиры => её id в RealtyCalendar. Новую квартиру добавлять сюда.
$flats = [
    'verkhnyaya' => 113239,
    'nizhnyaya' => 82888,
    'melnitsa' => 78200,
    'sverdlova' => 260609,
    'tsiolkovskogo' => 231276,
    'krasnolesya' => 155941,
    'mekhrentseva' => 139339,
    'pobedy' => 145012,
    'laguna' => 277955,
    'dubai' => 289303,
    'avrora' => 349972,
    'viktoriya' => 373896,
    'malyy-7' => 247421,
    'malyy-6' => 221813,
    'frunze' => 291144,
    'sverdlova-32' => 270198,
    'ananyeva-4' => 314503,
];

const CACHE_SECONDS = 300;

$flat = isset($_GET['flat']) ? (string) $_GET['flat'] : '';
if ($flat !== 'all' && !isset($flats[$flat])) {
    echo json_encode(['known' => false]);
    exit;
}

function download($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 7,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 ? $body : false;
    }
    $context = stream_context_create(['http' => ['timeout' => 7]]);
    return @file_get_contents($url, false, $context);
}

function parseBusy($ics)
{
    $today = date('Ymd');
    $busy = [];
    foreach (explode('BEGIN:VEVENT', $ics) as $event) {
        if (preg_match('/^DTSTART[^:\r\n]*:(\d{8})/m', $event, $start)
            && preg_match('/^DTEND[^:\r\n]*:(\d{8})/m', $event, $end)
            && $end[1] > $today) {
            $busy[] = [iso($start[1]), iso($end[1])];
        }
    }
    sort($busy);
    return $busy;
}

function iso($ymd)
{
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

// Занятые даты одной квартиры из кэша или RealtyCalendar; false — данных нет совсем.
// $mayFetch = false — только кэш (когда общий запрос по всем квартирам уже идёт слишком долго).
function busyFor($slug, $id, $mayFetch = true)
{
    $cacheFile = sys_get_temp_dir() . '/uralsutki-busy-v2-' . $slug . '.json';
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false && (!$mayFetch || time() - filemtime($cacheFile) < CACHE_SECONDS)) {
        return json_decode($cached, true);
    }
    if (!$mayFetch) {
        return false;
    }

    $ics = download('https://realtycalendar.ru/apartments/export.ics?q=' . base64_encode((string) $id) . '%0A');
    if ($ics === false || strpos($ics, 'BEGIN:VCALENDAR') === false) {
        // Лучше вчерашние данные, чем ни одной брони: пустой календарь показал бы всё свободным.
        return $cached !== false ? json_decode($cached, true) : false;
    }

    $busy = parseBusy($ics);
    @file_put_contents($cacheFile, json_encode($busy), LOCK_EX);
    return $busy;
}

if ($flat === 'all') {
    $all = [];
    $started = microtime(true);
    foreach ($flats as $slug => $id) {
        $busy = busyFor($slug, $id, microtime(true) - $started < 12);
        if ($busy !== false) {
            $all[$slug] = $busy;
        }
    }
    echo json_encode(['ok' => true, 'flats' => (object) $all]);
    exit;
}

$busy = busyFor($flat, $flats[$flat]);
if ($busy === false) {
    http_response_code(502);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true, 'busy' => $busy]);
