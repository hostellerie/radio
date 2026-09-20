<?php
require_once '../lib-common.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$download = !empty($_GET['download']);
$row = RADIO_getMedia($id, true);

if ($row === false) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

if (!RADIO_sendMedia($row, $download)) {
    header($download ? 'HTTP/1.1 403 Forbidden' : 'HTTP/1.1 404 Not Found');
    exit;
}
