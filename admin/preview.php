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

$canEdit = RADIO_hasEditAccess($program);
$classificationOptions = $canEdit ? RADIO_mediaClassificationOptions() : array(
    'categories' => array(),
    'collections' => array(),
    'tags' => array()
);
$items = RADIO_getProgramItems($programId);
$playlist = array();
$chapters = '';
$offset = 0;

foreach ($items as $item) {
    if (!RADIO_hasReadAccess($item)) {
        continue;
    }

    $duration = max(0, (int) $item['duration']);
    $playable = RADIO_isBroadcastAvailable($item) && $duration > 0;

    $playlist[] = array(
        'item_id' => (int) $item['item_id'],
        'media_id' => (int) $item['media_id'],
        'title' => $item['title'],
        'author' => isset($item['author']) ? $item['author'] : '',
        'duration' => $duration,
        'offset' => $offset,
        'playable' => $playable,
        'stream_url' => $playable ? RADIO_mediaUrl((int) $item['media_id'], false) : ''
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

    if ($playable) {
        $offset += $duration;
    }
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

$playlistJson = json_encode($playlist);
$first = count($playlist) > 0 ? $playlist[0] : false;

$content .= '<section class="radio-replay__player" data-radio-replay-player'
    . ' data-radio-program-id="' . $programId . '"'
    . ' data-radio-replay-dynamic="' . ($canEdit ? '1' : '0') . '"'
    . ' data-radio-replay-track="0"'
    . ' data-radio-replay-source="studio"'
    . ' data-radio-replay-items="'
    . htmlspecialchars($playlistJson, ENT_QUOTES, 'UTF-8') . '">'
    . '<audio data-radio-replay-audio preload="metadata"'
    . ($first ? ' src="' . htmlspecialchars($first['stream_url'], ENT_QUOTES, 'UTF-8') . '"' : '')
    . '></audio>'
    . '<div class="radio-replay__controls">'
    . '<button type="button" class="radio-replay__play" data-radio-replay-toggle'
    . ' data-play-label="' . htmlspecialchars($LANG_RADIO['public_listen'], ENT_QUOTES, 'UTF-8') . '"'
    . ' data-pause-label="' . htmlspecialchars($LANG_RADIO['public_pause'], ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['public_listen'], ENT_QUOTES, 'UTF-8') . '</button>'
    . '<strong class="radio-replay__now" data-radio-replay-now>'
    . ($first ? htmlspecialchars($first['title'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($LANG_RADIO['program_preview_empty'], ENT_QUOTES, 'UTF-8'))
    . '</strong>'
    . '</div>'
    . '<label class="radio-replay__progress-label">'
    . '<span class="radio-visually-hidden">' . htmlspecialchars($LANG_RADIO['replay_progress'], ENT_QUOTES, 'UTF-8') . '</span>'
    . '<input type="range" min="0" max="' . max(0, $offset) . '" value="0" step="1"'
    . ' data-radio-replay-progress aria-label="'
    . htmlspecialchars($LANG_RADIO['replay_progress'], ENT_QUOTES, 'UTF-8') . '"></label>'
    . '<div class="radio-replay__time"><span data-radio-replay-current>00:00:00</span>'
    . '<span data-radio-replay-total>' . gmdate('H:i:s', $offset) . '</span></div>'
    . '<h2>' . htmlspecialchars($LANG_RADIO['replay_chapters'], ENT_QUOTES, 'UTF-8') . '</h2>'
    . '<ol class="radio-replay__chapters">' . $chapters . '</ol>'
    . '</section>';

if ($canEdit) {
    $typeItems = array();
    foreach (array('music','podcast','interview','show','chronicle','jingle','announcement','promo') as $type) {
        $typeItems[$type] = $LANG_RADIO['type_' . $type];
    }

    $studioToken = SEC_createToken();
    $content .= '<section class="radio-program-picker radio-studio" data-radio-studio'
        . ' data-radio-program-id="' . $programId . '"'
        . ' data-radio-studio-endpoint="'
        . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/studio.php', ENT_QUOTES, 'UTF-8') . '"'
        . ' data-radio-csrf-name="' . htmlspecialchars(CSRF_TOKEN, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-radio-csrf-token="' . htmlspecialchars($studioToken, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-empty-label="' . htmlspecialchars($LANG_RADIO['program_media_search_empty'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-play-next-label="' . htmlspecialchars($LANG_RADIO['studio_play_next'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-add-end-label="' . htmlspecialchars($LANG_RADIO['studio_add_end'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-searching-label="' . htmlspecialchars($LANG_RADIO['studio_searching'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-adding-label="' . htmlspecialchars($LANG_RADIO['studio_adding'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-added-label="' . htmlspecialchars($LANG_RADIO['studio_added'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-error-label="' . htmlspecialchars($LANG_RADIO['studio_error'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-buffer-loading-label="' . htmlspecialchars($LANG_RADIO['studio_buffer_loading'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-buffer-ready-label="' . htmlspecialchars($LANG_RADIO['studio_buffer_ready'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-buffer-reserve-label="' . htmlspecialchars($LANG_RADIO['studio_buffer_reserve'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-queue-empty-label="' . htmlspecialchars($LANG_RADIO['studio_queue_empty'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-queue-not-ready-label="' . htmlspecialchars($LANG_RADIO['studio_queue_not_ready'], ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="radio-admin__panel-heading"><h2>'
        . htmlspecialchars($LANG_RADIO['studio_title'], ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<span class="radio-studio__status-group">'
        . '<span class="radio-admin__muted" data-radio-studio-buffer></span>'
        . '<span class="radio-admin__muted" data-radio-studio-status></span>'
        . '</span></div>'
        . '<p class="radio-admin__muted">' . htmlspecialchars($LANG_RADIO['studio_help'], ENT_QUOTES, 'UTF-8') . '</p>'
        . '<div class="radio-studio__queue-panel">'
        . '<h3>' . htmlspecialchars($LANG_RADIO['studio_queue_title'], ENT_QUOTES, 'UTF-8') . '</h3>'
        . '<div data-radio-studio-queue></div>'
        . '</div>'
        . '<form class="radio-program-picker__filters" data-radio-studio-search>'
        . '<div class="radio-program-picker__filter-grid">'
        . '<label>' . htmlspecialchars($LANG_RADIO['filter_search'], ENT_QUOTES, 'UTF-8')
        . '<input type="search" name="q" placeholder="'
        . htmlspecialchars($LANG_RADIO['program_media_search_placeholder'], ENT_QUOTES, 'UTF-8') . '"></label>'
        . '<label>' . htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8')
        . '<select name="type">' . RADIO_adminFilterSelectOptions($typeItems, '', $LANG_RADIO['filter_all']) . '</select></label>'
        . '<label>' . htmlspecialchars($LANG_RADIO['category'], ENT_QUOTES, 'UTF-8')
        . '<select name="category">' . RADIO_adminFilterSelectOptions($classificationOptions['categories'], '', $LANG_RADIO['filter_all']) . '</select></label>'
        . '<label>' . htmlspecialchars($LANG_RADIO['collection'], ENT_QUOTES, 'UTF-8')
        . '<select name="collection">' . RADIO_adminFilterSelectOptions($classificationOptions['collections'], '', $LANG_RADIO['filter_all']) . '</select></label>'
        . '<label>' . htmlspecialchars($LANG_RADIO['tags'], ENT_QUOTES, 'UTF-8')
        . '<select name="tag">' . RADIO_adminFilterSelectOptions($classificationOptions['tags'], '', $LANG_RADIO['filter_all']) . '</select></label>'
        . '</div>'
        . '<div class="radio-admin__toolbar"><button class="radio-admin__button radio-admin__button--primary" type="submit">'
        . htmlspecialchars($LANG_RADIO['program_media_search_button'], ENT_QUOTES, 'UTF-8') . '</button></div>'
        . '</form>'
        . '<div class="radio-program-picker__results" data-radio-studio-results></div>'
        . '</section>';
}


$content .= '</div>';

$studioScript = '';
if ($canEdit) {
    $studioPath = !empty($_CONF['path_admin'])
        ? rtrim($_CONF['path_admin'], '/\\') . '/plugins/radio/radio-studio.js'
        : '';
    if ($studioPath === '' || !is_file($studioPath)) {
        $studioPath = rtrim($_CONF['path'], '/\\') . '/plugins/radio/admin/radio-studio.js';
    }
    $studioScript = '<script defer src="'
        . htmlspecialchars(rtrim($_CONF['site_admin_url'], '/') . '/plugins/radio/radio-studio.js', ENT_QUOTES, 'UTF-8')
        . '?v=' . rawurlencode(RADIO_assetVersion($studioPath)) . '"></script>' . "\n";
}

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['program_preview'] . ' - ' . $program['title'],
    'headercode' => RADIO_adminStylesheetLink()
        . RADIO_publicStylesheetLink()
        . RADIO_publicScriptTag()
        . $studioScript
)));
