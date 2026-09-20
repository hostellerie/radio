<?php
require_once '../lib-common.php';
global $_TABLES, $_CONF, $_RADIO_CONF, $LANG_RADIO;

$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id > 0) {
    $row = RADIO_getMedia($id, true);
    if ($row === false) {
        $content = COM_showMessageText($LANG_RADIO['media_not_found'], $title);
COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title, 'footercode' => RADIO_trackingScript())));
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
    $content .= '<p><audio data-radio-media-id="' . (int) $row['media_id'] . '" data-radio-source="catalogue" controls preload="metadata" style="width:100%;max-width:800px" src="'
        . htmlspecialchars(RADIO_mediaUrl($id, false), ENT_QUOTES, 'UTF-8') . '"></audio></p>';

    if (!empty($_RADIO_CONF['allow_downloads']) && !empty($row['allow_download'])) {
        $content .= '<p><a href="' . htmlspecialchars(RADIO_mediaUrl($id, true), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($LANG_RADIO['download'], ENT_QUOTES, 'UTF-8') . '</a></p>';
    }
    $content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $row['title'], 'footercode' => RADIO_trackingScript())));
    exit;
}

$media = RADIO_getMediaList(50, true);
$liveState = RADIO_getLiveState(time());
$nowPlaying = $liveState['program'];
$upcomingPrograms = RADIO_getUpcoming(5, time());

$content = '<div class="radio-status" style="margin:0 0 1.5rem;padding:1rem;border:1px solid rgba(127,127,127,.3);border-radius:.4rem">';
if ($nowPlaying !== false) {
    $content .= '<strong>' . htmlspecialchars($LANG_RADIO['now_playing'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . '<a href="' . htmlspecialchars($nowPlaying['url'], ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($nowPlaying['program_title'], ENT_QUOTES, 'UTF-8') . '</a> '
        . '<small>(' . date('H:i', $nowPlaying['start']) . '–' . date('H:i', $nowPlaying['end']) . ')</small>';
} elseif ($liveState['media'] !== false) {
    $content .= '<strong>' . htmlspecialchars($LANG_RADIO['now_playing'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . htmlspecialchars($LANG_RADIO['automatic_rotation'], ENT_QUOTES, 'UTF-8') . ' — '
        . '<a href="' . htmlspecialchars($liveState['media']['item_url'], ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($liveState['media']['title'], ENT_QUOTES, 'UTF-8') . '</a>';
} else {
    $content .= '<strong>' . htmlspecialchars($LANG_RADIO['now_playing'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . htmlspecialchars($LANG_RADIO['nothing_scheduled_now'], ENT_QUOTES, 'UTF-8');
}
if (count($upcomingPrograms) > 0) {
    $content .= '<h2 style="font-size:1rem">' . htmlspecialchars($LANG_RADIO['up_next'], ENT_QUOTES, 'UTF-8') . '</h2><ul>';
    foreach ($upcomingPrograms as $program) {
        $content .= '<li>' . date('Y-m-d H:i', $program['start']) . ' — <a href="'
            . htmlspecialchars($program['url'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($program['program_title'], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    $content .= '</ul>';
}
$content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/schedule.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['view_full_schedule'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/live.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['listen_live'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars(RADIO_podcastFeedUrl(), ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['podcast_feed'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

$content .= '<div class="radio-catalogue"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
if (count($media) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['public_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<div class="radio-list">';
    foreach ($media as $row) {
        $itemUrl = $_CONF['site_url'] . '/radio/index.php?id=' . (int) $row['media_id'];
        $content .= '<article style="margin:0 0 1.5rem"><h2><a href="'
            . htmlspecialchars($itemUrl, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '</a></h2>';
        if ($row['description'] !== '') {
            $content .= '<p>' . nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        $content .= '<audio data-radio-media-id="' . (int) $row['media_id'] . '" data-radio-source="catalogue" controls preload="none" style="width:100%;max-width:800px" src="'
            . htmlspecialchars(RADIO_mediaUrl((int) $row['media_id'], false), ENT_QUOTES, 'UTF-8') . '"></audio></article>';
    }
    $content .= '</div>';
}
$content .= '</div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title, 'footercode' => RADIO_trackingScript())));
