<?php
/**
 * Radio Studio real-time stream endpoint.
 *
 * This endpoint intentionally bypasses admin/auth.inc.php. Geeklog is fully
 * bootstrapped by lib-common.php and Studio authorization is checked explicitly
 * with SEC_hasRights() plus programme ACLs. Keeping this endpoint small prevents
 * themed Geeklog error pages from leaking into the browser's JSON control path.
 */

define('RADIO_STUDIO_STREAM_API', true);
ob_start();

require_once dirname(__FILE__) . '/../../../lib-common.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

require_once dirname(__FILE__) . '/../lib/studio-log.inc.php';
require_once dirname(__FILE__) . '/../lib/studio-output.inc.php';
require_once dirname(__FILE__) . '/../lib/studio-live.inc.php';

RADIO_studioInstallFatalLogger();

register_shutdown_function(function () {
    $error = error_get_last();
    if (!is_array($error)
        || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        return;
    }

    RADIO_studioLog('stream_api.fatal', array(
        'message' => isset($error['message']) ? $error['message'] : '',
        'file' => isset($error['file']) ? $error['file'] : '',
        'line' => isset($error['line']) ? (int) $error['line'] : 0
    ), 'ERROR');

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
        'file' => isset($error['file']) ? basename((string) $error['file']) : '',
        'line' => isset($error['line']) ? (int) $error['line'] : 0
    ));
});

