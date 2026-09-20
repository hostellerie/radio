<?php
require_once '../lib-common.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$state = RADIO_getLiveState(time());
$upcoming = RADIO_getUpcoming(5, time());

$payload = array(
    'generated_at' => date('c'),
    'now_playing' => false,
    'current_media' => false,
    'upcoming' => array()
);

if ($state['program'] !== false) {
    $program = $state['program'];
    $payload['now_playing'] = array(
        'program_id' => RADIO_externalId('program', $program['program_id']),
        'title' => $program['program_title'],
        'url' => $program['url'],
        'start' => date('c', $program['start']),
        'end' => date('c', $program['end']),
        'elapsed' => (int) $program['elapsed'],
        'remaining' => (int) $program['remaining']
    );
}

if ($state['media'] !== false) {
    $media = $state['media'];
    $payload['current_media'] = array(
        'media_id' => $media['external_id'],
        'title' => $media['title'],
        'media_type' => $media['media_type'],
        'duration' => (int) $media['duration'],
        'offset' => (int) $media['offset'],
        'stream_url' => $media['stream_url'],
        'url' => $media['item_url']
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
