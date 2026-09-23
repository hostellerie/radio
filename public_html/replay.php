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
    $replayProgram = RADIO_getProgram($replay['program_id'], true);
    if ($replayProgram && !empty($replayProgram['cover_name'])) {
        $content .= '<p><img src="' . htmlspecialchars(RADIO_coverUrl('program', $replay['program_id']), ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:360px;width:100%;height:auto"></p>';
    }
    if ($replayProgram && !empty($replayProgram['host'])) {
        $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['host'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($replayProgram['host'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['broadcast_date'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . date('Y-m-d H:i', $replay['start']) . '–' . date('H:i', $replay['end']) . '<br>'
        . '<strong>' . htmlspecialchars($LANG_RADIO['available_until'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . date('Y-m-d H:i', $replay['available_until']) . '</p>';

    if ($replay['description'] !== '') {
        $content .= '<p>' . nl2br(htmlspecialchars($replay['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    $playlist = array();
    $chapters = '';
    $offset = 0;

    foreach ($replay['items'] as $item) {
        if (!RADIO_isBroadcastAvailable($item) || !RADIO_hasReadAccess($item)
            || (int) $item['duration'] < 1) {
            continue;
        }

        $duration = (int) $item['duration'];
        $playlist[] = array(
            'media_id' => (int) $item['media_id'],
            'title' => $item['title'],
            'author' => isset($item['author']) ? $item['author'] : '',
            'duration' => $duration,
            'offset' => $offset,
            'stream_url' => RADIO_mediaUrl((int) $item['media_id'], false)
        );

        $chapterLabel = trim(
            (isset($item['author']) && trim((string) $item['author']) !== ''
                ? $item['author'] . ' — '
                : '')
            . $item['title']
        );

        $chapters .= '<li><button type="button" class="radio-replay__chapter"'
            . ' data-radio-replay-chapter="' . count($playlist) - 1 . '"'
            . ' data-radio-replay-offset="' . $offset . '">'
            . '<span class="radio-replay__chapter-time">' . gmdate('H:i:s', $offset) . '</span>'
            . '<span class="radio-replay__chapter-title">'
            . htmlspecialchars($chapterLabel, ENT_QUOTES, 'UTF-8')
            . '</span></button></li>';

        $offset += $duration;
    }

    if (count($playlist) === 0) {
        $content .= '<p>' . htmlspecialchars($LANG_RADIO['replay_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
    } else {
        $playlistJson = json_encode($playlist);
        $first = $playlist[0];

        $content .= '<section class="radio-replay__player" data-radio-replay-player'
            . ' data-radio-program-id="' . (int) $replay['program_id'] . '"'
            . ' data-radio-replay-items="'
            . htmlspecialchars($playlistJson, ENT_QUOTES, 'UTF-8') . '">'
            . '<audio data-radio-replay-audio preload="metadata" src="'
            . htmlspecialchars($first['stream_url'], ENT_QUOTES, 'UTF-8') . '"></audio>'
            . '<div class="radio-replay__controls">'
            . '<button type="button" class="radio-replay__play" data-radio-replay-toggle'
            . ' data-play-label="' . htmlspecialchars($LANG_RADIO['public_listen'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-pause-label="' . htmlspecialchars($LANG_RADIO['public_pause'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($LANG_RADIO['public_listen'], ENT_QUOTES, 'UTF-8') . '</button>'
            . '<strong class="radio-replay__now" data-radio-replay-now>'
            . htmlspecialchars($first['title'], ENT_QUOTES, 'UTF-8') . '</strong>'
            . '</div>'
            . '<label class="radio-replay__progress-label">'
            . '<span class="radio-visually-hidden">' . htmlspecialchars($LANG_RADIO['replay_progress'], ENT_QUOTES, 'UTF-8') . '</span>'
            . '<input type="range" min="0" max="' . max(0, $offset) . '" value="0" step="1"'
            . ' data-radio-replay-progress aria-label="'
            . htmlspecialchars($LANG_RADIO['replay_progress'], ENT_QUOTES, 'UTF-8') . '"></label>'
            . '<div class="radio-replay__time"><span data-radio-replay-current>00:00:00</span>'
            . '<span>' . gmdate('H:i:s', $offset) . '</span></div>'
            . '<h2>' . htmlspecialchars($LANG_RADIO['replay_chapters'], ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<ol class="radio-replay__chapters">' . $chapters . '</ol>'
            . '</section>';
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
