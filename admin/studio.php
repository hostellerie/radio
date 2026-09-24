<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.schedule')) {
    COM_accessLog('User tried to access the Radio studio without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;

function radio_studio_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$programId = isset($_REQUEST['program_id']) ? (int) $_REQUEST['program_id'] : 0;
$program = $programId > 0 ? RADIO_getProgram($programId, false) : false;

if ($program === false || (!RADIO_hasReadAccess($program) && !RADIO_hasEditAccess($program))) {
    $content = COM_showMessageText($LANG_RADIO['program_not_found'], $LANG_RADIO['studio_title']);
    COM_output(COM_createHTMLDocument($content, array(
        'pagetitle' => $LANG_RADIO['studio_title'],
        'headercode' => RADIO_adminStylesheetLink() . RADIO_publicStylesheetLink() . RADIO_publicScriptTag()
    )));
    exit;
}

$canEdit = RADIO_hasEditAccess($program);
$message = '';

/*
 * Studio 0.5.1 deliberately uses normal same-page POST actions.
 *
 * This keeps playlist editing inside Geeklog's native form/CSRF lifecycle and
 * avoids a second AJAX mutation layer. The page reload after a mutation is
 * intentional: the saved playlist, player and search results always come from
 * one server-rendered state.
 */
if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['studio_action'])) {
    if (!SEC_checkToken()) {
        exit;
    }

    $action = trim((string) $_POST['studio_action']);
    $itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    $ok = false;

    if ($action === 'remove' && $itemId > 0) {
        $ok = RADIO_removeProgramItem($itemId, $programId);
    } elseif (($action === 'move_up' || $action === 'move_down') && $itemId > 0) {
        $ok = RADIO_moveProgramItem(
            $itemId,
            $programId,
            $action === 'move_up' ? 'up' : 'down'
        );
    } elseif ($action === 'add') {
        $mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
        $position = isset($_POST['position']) ? trim((string) $_POST['position']) : 'end';
        $currentItemId = isset($_POST['current_item_id']) ? (int) $_POST['current_item_id'] : 0;
        $afterItemId = $position === 'next' ? $currentItemId : 0;
        $ok = $mediaId > 0 && RADIO_addProgramItem($programId, $mediaId, $afterItemId);
    }

    if ($ok) {
        COM_redirect(
            $_CONF['site_admin_url'] . '/plugins/radio/studio.php?program_id='
            . $programId . '&updated=1'
        );
    }

    $message = COM_showMessageText($LANG_RADIO['studio_error'], $LANG_RADIO['studio_title']);
}

if (isset($_GET['updated']) && (int) $_GET['updated'] === 1) {
    $message = COM_showMessageText($LANG_RADIO['studio_updated'], $LANG_RADIO['studio_title']);
}

$filters = array();
foreach (array('q','type','category','collection','tag') as $key) {
    $filters[$key] = isset($_GET[$key]) ? trim((string) $_GET[$key]) : '';
}

$classificationOptions = $canEdit ? RADIO_mediaClassificationOptions() : array(
    'categories' => array(),
    'collections' => array(),
    'tags' => array()
);

$searchFilters = array(
    'q' => $filters['q'],
    'type' => $filters['type'],
    'category' => $filters['category'],
    'collection' => $filters['collection'],
    'tag' => $filters['tag'],
    'status' => 'published',
    'broadcast' => '1'
);
$searchRows = $canEdit ? RADIO_getMediaList(60, false, 'title', 'asc', $searchFilters) : array();

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
        'media_type' => isset($item['media_type']) ? $item['media_type'] : '',
        'duration' => $duration,
        'offset' => 0,
        'transition_overlap' => 0,
        'playable' => $playable,
        'stream_url' => $playable ? RADIO_mediaUrl((int) $item['media_id'], false) : ''
    );

}

