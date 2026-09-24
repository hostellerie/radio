<?php
require_once '../lib-common.php';
RADIO_requirePublicAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

if (!RADIO_statsEnabled()) {
    http_response_code(204);
    exit;
}

$origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
if ($origin !== '') {
    global $_CONF;
    $expected = parse_url($_CONF['site_url']);
    $actual = parse_url($origin);
    if (!is_array($expected) || !is_array($actual)
        || strtolower(isset($expected['host']) ? $expected['host'] : '') !== strtolower(isset($actual['host']) ? $actual['host'] : '')) {
        header('HTTP/1.1 403 Forbidden');
        exit;
    }
}

$mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
$programId = isset($_POST['program_id']) ? (int) $_POST['program_id'] : 0;
$eventType = isset($_POST['event_type']) ? (string) $_POST['event_type'] : 'play';
$source = isset($_POST['source']) ? (string) $_POST['source'] : 'catalogue';
$seconds = isset($_POST['seconds']) ? (int) $_POST['seconds'] : 0;

if ($mediaId < 1) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

RADIO_recordStatEvent($mediaId, $programId, $eventType, $source, $seconds);
http_response_code(204);
