<?php
require_once '../lib-common.php';
RADIO_requirePublicAccess();

global $_RADIO_CONF;

header('Content-Type: audio/x-mpegurl; charset=UTF-8');
header('Content-Disposition: inline; filename="radio-playlist.m3u"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function RADIO_m3uText($value)
{
    $value = str_replace(array("\r", "\n"), ' ', (string) $value);
    return trim($value);
}

$items = RADIO_getPodcastItems(200, 0);

echo "#EXTM3U\n";

foreach ($items as $item) {
    $duration = max(-1, (int) $item['duration']);
    $title = RADIO_m3uText($item['title']);
    if (!empty($item['author'])) {
        $title = RADIO_m3uText($item['author']) . ' - ' . $title;
    }

    echo '#EXTINF:' . $duration . ',' . $title . "\n";
    echo RADIO_mediaUrl((int) $item['media_id'], false) . "\n";
}
