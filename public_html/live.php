<?php
require_once '../lib-common.php';
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';
$content = '<div class="radio-live"'
    . ' data-now-endpoint="' . htmlspecialchars($_CONF['site_url'] . '/radio/now.php', ENT_QUOTES, 'UTF-8') . '"'
    . ' data-event-endpoint="' . htmlspecialchars($_CONF['site_url'] . '/radio/event.php', ENT_QUOTES, 'UTF-8') . '"'
    . ' data-nothing-label="' . htmlspecialchars($LANG_RADIO['nothing_scheduled_now'], ENT_QUOTES, 'UTF-8') . '"'
    . ' data-on-air-label="' . htmlspecialchars($LANG_RADIO['public_on_air'], ENT_QUOTES, 'UTF-8') . '"'
    . ' data-incomplete-label="' . htmlspecialchars($LANG_RADIO['duration_required_live'], ENT_QUOTES, 'UTF-8') . '"'
    . ' data-unavailable-label="' . htmlspecialchars($LANG_RADIO['live_unavailable'], ENT_QUOTES, 'UTF-8') . '">'
    . '<h1>' . htmlspecialchars($LANG_RADIO['listen_live'], ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<p id="radio-live-status">' . htmlspecialchars($LANG_RADIO['live_ready'], ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong id="radio-live-program"></strong><br><span id="radio-live-media"></span></p>'
    . '<audio id="radio-live-player" controls preload="metadata"></audio>'
    . '<p><button type="button" id="radio-live-start">' . htmlspecialchars($LANG_RADIO['start_listening'], ENT_QUOTES, 'UTF-8') . '</button></p>'
    . '<p><small>' . htmlspecialchars($LANG_RADIO['autoplay_notice'], ENT_QUOTES, 'UTF-8') . '</small></p>'
    . '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/schedule.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['view_full_schedule'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['listen_live'] . ' - ' . $title
)));
