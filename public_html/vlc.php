<?php
require_once '../lib-common.php';

header('Content-Type: audio/x-mpegurl; charset=UTF-8');
header('Content-Disposition: inline; filename="radio-live.m3u"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function RADIO_liveM3uText($value)
{
    $value = str_replace(array("\r", "\n"), ' ', (string) $value);
    return trim($value);
}

$state = RADIO_getLiveState(time());

echo "#EXTM3U\n";

if ($state['media'] === false) {
    exit;
}

$current = $state['media'];
$currentId = (int) $current['media_id'];
$offset = max(0, (int) $current['offset']);
$duration = max(-1, (int) $current['duration']);

$currentUrl = $current['source_kind'] === 'local'
    ? RADIO_mediaUrlAtOffset($currentId, $offset)
    : $current['stream_url'];

echo '#EXTINF:' . max(1, $duration - $offset) . ',' . RADIO_liveM3uText($current['title']) . "\n";
echo $currentUrl . "\n";

$seen = array($currentId => true);

if ($state['source'] === 'schedule' && $state['program'] !== false) {
    $items = RADIO_getProgramItems((int) $state['program']['program_id']);
    $cursor = 0;
    $currentFound = false;

    foreach ($items as $item) {
        if ($item['status'] !== 'published' || !RADIO_hasReadAccess($item) || (int) $item['duration'] < 1) {
            continue;
        }

        $itemDuration = (int) $item['duration'];
        if (!$currentFound) {
            if ((int) $item['media_id'] === $currentId
                && (int) $state['program']['elapsed'] < ($cursor + $itemDuration)) {
                $currentFound = true;
            }
            $cursor += $itemDuration;
            continue;
        }

        $id = (int) $item['media_id'];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        echo '#EXTINF:' . $itemDuration . ',' . RADIO_liveM3uText($item['title']) . "\n";
        echo RADIO_mediaUrl($id, false) . "\n";
    }
} elseif ($state['source'] === 'rotation') {
    $sequence = RADIO_buildRotationSequence(date('Y-m-d'));
    $count = count($sequence);
    $currentIndex = -1;

    for ($i = 0; $i < $count; $i++) {
        if ((int) $sequence[$i]['media_id'] === $currentId) {
            $currentIndex = $i;
            break;
        }
    }

    if ($currentIndex >= 0 && $count > 1) {
        $max = min(30, $count - 1);
        for ($step = 1; $step <= $max; $step++) {
            $item = $sequence[($currentIndex + $step) % $count];
            $id = (int) $item['media_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            echo '#EXTINF:' . max(-1, (int) $item['duration']) . ',' . RADIO_liveM3uText($item['title']) . "\n";
            echo RADIO_mediaUrl($id, false) . "\n";
        }
    }
}
