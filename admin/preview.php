<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.schedule')) {
    COM_accessLog('User tried to preview a Radio programme without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;

$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
$program = $programId > 0 ? RADIO_getProgram($programId, false) : false;

if ($program === false || (!RADIO_hasReadAccess($program) && !RADIO_hasEditAccess($program))) {
    $content = COM_showMessageText($LANG_RADIO['program_not_found'], $LANG_RADIO['program_preview']);
    COM_output(COM_createHTMLDocument($content, array(
        'pagetitle' => $LANG_RADIO['program_preview'],
        'headercode' => RADIO_publicStylesheetLink() . RADIO_publicScriptTag()
    )));
    exit;
}

$items = RADIO_getProgramItems($programId);
$playlist = array();
$chapters = '';
$offset = 0;

foreach ($items as $item) {
    if (!RADIO_isBroadcastAvailable($item)
        || !RADIO_hasReadAccess($item)
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
        . ' data-radio-replay-chapter="' . (count($playlist) - 1) . '"'
        . ' data-radio-replay-offset="' . $offset . '">'
        . '<span class="radio-replay__chapter-time">' . gmdate('H:i:s', $offset) . '</span>'
        . '<span class="radio-replay__chapter-title">'
        . htmlspecialchars($chapterLabel, ENT_QUOTES, 'UTF-8')
        . '</span></button></li>';

    $offset += $duration;
}

$content = '<div class="radio-replay radio-program-preview">';
$content .= '<p><a href="'
    . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/programs.php?program_id=' . $programId, ENT_QUOTES, 'UTF-8')
    . '">← ' . htmlspecialchars($LANG_RADIO['program_preview_back'], ENT_QUOTES, 'UTF-8') . '</a></p>';
$content .= '<h1>' . htmlspecialchars($LANG_RADIO['program_preview'], ENT_QUOTES, 'UTF-8') . ': '
    . htmlspecialchars($program['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
$content .= '<p class="radio-admin__muted">'
    . htmlspecialchars($LANG_RADIO['program_preview_help'], ENT_QUOTES, 'UTF-8') . '</p>';

if (!empty($program['host'])) {
    $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['host'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . htmlspecialchars($program['host'], ENT_QUOTES, 'UTF-8') . '</p>';
}
if (!empty($program['description'])) {
    $content .= '<p>' . nl2br(htmlspecialchars($program['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
}

if (count($playlist) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['program_preview_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $playlistJson = json_encode($playlist);
    $first = $playlist[0];

    $content .= '<section class="radio-replay__player" data-radio-replay-player'
        . ' data-radio-program-id="' . $programId . '"'
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

$content .= '</div>';

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['program_preview'] . ' - ' . $program['title'],
    'headercode' => RADIO_publicStylesheetLink() . RADIO_publicScriptTag()
)));