$transitionMode = RADIO_transitionMode();
$crossfadeSeconds = RADIO_crossfadeSeconds();
$offset = 0;
for ($i = 0; $i < count($playlist); $i++) {
    $playlist[$i]['offset'] = $offset;
    $overlap = 0;
    if ($i + 1 < count($playlist)
        && !empty($playlist[$i]['playable'])
        && !empty($playlist[$i + 1]['playable'])) {
        $overlap = RADIO_transitionOverlapFor(
            $playlist[$i],
            $playlist[$i + 1],
            $transitionMode,
            $crossfadeSeconds
        );
    }
    $playlist[$i]['transition_overlap'] = $overlap;
    if (!empty($playlist[$i]['playable'])) {
        $offset += max(0, (int) $playlist[$i]['duration'] - $overlap);
    }
}

$chapters = '';
foreach ($playlist as $chapterIndex => $chapterItem) {
    $chapterLabel = trim(
        (!empty($chapterItem['author']) ? $chapterItem['author'] . ' — ' : '')
        . $chapterItem['title']
    );
    $chapterOffset = (int) $chapterItem['offset'];
    $chapters .= '<li><button type="button" class="radio-replay__chapter"'
        . ' data-radio-replay-chapter="' . $chapterIndex . '"'
        . ' data-radio-replay-item-id="' . (int) $chapterItem['item_id'] . '"'
        . ' data-radio-replay-offset="' . $chapterOffset . '">'
        . '<span class="radio-replay__chapter-time">' . gmdate('H:i:s', $chapterOffset) . '</span>'
        . '<span class="radio-replay__chapter-title">' . radio_studio_h($chapterLabel) . '</span>'
        . '</button></li>';
}

$playlistJson = json_encode($playlist);
$first = count($playlist) > 0 ? $playlist[0] : false;
$token = $canEdit ? SEC_createToken() : '';

$studioAttrs = '';
if ($canEdit) {
    $studioAttrs = ' data-radio-studio'
        . ' data-radio-program-id="' . $programId . '"'
        . ' data-radio-studio-endpoint="'
        . radio_studio_h($_CONF['site_admin_url'] . '/plugins/radio/studio-api.php') . '"'
        . ' data-radio-studio-mutation-endpoint="'
        . radio_studio_h($_CONF['site_admin_url'] . '/plugins/radio/studio-api.php?program_id=' . $programId) . '"'
        . ' data-radio-csrf-name="' . radio_studio_h(CSRF_TOKEN) . '"'
        . ' data-radio-csrf-token="' . radio_studio_h($token) . '"'
        . ' data-empty-label="' . radio_studio_h($LANG_RADIO['program_media_search_empty']) . '"'
        . ' data-play-next-label="' . radio_studio_h($LANG_RADIO['studio_play_next']) . '"'
        . ' data-add-end-label="' . radio_studio_h($LANG_RADIO['studio_add_end']) . '"'
        . ' data-searching-label="' . radio_studio_h($LANG_RADIO['studio_searching']) . '"'
        . ' data-adding-label="' . radio_studio_h($LANG_RADIO['studio_adding']) . '"'
        . ' data-added-label="' . radio_studio_h($LANG_RADIO['studio_added']) . '"'
        . ' data-error-label="' . radio_studio_h($LANG_RADIO['studio_error']) . '"'
        . ' data-buffer-loading-label="' . radio_studio_h($LANG_RADIO['studio_buffer_loading']) . '"'
        . ' data-buffer-ready-label="' . radio_studio_h($LANG_RADIO['studio_buffer_ready']) . '"'
        . ' data-buffer-reserve-label="' . radio_studio_h($LANG_RADIO['studio_buffer_reserve']) . '"'
        . ' data-queue-empty-label="' . radio_studio_h($LANG_RADIO['studio_queue_empty']) . '"'
        . ' data-queue-not-ready-label="' . radio_studio_h($LANG_RADIO['studio_queue_not_ready']) . '"'
        . ' data-move-up-label="' . radio_studio_h($LANG_RADIO['move_up']) . '"'
        . ' data-move-down-label="' . radio_studio_h($LANG_RADIO['move_down']) . '"'
        . ' data-remove-label="' . radio_studio_h($LANG_RADIO['remove']) . '"'
        . ' data-broadcast-start-label="' . radio_studio_h($LANG_RADIO['studio_broadcast_start']) . '"'
        . ' data-broadcast-stop-label="' . radio_studio_h($LANG_RADIO['studio_broadcast_stop']) . '"'
        . ' data-broadcast-active-label="' . radio_studio_h($LANG_RADIO['studio_broadcast_active']) . '"'
        . ' data-broadcast-started-label="' . radio_studio_h($LANG_RADIO['studio_broadcast_started']) . '"'
        . ' data-broadcast-stopped-label="' . radio_studio_h($LANG_RADIO['studio_broadcast_stopped']) . '"'
        . ' data-broadcast-failed-label="' . radio_studio_h($LANG_RADIO['studio_broadcast_failed']) . '"';
}

