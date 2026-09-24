<?php
define('RADIO_STUDIO_API', true);
ob_start();

require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

$GLOBALS['_RADIO_STUDIO_WARNINGS'] = array();

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    if (in_array($severity, array(E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED), true)) {
        $GLOBALS['_RADIO_STUDIO_WARNINGS'][] = array(
            'severity' => (int) $severity,
            'message' => (string) $message,
            'file' => basename((string) $file),
            'line' => (int) $line
        );
        error_log(
            'Radio Studio API PHP warning: ' . $message
            . ' @ ' . $file . ':' . (int) $line
        );
        return true;
    }

    return false;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!is_array($error)
        || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        if (function_exists('http_response_code')) {
            http_response_code(500);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    echo json_encode(array(
        'ok' => false,
        'error' => 'php_fatal',
        'message' => isset($error['message']) ? $error['message'] : '',
        'file' => isset($error['file']) ? basename($error['file']) : '',
        'line' => isset($error['line']) ? (int) $error['line'] : 0
    ));
});

function radio_studio_json($data, $status)
{
    $unexpectedOutput = '';
    if (ob_get_level() > 0) {
        $unexpectedOutput = (string) ob_get_contents();
        @ob_clean();
    }

    if ($unexpectedOutput !== '' && preg_match('/<\s*!DOCTYPE|<\s*html|<\s*body/i', $unexpectedOutput)) {
        error_log(
            'Radio Studio API discarded unexpected HTML output: '
            . substr(preg_replace('/\s+/', ' ', strip_tags($unexpectedOutput)), 0, 500)
        );
        if (is_array($data) && !isset($data['discarded_html'])) {
            $data['discarded_html'] = true;
        }
    }

    if (!is_array($data)) {
        $data = array('ok' => false, 'error' => 'invalid_response');
    }

    if (!isset($data['csrf_name'])) {
        $data['csrf_name'] = CSRF_TOKEN;
    }
    if (!isset($data['csrf_token'])) {
        $data['csrf_token'] = SEC_createToken();
    }
    if (!empty($GLOBALS['_RADIO_STUDIO_WARNINGS'])) {
        $data['php_warnings'] = $GLOBALS['_RADIO_STUDIO_WARNINGS'];
    }

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
        if (!RADIO_hasReadAccess($item)) {
            continue;
        }

        $duration = max(0, (int) $item['duration']);
        $playable = RADIO_isBroadcastAvailable($item) && $duration > 0;

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
            'playable' => $playable,
            'stream_url' => $playable ? RADIO_mediaUrl((int) $item['media_id'], false) : ''
        );

        if ($playable) {
            $offset += $duration;
        }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['studio_action'])) {
    if (!SEC_checkToken()) {
        radio_studio_json(array(
            'ok' => false,
            'error' => 'invalid_token'
        ), 403);
    }

    $studioAction = trim((string) $_POST['studio_action']);
    $itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

    if ($studioAction === 'add') {
        $mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
        $position = isset($_POST['position']) ? trim((string) $_POST['position']) : 'end';
        $currentItemId = isset($_POST['current_item_id']) ? (int) $_POST['current_item_id'] : 0;
        $afterItemId = $position === 'next' ? $currentItemId : 0;
        if (!RADIO_addProgramItem($programId, $mediaId, $afterItemId)) {
            radio_studio_json(array('ok' => false, 'error' => 'add_failed'), 400);
        }
        radio_studio_json(radio_studio_state($programId), 200);
    }

    if ($studioAction === 'remove') {
        if (!$itemId) {
            radio_studio_json(array('ok' => false, 'error' => 'missing_item_id'), 400);
        }
        if (!RADIO_removeProgramItem($itemId, $programId)) {
            radio_studio_json(array(
                'ok' => false,
                'error' => 'remove_failed',
                'item_id' => $itemId
            ), 400);
        }
        radio_studio_json(radio_studio_state($programId), 200);
    }

    if ($studioAction === 'move_up' || $studioAction === 'move_down') {
        if (!$itemId) {
            radio_studio_json(array('ok' => false, 'error' => 'missing_item_id'), 400);
        }
        $direction = $studioAction === 'move_up' ? 'up' : 'down';
        if (!RADIO_moveProgramItem($itemId, $programId, $direction)) {
            radio_studio_json(array(
                'ok' => false,
                'error' => 'move_failed',
                'item_id' => $itemId,
                'direction' => $direction
            ), 400);
        }
        radio_studio_json(radio_studio_state($programId), 200);
    }

    radio_studio_json(array('ok' => false, 'error' => 'invalid_action'), 400);
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
        'results' => $results
    ), 200);
}


radio_studio_json(array('ok' => false, 'error' => 'invalid_action'), 400);
