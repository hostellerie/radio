<?php
require_once '../lib-common.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$state = RADIO_getLiveState(time());
$upcoming = RADIO_getUpcoming(5, time());

$payload = array(
    'generated_at' => date('c'),
    'source' => $state['source'],
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
    $payload['transition_mode'] = isset($media['transition_mode'])
        ? $media['transition_mode']
        : RADIO_transitionMode();
    $payload['transition_seconds'] = isset($media['transition_seconds'])
        ? (int) $media['transition_seconds']
        : 0;
    $payload['current_media'] = array(
        'media_id' => $media['external_id'],
        'title' => $media['title'],
        'media_type' => $media['media_type'],
        'source_kind' => isset($media['source_kind']) ? $media['source_kind'] : 'local',
        'duration' => (int) $media['duration'],
        'slot_duration' => isset($media['slot_duration']) ? (int) $media['slot_duration'] : (int) $media['duration'],
        'offset' => (int) $media['offset'],
        'stream_url' => $media['stream_url'],
        'url' => $media['item_url']
    );
    if (!empty($media['next_media']) && is_array($media['next_media'])) {
        $next = $media['next_media'];
        $payload['next_media'] = array(
            'media_id' => $next['external_id'],
            'title' => $next['title'],
            'media_type' => $next['media_type'],
            'source_kind' => isset($next['source_kind']) ? $next['source_kind'] : 'local',
            'duration' => (int) $next['duration'],
            'stream_url' => $next['stream_url'],
            'url' => $next['item_url']
        );
    } else {
        $payload['next_media'] = false;
    }
    if (!empty($media['next_next_media']) && is_array($media['next_next_media'])) {
        $nextNext = $media['next_next_media'];
        $payload['next_next_media'] = array(
            'media_id' => $nextNext['external_id'],
            'title' => $nextNext['title'],
            'media_type' => $nextNext['media_type'],
            'source_kind' => isset($nextNext['source_kind']) ? $nextNext['source_kind'] : 'local',
            'duration' => (int) $nextNext['duration'],
            'stream_url' => $nextNext['stream_url'],
            'url' => $nextNext['item_url']
        );
    } else {
        $payload['next_next_media'] = false;
    }
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
