<?php
require_once '../lib-common.php';
RADIO_requirePublicAccess();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$download = !empty($_GET['download']);
$row = RADIO_getMedia($id, false);

if ($row !== false) {
    $published = isset($row['status']) && $row['status'] === 'published';
    if ($published) {
        if (!RADIO_hasReadAccess($row)) {
            $row = false;
        }
    } elseif (!RADIO_hasEditAccess($row)) {
        $row = false;
    }
}

if ($row === false) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

if ($download && !RADIO_downloadAllowed($row)) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}
if ($download) {
    RADIO_recordStatEvent($id, 0, 'download', 'download', 0);
}

if (!RADIO_sendMedia($row, $download)) {
    header($download ? 'HTTP/1.1 403 Forbidden' : 'HTTP/1.1 404 Not Found');
    exit;
}
