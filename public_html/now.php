<?php
require_once '../lib-common.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$now = RADIO_getNowPlaying(time());
$upcoming = RADIO_getUpcoming(5, time());

$payload = array(
    'generated_at' => date('c'),
    'now_playing' => false,
    'upcoming' => array()
);

if ($now !== false) {
    $payload['now_playing'] = array(
        'program_id' => RADIO_externalId('program', $now['program_id']),
        'title' => $now['program_title'],
        'url' => $now['url'],
        'start' => date('c', $now['start']),
        'end' => date('c', $now['end']),
        'elapsed' => (int) $now['elapsed'],
        'remaining' => (int) $now['remaining']
    );
}

foreach ($upcoming as $item) {
    $payload['upcoming'][] = array(
        'program_id' => RADIO_externalId('program', $item['program_id']),
        'title' => $item['program_title'],
        'url' => $item['url'],
        'start' => date('c', $item['start']),
        'end' => date('c', $item['end'])
    );
}

echo json_encode($payload);
