<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';

function radio_studio_json($data, $status)
{
    if (function_exists('http_response_code')) {
        http_response_code((int) $status);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data);
    exit;
}

function radio_studio_items($programId)
{
    $items = RADIO_getProgramItems((int) $programId);
    $playlist = array();
    $offset = 0;

    foreach ($items as $item) {
        if (!RADIO_isBroadcastAvailable($item)
            || !RADIO_hasReadAccess($item)
            || (int) $item['duration'] < 1) {
            continue;
        }

        $duration = (int) $item['duration'];
        $playlist[] = array(
            'item_id' => (int) $item['item_id'],
            'media_id' => (int) $item['media_id'],
            'title' => $item['title'],
            'author' => isset($item['author']) ? $item['author'] : '',
            'media_type' => isset($item['media_type']) ? $item['media_type'] : '',
            'category' => isset($item['category']) ? $item['category'] : '',
            'collection' => isset($item['collection_name']) ? $item['collection_name'] : '',
            'tags' => isset($item['tags']) ? $item['tags'] : '',
            'duration' => $duration,
            'offset' => $offset,
            'stream_url' => RADIO_mediaUrl((int) $item['media_id'], false)
        );
        $offset += $duration;
    }

    return $playlist;
}

function radio_studio_state($programId)
{
    $items = radio_studio_items($programId);
    $signature = array();
    foreach ($items as $item) {
        $signature[] = $item['item_id'] . ':' . $item['media_id'] . ':' . $item['duration'];
    }

    return array(
        'ok' => true,
        'items' => $items,
        'version' => sha1(implode('|', $signature)),
        'csrf_name' => CSRF_TOKEN,
        'csrf_token' => SEC_createToken()
    );
}

if (!SEC_hasRights('radio.schedule')) {
    radio_studio_json(array('ok' => false, 'error' => 'access_denied'), 403);
}

$programId = isset($_REQUEST['program_id']) ? (int) $_REQUEST['program_id'] : 0;
$program = $programId > 0 ? RADIO_getProgram($programId, false) : false;
if ($program === false || !RADIO_hasEditAccess($program)) {
    radio_studio_json(array('ok' => false, 'error' => 'access_denied'), 403);
}

$action = isset($_REQUEST['action']) ? trim((string) $_REQUEST['action']) : 'state';

if ($action === 'state') {
    radio_studio_json(radio_studio_state($programId), 200);
}

if ($action === 'search') {
    $filters = array(
        'q' => isset($_GET['q']) ? trim((string) $_GET['q']) : '',
        'type' => isset($_GET['type']) ? trim((string) $_GET['type']) : '',
        'category' => isset($_GET['category']) ? trim((string) $_GET['category']) : '',
        'collection' => isset($_GET['collection']) ? trim((string) $_GET['collection']) : '',
        'tag' => isset($_GET['tag']) ? trim((string) $_GET['tag']) : '',
        'status' => 'published',
        'broadcast' => '1'
    );

    $rows = RADIO_getMediaList(60, false, 'title', 'asc', $filters);
    $results = array();

    foreach ($rows as $row) {
        if (!RADIO_hasReadAccess($row) || !RADIO_isBroadcastAvailable($row)) {
            continue;
        }
        $results[] = array(
            'media_id' => (int) $row['media_id'],
            'title' => $row['title'],
            'author' => isset($row['author']) ? $row['author'] : '',
            'media_type' => isset($row['media_type']) ? $row['media_type'] : '',
            'category' => isset($row['category']) ? $row['category'] : '',
            'collection' => isset($row['collection_name']) ? $row['collection_name'] : '',
            'tags' => isset($row['tags']) ? $row['tags'] : '',
            'duration' => isset($row['duration']) ? (int) $row['duration'] : 0
        );
    }

    radio_studio_json(array(
        'ok' => true,
        'results' => $results,
        'csrf_name' => CSRF_TOKEN,
        'csrf_token' => SEC_createToken()
    ), 200);
}

if ($action === 'add') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !SEC_checkToken()) {
        radio_studio_json(array('ok' => false, 'error' => 'invalid_token'), 403);
    }

    $mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
    $position = isset($_POST['position']) ? trim((string) $_POST['position']) : 'end';
    $currentItemId = isset($_POST['current_item_id']) ? (int) $_POST['current_item_id'] : 0;
    $afterItemId = $position === 'next' ? $currentItemId : 0;

    if (!RADIO_addProgramItem($programId, $mediaId, $afterItemId)) {
        radio_studio_json(array('ok' => false, 'error' => 'add_failed'), 400);
    }

    radio_studio_json(radio_studio_state($programId), 200);
}

radio_studio_json(array('ok' => false, 'error' => 'invalid_action'), 400);
