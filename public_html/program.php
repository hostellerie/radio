<?php
require_once '../lib-common.php';
RADIO_requirePublicAccess();
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$program = RADIO_getProgram($id, true);
$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';

if ($program === false) {
    $content = COM_showMessageText($LANG_RADIO['program_not_found'], $title);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title)));
    exit;
}

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

$now = time();
$upcoming = RADIO_getProgramUpcomingOccurrences($id, 5, $now);
if (!empty($upcoming)) {
    $content .= '<section class="radio-program__broadcasts"><h2>'
        . htmlspecialchars($LANG_RADIO['program_next_broadcasts'], ENT_QUOTES, 'UTF-8')
        . '</h2><ul>';

    foreach ($upcoming as $occurrence) {
        $content .= '<li><strong>'
            . date('Y-m-d H:i', $occurrence['start'])
            . '</strong>–' . date('H:i', $occurrence['end']) . '</li>';
    }

    $content .= '</ul></section>';
}

$replays = RADIO_getProgramReplayOccurrences($id, 5, $now);
if (!empty($replays)) {
    $content .= '<section class="radio-program__replays"><h2>'
        . htmlspecialchars($LANG_RADIO['program_available_replays'], ENT_QUOTES, 'UTF-8')
        . '</h2><ul>';

    foreach ($replays as $replay) {
        $content .= '<li><a href="'
            . htmlspecialchars($replay['url'], ENT_QUOTES, 'UTF-8') . '"><strong>'
            . date('Y-m-d H:i', $replay['start']) . '</strong>–'
            . date('H:i', $replay['end']) . '</a><br><small>'
            . htmlspecialchars($LANG_RADIO['available_until'], ENT_QUOTES, 'UTF-8')
            . ' ' . date('Y-m-d H:i', $replay['available_until'])
            . '</small></li>';
    }

    $content .= '</ul></section>';
}

$content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/replay.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['replays'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $program['title'])));
