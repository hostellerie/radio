<?php
require_once '../lib-common.php';
global $_TABLES, $_CONF, $_RADIO_CONF, $LANG_RADIO;

$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id > 0) {
    $row = RADIO_getMedia($id, true);
    if ($row === false) {
        $content = COM_showMessageText($LANG_RADIO['media_not_found'], $title);
        COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title)));
        exit;
    }

    DB_query("UPDATE {$_TABLES['radio_media']} SET hits=hits+1 WHERE media_id=" . $id);

    $content = '<div class="radio-item"><h1>' . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
    if ($row['description'] !== '') {
        $content .= '<p>' . nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    $content .= '<p><audio controls preload="metadata" style="width:100%;max-width:800px" src="'
        . htmlspecialchars(RADIO_mediaUrl($id, false), ENT_QUOTES, 'UTF-8') . '"></audio></p>';

    if (!empty($_RADIO_CONF['allow_downloads']) && !empty($row['allow_download'])) {
        $content .= '<p><a href="' . htmlspecialchars(RADIO_mediaUrl($id, true), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($LANG_RADIO['download'], ENT_QUOTES, 'UTF-8') . '</a></p>';
    }
    $content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $row['title'])));
    exit;
}

$media = RADIO_getMediaList(50, true);
$content = '<div class="radio-catalogue"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
if (count($media) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['public_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<div class="radio-list">';
    foreach ($media as $row) {
        $itemUrl = $_CONF['site_url'] . '/radio/index.php?id=' . (int) $row['media_id'];
        $content .= '<article style="margin:0 0 1.5rem"><h2><a href="'
            . htmlspecialchars($itemUrl, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '</a></h2>';
        if ($row['description'] !== '') {
            $content .= '<p>' . nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        $content .= '<audio controls preload="none" style="width:100%;max-width:800px" src="'
            . htmlspecialchars(RADIO_mediaUrl((int) $row['media_id'], false), ENT_QUOTES, 'UTF-8') . '"></audio></article>';
    }
    $content .= '</div>';
}
$content .= '</div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title)));
