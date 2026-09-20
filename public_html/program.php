<?php
require_once '../lib-common.php';
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$program = RADIO_getProgram($id, true);
$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';

if ($program === false) {
    $content = COM_showMessageText($LANG_RADIO['program_not_found'], $title);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title)));
    exit;
}

$items = RADIO_getProgramItems($id);
$content = '<div class="radio-program"><h1>' . htmlspecialchars($program['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
if (!empty($program['cover_name'])) {
    $content .= '<p><img src="' . htmlspecialchars(RADIO_coverUrl('program', $id), ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:360px;width:100%;height:auto"></p>';
}
if (!empty($program['host'])) {
    $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['host'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($program['host'], ENT_QUOTES, 'UTF-8') . '</p>';
}
if ($program['description'] !== '') {
    $content .= '<p>' . nl2br(htmlspecialchars($program['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
}
if (count($items) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['program_public_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ol>';
    foreach ($items as $item) {
        if ($item['status'] !== 'published' || !RADIO_hasReadAccess($item)) {
            continue;
        }
        $content .= '<li style="margin:0 0 1.25rem"><strong>'
            . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong><br>'
            . '<audio controls preload="none" style="width:100%;max-width:800px" src="'
            . htmlspecialchars(RADIO_mediaUrl((int) $item['media_id'], false), ENT_QUOTES, 'UTF-8')
            . '"></audio></li>';
    }
    $content .= '</ol>';
}
$content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/replay.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['replays'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $program['title'])));
