<?php
require_once '../lib-common.php';
RADIO_requirePublicAccess();

$type = isset($_GET['type']) && $_GET['type'] === 'program' ? 'program' : 'media';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id < 1 || !RADIO_sendCover($type, $id)) {
    header('HTTP/1.1 404 Not Found');
    exit;
}