$content = '<div class="radio-replay radio-program-preview"' . $studioAttrs . '>';
$content .= '<p><a href="'
    . radio_studio_h($_CONF['site_admin_url'] . '/plugins/radio/programs.php?program_id=' . $programId)
    . '">← ' . radio_studio_h($LANG_RADIO['studio_back_to_metadata']) . '</a></p>';
$content .= '<h1>' . radio_studio_h($LANG_RADIO['studio_title']) . ': '
    . radio_studio_h($program['title']) . '</h1>';
$content .= '<p class="radio-admin__muted">'
    . radio_studio_h(isset($LANG_RADIO['studio_help']) ? $LANG_RADIO['studio_help'] : $LANG_RADIO['studio_page_help_simple']) . '</p>';
$content .= $message;

$content .= '<section class="radio-replay__player" data-radio-replay-player'
    . ' data-radio-program-id="' . $programId . '"'
    . ' data-radio-replay-dynamic="0"'
    . ' data-radio-replay-track="0"'
    . ' data-radio-replay-source="studio"'
    . ' data-radio-transition-mode="' . radio_studio_h($transitionMode) . '"'
    . ' data-radio-crossfade-seconds="' . (int) $crossfadeSeconds . '"'
    . ' data-radio-replay-items="' . radio_studio_h($playlistJson) . '">'
    . '<audio data-radio-replay-audio preload="metadata"'
    . ($first ? ' src="' . radio_studio_h($first['stream_url']) . '"' : '')
    . '></audio>'
    . '<div class="radio-replay__controls">'
    . '<button type="button" class="radio-replay__play" data-radio-replay-toggle'
    . ' data-play-label="' . radio_studio_h($LANG_RADIO['public_listen']) . '"'
    . ' data-pause-label="' . radio_studio_h($LANG_RADIO['public_pause']) . '">'
    . radio_studio_h($LANG_RADIO['public_listen']) . '</button>'
    . '<strong class="radio-replay__now" data-radio-replay-now>'
    . ($first ? radio_studio_h($first['title']) : radio_studio_h($LANG_RADIO['program_preview_empty']))
    . '</strong>'
    . '</div>'
    . '<label class="radio-replay__progress-label">'
    . '<span class="radio-visually-hidden">' . radio_studio_h($LANG_RADIO['replay_progress']) . '</span>'
    . '<input type="range" min="0" max="' . max(0, $offset) . '" value="0" step="1"'
    . ' data-radio-replay-progress aria-label="' . radio_studio_h($LANG_RADIO['replay_progress']) . '"></label>'
    . '<div class="radio-replay__time"><span data-radio-replay-current>00:00:00</span>'
    . '<span data-radio-replay-total>' . gmdate('H:i:s', $offset) . '</span></div>'
    . '<ol class="radio-replay__chapters radio-visually-hidden" aria-hidden="true">' . $chapters . '</ol>'
    . '</section>';