function radio_studio_stream_json($data, $status)
{
    if (ob_get_level() > 0) {
        @ob_clean();
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

    $json = json_encode($data);
    if ($json === false) {
        $status = 500;
        $json = '{"ok":false,"error":"json_encode_failed"}';
    }

    if (function_exists('http_response_code')) {
        http_response_code((int) $status);
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    echo $json;
    exit;
}

/**
 * Geeklog's normal one-time token also checks the creation URL. Studio AJAX
 * rotates its token through this endpoint, while the browser referrer remains
 * studio.php, so the URL comparison cannot be used here. Preserve the token
 * owner, expiry and one-time guarantees without duplicating any other auth.
 */
function radio_studio_stream_check_token()
{
    global $_TABLES, $_USER;

    $token = isset($_POST[CSRF_TOKEN]) ? trim((string) $_POST[CSRF_TOKEN]) : '';
    if ($token === '') {
        return false;
    }

    $result = DB_query(
        "SELECT token,created,owner_id,ttl FROM {$_TABLES['tokens']} WHERE token='"
        . DB_escapeString($token) . "'"
    );
    if (DB_error() || DB_numRows($result) !== 1) {
        return false;
    }

    $row = DB_fetchArray($result);
    DB_delete($_TABLES['tokens'], 'token', $token);

    $uid = isset($_USER['uid']) ? (int) $_USER['uid'] : 1;
    if ($uid !== (int) $row['owner_id']) {
        return false;
    }

    $ttl = isset($row['ttl']) ? (int) $row['ttl'] : 0;
    $created = isset($row['created']) ? strtotime($row['created']) : false;

    return $ttl <= 0 || ($created !== false && ($created + $ttl) >= time());
}

if (!SEC_hasRights('radio.schedule')) {
    radio_studio_stream_json(array('ok' => false, 'error' => 'access_denied'), 403);
}

$programId = isset($_REQUEST['program_id']) ? (int) $_REQUEST['program_id'] : 0;
$program = $programId > 0 ? RADIO_getProgram($programId, false) : false;
if ($program === false || !RADIO_hasEditAccess($program)) {
    radio_studio_stream_json(array('ok' => false, 'error' => 'access_denied'), 403);
}

$action = isset($_POST['studio_action'])
    ? trim((string) $_POST['studio_action'])
    : (isset($_GET['action']) ? trim((string) $_GET['action']) : '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'live_status') {
    radio_studio_stream_json(array(
        'ok' => true,
        'youtube_live' => RADIO_studioYoutubePublicStatus()
    ), 200);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action === '') {
    radio_studio_stream_json(array('ok' => false, 'error' => 'invalid_request'), 400);
}

if (!radio_studio_stream_check_token()) {
    radio_studio_stream_json(array('ok' => false, 'error' => 'invalid_token'), 403);
}

$uid = isset($_USER['uid']) ? (int) $_USER['uid'] : 0;
RADIO_studioLog('stream_api.action', array(
    'action' => $action,
    'program_id' => $programId,
    'uid' => $uid
));

if ($action === 'recording_start') {
    $error = '';
    $recording = RADIO_studioRecordingStart(
        $programId,
        isset($_POST['mime_type']) ? (string) $_POST['mime_type'] : '',
        $uid,
        $error
    );
    radio_studio_stream_json(
        $recording === false
            ? array('ok' => false, 'error' => $error)
            : array('ok' => true, 'recording' => $recording),
        $recording === false ? 400 : 200
    );
}

if ($action === 'recording_chunk') {
    $upload = isset($_FILES['chunk']) && is_array($_FILES['chunk']) ? $_FILES['chunk'] : array();
    if (empty($upload) || !isset($upload['error']) || (int) $upload['error'] !== UPLOAD_ERR_OK) {
        radio_studio_stream_json(array('ok' => false, 'error' => 'studio_recording_chunk_invalid'), 400);
    }

    $error = '';
    $recording = RADIO_studioRecordingAppend(
        isset($_POST['session_id']) ? (string) $_POST['session_id'] : '',
        isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '',
        isset($upload['size']) ? (int) $upload['size'] : 0,
        isset($_POST['chunk_index']) ? (int) $_POST['chunk_index'] : -1,
        $uid,
        $error
    );
    radio_studio_stream_json(
        $recording === false
            ? array('ok' => false, 'error' => $error)
            : array('ok' => true, 'recording' => $recording),
        $recording === false ? 400 : 200
    );
}

if ($action === 'recording_stop') {
    $error = '';
    $recording = RADIO_studioRecordingStop(
        isset($_POST['session_id']) ? (string) $_POST['session_id'] : '',
        $uid,
        $error
    );
    radio_studio_stream_json(
        $recording === false
            ? array('ok' => false, 'error' => $error)
            : array('ok' => true, 'recording' => $recording),
        $recording === false ? 400 : 200
    );
}

if ($action === 'recording_abort') {
    $ok = RADIO_studioRecordingAbort(
        isset($_POST['session_id']) ? (string) $_POST['session_id'] : '',
        $uid
    );
    radio_studio_stream_json(array(
        'ok' => (bool) $ok
    ), $ok ? 200 : 400);
}

if ($action === 'youtube_live_start') {
    $error = '';
    $live = RADIO_studioYoutubeStart(
        $programId,
        isset($_POST['mime_type']) ? (string) $_POST['mime_type'] : '',
        $uid,
        $error
    );
    radio_studio_stream_json(
        $live === false
            ? array('ok' => false, 'error' => $error)
            : array('ok' => true, 'youtube_live' => RADIO_studioYoutubePublicStatus()),
        $live === false ? 400 : 200
    );
}

if ($action === 'youtube_live_chunk') {
    $upload = isset($_FILES['chunk']) && is_array($_FILES['chunk']) ? $_FILES['chunk'] : array();
    if (empty($upload) || !isset($upload['error']) || (int) $upload['error'] !== UPLOAD_ERR_OK) {
        radio_studio_stream_json(array('ok' => false, 'error' => 'studio_youtube_chunk_invalid'), 400);
    }

    $error = '';
    $live = RADIO_studioYoutubeAppend(
        isset($_POST['session_id']) ? (string) $_POST['session_id'] : '',
        isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '',
        isset($upload['size']) ? (int) $upload['size'] : 0,
        isset($_POST['chunk_index']) ? (int) $_POST['chunk_index'] : -1,
        $uid,
        $error
    );
    radio_studio_stream_json(
        $live === false
            ? array('ok' => false, 'error' => $error)
            : array('ok' => true, 'youtube_live' => RADIO_studioYoutubePublicStatus()),
        $live === false ? 400 : 200
    );
}

if ($action === 'youtube_live_metadata') {
    $error = '';
    $ok = RADIO_studioYoutubeUpdateMetadata(
        isset($_POST['session_id']) ? (string) $_POST['session_id'] : '',
        isset($_POST['track_title']) ? (string) $_POST['track_title'] : '',
        $uid,
        $error
    );
    radio_studio_stream_json(
        !$ok
            ? array('ok' => false, 'error' => $error)
            : array('ok' => true, 'youtube_live' => RADIO_studioYoutubePublicStatus()),
        !$ok ? 400 : 200
    );
}

if ($action === 'youtube_live_stop') {
    $error = '';
    $live = RADIO_studioYoutubeRequestStop(
        isset($_POST['session_id']) ? (string) $_POST['session_id'] : '',
        $uid,
        $error
    );
    radio_studio_stream_json(
        $live === false
            ? array('ok' => false, 'error' => $error)
            : array('ok' => true, 'youtube_live' => RADIO_studioYoutubePublicStatus()),
        $live === false ? 400 : 200
    );
}

radio_studio_stream_json(array('ok' => false, 'error' => 'unknown_action'), 400);
