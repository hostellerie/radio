<?php
require_once '../lib-common.php';
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$scheduleId = isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : 0;
$start = isset($_GET['start']) ? (int) $_GET['start'] : 0;
$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';

if ($scheduleId > 0 && $start > 0) {
    $replay = RADIO_getReplay($scheduleId, $start);
    if ($replay === false) {
        $content = COM_showMessageText($LANG_RADIO['replay_not_available'], $LANG_RADIO['replays']);
        COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['replays'])));
        exit;
    }

    $content = '<div class="radio-replay"><h1>' . htmlspecialchars($replay['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
    $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['broadcast_date'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . date('Y-m-d H:i', $replay['start']) . '–' . date('H:i', $replay['end']) . '<br>'
        . '<strong>' . htmlspecialchars($LANG_RADIO['available_until'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . date('Y-m-d H:i', $replay['available_until']) . '</p>';

    if ($replay['description'] !== '') {
        $content .= '<p>' . nl2br(htmlspecialchars($replay['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    $published = 0;
    foreach ($replay['items'] as $item) {
        if ($item['status'] !== 'published') {
            continue;
        }
        $published++;
        $content .= '<article style="margin:0 0 1.25rem"><strong>'
            . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong><br>'
            . '<audio controls preload="none" style="width:100%;max-width:800px" src="'
            . htmlspecialchars(RADIO_mediaUrl((int) $item['media_id'], false), ENT_QUOTES, 'UTF-8')
            . '"></audio></article>';
    }

    if ($published === 0) {
        $content .= '<p>' . htmlspecialchars($LANG_RADIO['replay_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/replay.php', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_RADIO['back_to_replays'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $replay['title'])));
    exit;
}

$replays = RADIO_getReplayOccurrences(50, time());
$content = '<div class="radio-replays"><h1>' . htmlspecialchars($LANG_RADIO['replays'], ENT_QUOTES, 'UTF-8') . '</h1>';

if (count($replays) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['replays_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ul>';
    foreach ($replays as $replay) {
        $content .= '<li style="margin:0 0 1rem"><a href="'
            . htmlspecialchars($replay['url'], ENT_QUOTES, 'UTF-8') . '"><strong>'
            . htmlspecialchars($replay['title'], ENT_QUOTES, 'UTF-8') . '</strong></a><br><small>'
            . date('Y-m-d H:i', $replay['start']) . '–' . date('H:i', $replay['end'])
            . '</small></li>';
    }
    $content .= '</ul>';
}
$content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['replays'] . ' - ' . $title)));