if ($canEdit) {
    $content .= '<div class="radio-studio__player-meta">'
        . '<span class="radio-studio__buffer-status" data-radio-studio-buffer></span>'
        . '<span class="radio-admin__muted radio-studio__action-status" data-radio-studio-status></span>'
        . '</div>'
        . '<div class="radio-studio__broadcast-bar">'
        . '<button type="button" class="radio-admin__button radio-studio__broadcast-button"'
        . ' data-radio-studio-broadcast>'
        . radio_studio_h($LANG_RADIO['studio_broadcast_start']) . '</button>'
        . '<strong class="radio-studio__broadcast-state" data-radio-studio-broadcast-state></strong>'
        . '</div>';
}

$content .= '<section class="radio-studio__queue-panel"><h2>'
    . radio_studio_h($LANG_RADIO['studio_queue_title']) . '</h2>';

$content .= '<div data-radio-studio-queue>';
if (count($playlist) === 0) {
    $content .= '<p class="radio-admin__muted">' . radio_studio_h($LANG_RADIO['studio_queue_empty']) . '</p>';
} else {
    $content .= '<ol class="radio-studio__queue">';
    foreach ($playlist as $index => $item) {
        $label = trim(($item['author'] !== '' ? $item['author'] . ' — ' : '') . $item['title']);
        $typeLabel = RADIO_adminMediaTypeLabel($item['media_type']);

        $durationLabel = $item['duration'] > 0
            ? gmdate('H:i:s', $item['duration'])
            : '--:--';

        $content .= '<li class="radio-studio__queue-item'
            . (!$item['playable'] ? ' is-not-ready' : '') . '">'
            . '<span class="radio-admin__badge radio-studio__queue-type">'
            . radio_studio_h($typeLabel) . '</span>'
            . '<span class="radio-studio__queue-duration">'
            . radio_studio_h($durationLabel) . '</span>'
            . '<button type="button" class="radio-studio__queue-title radio-studio__queue-seek"'
            . ' data-radio-studio-seek-item="' . (int) $item['item_id'] . '">'
            . radio_studio_h($label) . '</button>';

        if (!$item['playable']) {
            $content .= '<span class="radio-admin__muted radio-studio__queue-state">'
                . radio_studio_h($LANG_RADIO['studio_queue_not_ready']) . '</span>';
        }

        if ($canEdit) {
            $content .= '<span class="radio-program-item__actions">';

            $content .= '<form method="post" action="" class="radio-studio__inline-form">'
                . '<input type="hidden" name="program_id" value="' . $programId . '">'
                . '<input type="hidden" name="item_id" value="' . (int) $item['item_id'] . '">'
                . '<input type="hidden" name="studio_action" value="move_up">'
                . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_studio_h($token) . '">'
                . '<button type="submit" class="radio-program-item__action"'
                . ($index === 0 ? ' disabled' : '')
                . ' title="' . radio_studio_h($LANG_RADIO['move_up']) . '" aria-label="'
                . radio_studio_h($LANG_RADIO['move_up']) . '">↑</button></form>';

            $content .= '<form method="post" action="" class="radio-studio__inline-form">'
                . '<input type="hidden" name="program_id" value="' . $programId . '">'
                . '<input type="hidden" name="item_id" value="' . (int) $item['item_id'] . '">'
                . '<input type="hidden" name="studio_action" value="move_down">'
                . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_studio_h($token) . '">'
                . '<button type="submit" class="radio-program-item__action"'
                . ($index === count($playlist) - 1 ? ' disabled' : '')
                . ' title="' . radio_studio_h($LANG_RADIO['move_down']) . '" aria-label="'
                . radio_studio_h($LANG_RADIO['move_down']) . '">↓</button></form>';

            $content .= '<form method="post" action="" class="radio-studio__inline-form">'
                . '<input type="hidden" name="program_id" value="' . $programId . '">'
                . '<input type="hidden" name="item_id" value="' . (int) $item['item_id'] . '">'
                . '<input type="hidden" name="studio_action" value="remove">'
                . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_studio_h($token) . '">'
                . '<button type="submit" class="radio-program-item__action radio-program-item__action--remove"'
                . ' title="' . radio_studio_h($LANG_RADIO['remove']) . '" aria-label="'
                . radio_studio_h($LANG_RADIO['remove']) . '">−</button></form>';

            $content .= '</span>';
        }

        $content .= '</li>';
    }
    $content .= '</ol>';
}
$content .= '</div></section>';

