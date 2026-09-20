<?php
require_once '../lib-common.php';

global $_CONF, $_RADIO_CONF;

header('Content-Type: application/rss+xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$title = isset($_RADIO_CONF['public_title']) && $_RADIO_CONF['public_title'] !== ''
    ? $_RADIO_CONF['public_title'] . ' Podcasts'
    : 'Radio Podcasts';
$link = $_CONF['site_url'] . '/radio/index.php';
$self = RADIO_podcastFeedUrl();
$items = RADIO_getPodcastItems(100, 0);

function RADIO_xml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">' . "\n";
echo "<channel>\n";
echo '<title>' . RADIO_xml($title) . "</title>\n";
echo '<link>' . RADIO_xml($link) . "</link>\n";
echo '<description>' . RADIO_xml($title) . "</description>\n";
echo '<language>' . RADIO_xml(isset($_CONF['language']) ? $_CONF['language'] : 'en') . "</language>\n";
echo '<atom:link href="' . RADIO_xml($self) . '" rel="self" type="application/rss+xml" />' . "\n";

foreach ($items as $item) {
    $itemUrl = plugin_idtourl_radio('media', RADIO_externalId('media', (int) $item['media_id']));
    $streamUrl = RADIO_mediaUrl((int) $item['media_id'], false);
    $guid = RADIO_externalId('media', (int) $item['media_id']);
    $pubDate = strtotime($item['modified']);
    $description = isset($item['description']) ? $item['description'] : '';
    $mime = $item['mime_type'] !== '' ? $item['mime_type'] : 'audio/mpeg';
    $length = (int) $item['file_size'];
    $duration = max(0, (int) $item['duration']);

    echo "<item>\n";
    echo '<title>' . RADIO_xml($item['title']) . "</title>\n";
    echo '<link>' . RADIO_xml($itemUrl) . "</link>\n";
    echo '<guid isPermaLink="false">' . RADIO_xml($guid) . "</guid>\n";
    echo '<pubDate>' . gmdate(DATE_RSS, $pubDate) . "</pubDate>\n";
    echo '<description>' . RADIO_xml($description) . "</description>\n";
    echo '<enclosure url="' . RADIO_xml($streamUrl) . '" length="' . $length . '" type="' . RADIO_xml($mime) . '" />' . "\n";
    if ($duration > 0) {
        echo '<itunes:duration>' . $duration . "</itunes:duration>\n";
    }
    echo "</item>\n";
}

echo "</channel>\n</rss>\n";
