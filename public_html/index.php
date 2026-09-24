<?php
require_once '../lib-common.php';
RADIO_requirePublicAccess();
global $_TABLES, $_CONF, $_RADIO_CONF, $LANG_RADIO;

$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';
$onDemandEnabled = !isset($_RADIO_CONF['on_demand_enabled']) || !empty($_RADIO_CONF['on_demand_enabled']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id > 0) {
    $row = RADIO_getMedia($id, true);
    if ($row === false || !RADIO_isOnDemandAvailable($row)) {
        $content = COM_showMessageText($LANG_RADIO['media_not_found'], $title);
COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title)));
        exit;
    }

    DB_query("UPDATE {$_TABLES['radio_media']} SET hits=hits+1 WHERE media_id=" . $id);

    $content = '<div class="radio-item"><h1>' . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
    if (!empty($row['cover_name'])) {
        $content .= '<p><img src="' . htmlspecialchars(RADIO_coverUrl('media', $id), ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:320px;width:100%;height:auto"></p>';
    }
    if (!empty($row['author'])) {
        $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['author'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($row['author'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if (!empty($row['series_title'])) {
        $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['series_title'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($row['series_title'], ENT_QUOTES, 'UTF-8');
        if ((int)$row['season_number'] > 0 || (int)$row['episode_number'] > 0) {
            $content .= ' — S' . (int)$row['season_number'] . ' E' . (int)$row['episode_number'];
        }
        $content .= '</p>';
    }
    if ($row['description'] !== '') {
        $content .= '<p>' . nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    if (RADIO_sourceKind($row) !== 'local') {
        $content .= '<p><small><strong>' . htmlspecialchars($LANG_RADIO['source_kind'], ENT_QUOTES, 'UTF-8') . ':</strong> '
            . htmlspecialchars(RADIO_sourceKind($row) === 'live' ? $LANG_RADIO['source_live'] : $LANG_RADIO['source_external'], ENT_QUOTES, 'UTF-8');
        if (!empty($row['source_provider'])) {
            $content .= ' · ' . htmlspecialchars($row['source_provider'], ENT_QUOTES, 'UTF-8');
        }
        if (!empty($row['source_attribution'])) {
            $content .= '<br><strong>' . htmlspecialchars($LANG_RADIO['source_attribution'], ENT_QUOTES, 'UTF-8') . ':</strong> '
                . htmlspecialchars($row['source_attribution'], ENT_QUOTES, 'UTF-8');
        }
        if (!empty($row['source_license'])) {
            $content .= '<br><strong>' . htmlspecialchars($LANG_RADIO['source_license'], ENT_QUOTES, 'UTF-8') . ':</strong> '
                . htmlspecialchars($row['source_license'], ENT_QUOTES, 'UTF-8');
        }
        $content .= '</small></p>';
    }
    if ($onDemandEnabled) {
        $content .= '<p><audio data-radio-media-id="' . (int) $row['media_id'] . '" data-radio-source="catalogue" controls preload="metadata" src="'
            . htmlspecialchars(RADIO_mediaUrl($id, false), ENT_QUOTES, 'UTF-8') . '"></audio></p>';
    } else {
        $content .= '<p>' . htmlspecialchars($LANG_RADIO['public_on_demand_disabled'], ENT_QUOTES, 'UTF-8') . '</p>';
    }

    if ($onDemandEnabled && !empty($_RADIO_CONF['allow_downloads']) && !empty($row['allow_download'])) {
        $content .= '<p><a href="' . htmlspecialchars(RADIO_mediaUrl($id, true), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($LANG_RADIO['download'], ENT_QUOTES, 'UTF-8') . '</a></p>';
    }
    $content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $row['title'])));
    exit;
}

$media = $onDemandEnabled ? RADIO_getMediaList(50, true) : array();
$liveState = RADIO_getLiveState(time());
$nowPlaying = $liveState['program'];
$upcomingPrograms = RADIO_getUpcoming(5, time());

$content = '<div class="radio-public">';

if ($liveState['media'] !== false) {
    $content .= '<section class="radio-player-card radio-player-card--live" data-radio-home-live'
        . ' data-now-endpoint="' . htmlspecialchars($_CONF['site_url'] . '/radio/now.php', ENT_QUOTES, 'UTF-8') . '"'
        . ' data-event-endpoint="' . htmlspecialchars($_CONF['site_url'] . '/radio/event.php', ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="radio-player-card__header">'
        . '<span class="radio-live-badge"><span class="radio-live-dot" aria-hidden="true"></span>'
        . htmlspecialchars($LANG_RADIO['public_on_air'], ENT_QUOTES, 'UTF-8') . '</span>'
        . '</div>'
        . '<div class="radio-player-card__body">'
        . '<h1 class="radio-now-title"><a data-radio-home-title href="'
        . htmlspecialchars($liveState['media']['item_url'], ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($liveState['media']['title'], ENT_QUOTES, 'UTF-8') . '</a></h1>';

    if ($nowPlaying !== false) {
        $content .= '<p class="radio-now-meta">'
            . htmlspecialchars($nowPlaying['program_title'], ENT_QUOTES, 'UTF-8')
            . ' · ' . date('H:i', $nowPlaying['start']) . '–' . date('H:i', $nowPlaying['end'])
            . '</p>';
    }

    $content .= '<audio id="radio-home-audio" controls preload="metadata" src="'
        . htmlspecialchars($liveState['media']['stream_url'], ENT_QUOTES, 'UTF-8') . '"></audio>'
        . '<div class="radio-wave-wrap">'
        . '<canvas class="radio-wave" id="radio-home-wave" width="720" height="84" aria-hidden="true"></canvas>'
        . '</div>'
        . '</div>'
        . '<nav class="radio-player-links" aria-label="' . htmlspecialchars($LANG_RADIO['plugin_name'], ENT_QUOTES, 'UTF-8') . '">'
        . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/player.php?autoplay=1', ENT_QUOTES, 'UTF-8') . '" data-radio-persistent-player>'
        . htmlspecialchars($LANG_RADIO['persistent_listen'], ENT_QUOTES, 'UTF-8') . '</a>'
        . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/schedule.php', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_RADIO['view_full_schedule'], ENT_QUOTES, 'UTF-8') . '</a>'
        . '<a href="' . htmlspecialchars(RADIO_podcastFeedUrl(), ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_RADIO['podcast_feed'], ENT_QUOTES, 'UTF-8') . '</a>'
        . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/playlist.php', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_RADIO['m3u_playlist'], ENT_QUOTES, 'UTF-8') . '</a>'
        . '</nav>'
        . '</section>';
} else {
    $content .= '<section class="radio-player-card radio-player-card--idle">'
        . '<span class="radio-live-badge">' . htmlspecialchars($LANG_RADIO['public_on_air'], ENT_QUOTES, 'UTF-8') . '</span>'
        . '<p>' . htmlspecialchars($LANG_RADIO['nothing_scheduled_now'], ENT_QUOTES, 'UTF-8') . '</p>'
        . '</section>';
}

if (count($upcomingPrograms) > 0) {
    $content .= '<section class="radio-up-next"><h2>' . htmlspecialchars($LANG_RADIO['up_next'], ENT_QUOTES, 'UTF-8') . '</h2><ul>';
    foreach ($upcomingPrograms as $program) {
        $content .= '<li><time>' . date('Y-m-d H:i', $program['start']) . '</time><a href="'
            . htmlspecialchars($program['url'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($program['program_title'], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    $content .= '</ul></section>';
}

if ($onDemandEnabled) {
    $content .= '<section class="radio-catalogue"><div class="radio-section-heading"><h2>'
        . htmlspecialchars($LANG_RADIO['public_on_demand'], ENT_QUOTES, 'UTF-8') . '</h2></div>';

    if (count($media) === 0) {
        $content .= '<p>' . htmlspecialchars($LANG_RADIO['public_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
    } else {
        $content .= '<div class="radio-list">';
        foreach ($media as $row) {
            $itemUrl = $_CONF['site_url'] . '/radio/index.php?id=' . (int) $row['media_id'];
            $content .= '<article class="radio-media-card">'
                . '<div class="radio-media-card__content"><h3><a href="'
                . htmlspecialchars($itemUrl, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '</a></h3>';

            if ($row['description'] !== '') {
                $content .= '<p>' . nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
            }

            $content .= '</div><audio data-radio-media-id="' . (int) $row['media_id']
                . '" data-radio-source="catalogue" controls preload="metadata" src="'
                . htmlspecialchars(RADIO_mediaUrl((int) $row['media_id'], false), ENT_QUOTES, 'UTF-8')
                . '"></audio></article>';
        }
        $content .= '</div>';
    }
    $content .= '</section>';
}

$content .= '</div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title)));