if ($canEdit) {
    $typeItems = array();
    foreach (array('music','podcast','interview','show','chronicle','jingle','announcement','promo') as $type) {
        $typeItems[$type] = $LANG_RADIO['type_' . $type];
    }

    $content .= '<section class="radio-program-picker radio-studio">'
        . '<h2>' . radio_studio_h($LANG_RADIO['program_media_search_title']) . '</h2>'
        . '<form class="radio-program-picker__filters" method="get" action="" data-radio-studio-search>'
        . '<input type="hidden" name="program_id" value="' . $programId . '">'
        . '<div class="radio-program-picker__filter-grid">'
        . '<label>' . radio_studio_h($LANG_RADIO['filter_search'])
        . '<input type="search" name="q" value="' . radio_studio_h($filters['q']) . '" placeholder="'
        . radio_studio_h($LANG_RADIO['program_media_search_placeholder']) . '"></label>'
        . '<label>' . radio_studio_h($LANG_RADIO['type'])
        . '<select name="type">' . RADIO_adminFilterSelectOptions($typeItems, $filters['type'], $LANG_RADIO['filter_all']) . '</select></label>'
        . '<label>' . radio_studio_h($LANG_RADIO['category'])
        . '<select name="category">' . RADIO_adminFilterSelectOptions($classificationOptions['categories'], $filters['category'], $LANG_RADIO['filter_all']) . '</select></label>'
        . '<label>' . radio_studio_h($LANG_RADIO['collection'])
        . '<select name="collection">' . RADIO_adminFilterSelectOptions($classificationOptions['collections'], $filters['collection'], $LANG_RADIO['filter_all']) . '</select></label>'
        . '<label>' . radio_studio_h($LANG_RADIO['tags'])
        . '<select name="tag">' . RADIO_adminFilterSelectOptions($classificationOptions['tags'], $filters['tag'], $LANG_RADIO['filter_all']) . '</select></label>'
        . '</div>'
        . '<div class="radio-admin__toolbar">'
        . '<button class="radio-admin__button radio-admin__button--primary" type="submit">'
        . radio_studio_h($LANG_RADIO['program_media_search_button']) . '</button>'
        . '<a class="radio-admin__button" href="'
        . radio_studio_h($_CONF['site_admin_url'] . '/plugins/radio/studio.php?program_id=' . $programId)
        . '">' . radio_studio_h($LANG_RADIO['filter_reset']) . '</a>'
        . '</div></form>';

    $visibleResults = array();
    foreach ($searchRows as $row) {
        if (RADIO_hasReadAccess($row) && RADIO_isBroadcastAvailable($row)) {
            $visibleResults[] = $row;
        }
    }

    $content .= '<p class="radio-admin__muted">'
        . sprintf(radio_studio_h($LANG_RADIO['program_media_search_count']), count($visibleResults))
        . '</p><div class="radio-program-picker__results" data-radio-studio-results>';

    if (count($visibleResults) === 0) {
        $content .= '<p class="radio-admin__muted">'
            . radio_studio_h($LANG_RADIO['program_media_search_empty']) . '</p>';
    } else {
        foreach ($visibleResults as $row) {
            $meta = array();
            if (!empty($row['author'])) {
                $meta[] = $row['author'];
            }
            $meta[] = RADIO_adminMediaTypeLabel($row['media_type']);
            if (!empty($row['duration'])) {
                $meta[] = gmdate('H:i:s', (int) $row['duration']);
            }

            $content .= '<article class="radio-program-picker__item">'
                . '<div class="radio-program-picker__info"><strong>'
                . radio_studio_h($row['title']) . '</strong>'
                . '<div class="radio-admin__muted">' . radio_studio_h(implode(' · ', $meta)) . '</div>'
                . RADIO_adminClassificationHtml($row)
                . '</div><div class="radio-studio__result-actions">';

            $content .= '<form method="post" action="" class="radio-studio__inline-form">'
                . '<input type="hidden" name="program_id" value="' . $programId . '">'
                . '<input type="hidden" name="media_id" value="' . (int) $row['media_id'] . '">'
                . '<input type="hidden" name="studio_action" value="add">'
                . '<input type="hidden" name="position" value="next">'
                . '<input type="hidden" name="current_item_id" value="0" data-radio-current-item-input>'
                . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_studio_h($token) . '">'
                . '<button class="radio-admin__button" type="submit">'
                . radio_studio_h($LANG_RADIO['studio_play_next']) . '</button></form>';

            $content .= '<form method="post" action="" class="radio-studio__inline-form">'
                . '<input type="hidden" name="program_id" value="' . $programId . '">'
                . '<input type="hidden" name="media_id" value="' . (int) $row['media_id'] . '">'
                . '<input type="hidden" name="studio_action" value="add">'
                . '<input type="hidden" name="position" value="end">'
                . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_studio_h($token) . '">'
                . '<button class="radio-program-picker__add" type="submit" title="'
                . radio_studio_h(sprintf($LANG_RADIO['program_media_add_title'], $row['title']))
                . '" aria-label="' . radio_studio_h(sprintf($LANG_RADIO['program_media_add_title'], $row['title']))
                . '">+</button></form>';

            $content .= '</div></article>';
        }
    }

    $content .= '</div></section>';
}

