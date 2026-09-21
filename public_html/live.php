<?php
require_once '../lib-common.php';
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';

$content = '<div class="radio-public"><section class="radio-player-card radio-player-card--live radio-live"'
    . ' data-now-endpoint="' . htmlspecialchars($_CONF['site_url'] . '/radio/now.php', ENT_QUOTES, 'UTF-8') . '"'
    . ' data-event-endpoint="' . htmlspecialchars($_CONF['site_url'] . '/radio/event.php', ENT_QUOTES, 'UTF-8') . '"'
    . ' data-nothing-label="' . htmlspecialchars($LANG_RADIO['nothing_scheduled_now'], ENT_QUOTES, 'UTF-8') . '"'
    . ' data-on-air-label="' . htmlspecialchars($LANG_RADIO['public_on_air'], ENT_QUOTES, 'UTF-8') . '"'
    . ' data-incomplete-label="' . htmlspecialchars($LANG_RADIO['duration_required_live'], ENT_QUOTES, 'UTF-8') . '"'
    . ' data-unavailable-label="' . htmlspecialchars($LANG_RADIO['live_unavailable'], ENT_QUOTES, 'UTF-8') . '">'
    . '<div class="radio-player-card__header"><span class="radio-live-badge">'
    . '<span class="radio-live-dot" aria-hidden="true"></span>'
    . htmlspecialchars($LANG_RADIO['public_on_air'], ENT_QUOTES, 'UTF-8') . '</span></div>'
    . '<div class="radio-player-card__body">'
    . '<h1 class="radio-live-heading">' . htmlspecialchars($LANG_RADIO['listen_live'], ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<p id="radio-live-status" class="radio-now-meta">' . htmlspecialchars($LANG_RADIO['live_ready'], ENT_QUOTES, 'UTF-8') . '</p>'
    . '<div class="radio-live-info"><strong id="radio-live-program"></strong><span id="radio-live-media"></span></div>'
    . '<audio id="radio-live-player" controls preload="metadata"></audio>'
    . '<div class="radio-wave-wrap"><canvas class="radio-wave" id="radio-live-wave" width="720" height="84" aria-hidden="true"></canvas></div>'
    . '<p class="radio-live-action"><button type="button" id="radio-live-start">' . htmlspecialchars($LANG_RADIO['start_listening'], ENT_QUOTES, 'UTF-8') . '</button></p>'
    . '<p class="radio-player-note">' . htmlspecialchars($LANG_RADIO['autoplay_notice'], ENT_QUOTES, 'UTF-8') . '</p>'
    . '</div>'
    . '<nav class="radio-player-links"><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a>'
    . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/schedule.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['view_full_schedule'], ENT_QUOTES, 'UTF-8') . '</a>'
</nav>'
    . '</section></div>';

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['listen_live'] . ' - ' . $title
)));