$content .= '</div>';

$studioSimpleScript = <<<'JS'
<script>
(function () {
    'use strict';
    var player = document.querySelector('[data-radio-replay-player]');
    if (!player) {
        return;
    }

    document.addEventListener('click', function (event) {
        var button = event.target;
        while (button && button !== document && !button.hasAttribute('data-radio-studio-seek-item')) {
            button = button.parentNode;
        }
        if (!button || button === document) {
            return;
        }

        var itemId = parseInt(button.getAttribute('data-radio-studio-seek-item') || '0', 10) || 0;
        if (!itemId) {
            return;
        }

        var customEvent;
        if (typeof CustomEvent === 'function') {
            customEvent = new CustomEvent('radio:seek-item', {detail: {item_id: itemId}});
        } else {
            customEvent = document.createEvent('CustomEvent');
            customEvent.initCustomEvent('radio:seek-item', false, false, {item_id: itemId});
        }
        player.dispatchEvent(customEvent);
    });

    document.addEventListener('submit', function (event) {
        var input = event.target.querySelector('[data-radio-current-item-input]');
        if (input) {
            input.value = player.getAttribute('data-radio-current-item-id') || '0';
        }
    });
}());
</script>
JS;

$content .= $studioSimpleScript;

$studioScript = '';
if ($canEdit) {
    $studioPath = !empty($_CONF['path_admin'])
        ? rtrim($_CONF['path_admin'], '/\\') . '/plugins/radio/radio-studio.js'
        : '';
    if ($studioPath === '' || !is_file($studioPath)) {
        $studioPath = rtrim($_CONF['path'], '/\\') . '/plugins/radio/admin/radio-studio.js';
    }
    $studioScript = '<script defer src="'
        . radio_studio_h(rtrim($_CONF['site_admin_url'], '/') . '/plugins/radio/radio-studio.js')
        . '?v=' . rawurlencode(RADIO_assetVersion($studioPath)) . '"></script>' . "\n";
}

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['studio_title'] . ' - ' . $program['title'],
    'headercode' => RADIO_adminStylesheetLink()
        . RADIO_publicStylesheetLink()
        . RADIO_publicScriptTag()
        . $studioScript
)));
