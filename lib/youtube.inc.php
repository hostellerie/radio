<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

function RADIO_youtubeConfigDefaults()
{
    global $_RADIO_CONF;

    return array(
        'enabled' => !empty($_RADIO_CONF['youtube_enabled']),
        'mode' => isset($_RADIO_CONF['youtube_mode'])
            ? (string) $_RADIO_CONF['youtube_mode']
            : 'scheduled',
        'rtmp_url' => isset($_RADIO_CONF['youtube_rtmp_url'])
            ? trim((string) $_RADIO_CONF['youtube_rtmp_url'])
            : 'rtmps://a.rtmps.youtube.com/live2',
        'stream_key' => isset($_RADIO_CONF['youtube_stream_key'])
            ? trim((string) $_RADIO_CONF['youtube_stream_key'])
            : '',
        'schedule_ids' => array(),
        'manual_program_id' => 0,
        'manual_requested' => false,
        'video_size' => isset($_RADIO_CONF['youtube_video_size'])
            ? (string) $_RADIO_CONF['youtube_video_size']
            : '1280x720',
        'video_bitrate' => isset($_RADIO_CONF['youtube_video_bitrate'])
            ? (string) $_RADIO_CONF['youtube_video_bitrate']
            : '2500k',
        'audio_bitrate' => isset($_RADIO_CONF['youtube_audio_bitrate'])
            ? (string) $_RADIO_CONF['youtube_audio_bitrate']
            : '128k',
        'visual_template' => 'stationcard',
        'show_station' => true,
        'station_name' => '',
        'show_program' => true,
        'show_track' => true,
        'show_artwork' => true,
        'show_visualizer' => true,
        'visualizer_size' => 'medium',
        'suppressed_target_key' => '',
        'suppressed_until' => 0,
        'suppressed_program_title' => ''
    );
}

function RADIO_youtubeConfigPath()
{
    return RADIO_storageDir() . 'youtube-live.json';
}

function RADIO_youtubeStatePath()
{
    return RADIO_storageDir() . 'youtube-live-state.json';
}

function RADIO_youtubeLogPath()
{
    return RADIO_storageDir() . 'youtube-live.log';
}

function RADIO_youtubeConcatPath()
{
    return RADIO_storageDir() . 'youtube-live.ffconcat';
}

function RADIO_youtubeReadJson($path, $defaults)
{
    if (!is_file($path) || !is_readable($path)) {
        return $defaults;
    }
    $raw = @file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return $defaults;
    }
    return array_merge($defaults, $data);
}

function RADIO_youtubeConfig()
{
    $config = RADIO_youtubeConfigDefaults();
    $runtime = RADIO_youtubeReadJson(RADIO_youtubeConfigPath(), array());

    foreach (array(
        'schedule_ids',
        'manual_program_id',
        'manual_requested',
        'visual_template',
        'show_station',
        'station_name',
        'show_program',
        'show_track',
        'show_artwork',
        'show_visualizer',
        'visualizer_size',
        'suppressed_target_key',
        'suppressed_until',
        'suppressed_program_title'
    ) as $runtimeKey) {
        if (array_key_exists($runtimeKey, $runtime)) {
            $config[$runtimeKey] = $runtime[$runtimeKey];
        }
    }

    $config['enabled'] = !empty($config['enabled']);
    $config['manual_requested'] = !empty($config['manual_requested']);
    $config['manual_program_id'] = max(0, (int) $config['manual_program_id']);
    $config['mode'] = in_array($config['mode'], array('scheduled','manual'), true)
        ? $config['mode']
        : 'scheduled';
    if (!is_array($config['schedule_ids'])) {
        $config['schedule_ids'] = array();
    }
    $config['schedule_ids'] = array_values(array_unique(array_filter(array_map('intval', $config['schedule_ids']))));
    $config['rtmp_url'] = trim((string) $config['rtmp_url']);
    if (strpos($config['rtmp_url'], 'rtmps://') !== 0
        && strpos($config['rtmp_url'], 'rtmp://') !== 0) {
        $config['rtmp_url'] = 'rtmps://a.rtmps.youtube.com/live2';
    }
    $config['video_size'] = preg_match('/^\d{3,4}x\d{3,4}$/', (string) $config['video_size'])
        ? (string) $config['video_size']
        : '1280x720';
    $config['audio_bitrate'] = in_array($config['audio_bitrate'], array('96k','128k','160k','192k'), true)
        ? $config['audio_bitrate']
        : '128k';
    $config['visual_template'] = in_array($config['visual_template'], array('stationcard','fullbackground','minimal','visualizer'), true)
        ? $config['visual_template']
        : 'stationcard';
    $config['show_station'] = !empty($config['show_station']);
    $config['station_name'] = trim((string) $config['station_name']);
    $config['show_program'] = !empty($config['show_program']);
    $config['show_track'] = !empty($config['show_track']);
    $config['show_artwork'] = !empty($config['show_artwork']);
    $config['show_visualizer'] = !empty($config['show_visualizer']);
    $config['visualizer_size'] = in_array($config['visualizer_size'], array('small','medium','large'), true)
        ? $config['visualizer_size']
        : 'medium';
    $config['suppressed_target_key'] = trim((string) $config['suppressed_target_key']);
    $config['suppressed_until'] = max(0, (int) $config['suppressed_until']);
    $config['suppressed_program_title'] = trim((string) $config['suppressed_program_title']);

    return $config;
}

function RADIO_youtubeWriteJson($path, $data)
{
    if (!RADIO_ensureStorage()) {
        return false;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);
    return true;
}

function RADIO_youtubeSaveConfig($data)
{
    $runtime = RADIO_youtubeReadJson(RADIO_youtubeConfigPath(), array(
        'schedule_ids' => array(),
        'manual_program_id' => 0,
        'manual_requested' => false,
        'visual_template' => 'stationcard',
        'show_station' => true,
        'station_name' => '',
        'show_program' => true,
        'show_track' => true,
        'show_artwork' => true,
        'show_visualizer' => true,
        'visualizer_size' => 'medium'
    ));

    $runtime['schedule_ids'] = isset($data['schedule_ids']) && is_array($data['schedule_ids'])
        ? array_values(array_unique(array_filter(array_map('intval', $data['schedule_ids']))))
        : array();
    $runtime['manual_program_id'] = isset($data['manual_program_id'])
        ? max(0, (int) $data['manual_program_id'])
        : 0;

    $runtime['visual_template'] = isset($data['visual_template'])
        && in_array($data['visual_template'], array('stationcard','fullbackground','minimal','visualizer'), true)
        ? (string) $data['visual_template']
        : 'stationcard';
    $runtime['show_station'] = !empty($data['show_station']);
    $runtime['station_name'] = isset($data['station_name'])
        ? RADIO_youtubeOverlayText($data['station_name'], 70)
        : '';
    $runtime['show_program'] = !empty($data['show_program']);
    $runtime['show_track'] = !empty($data['show_track']);
    $runtime['show_artwork'] = !empty($data['show_artwork']);
    $runtime['show_visualizer'] = !empty($data['show_visualizer']);
    $runtime['visualizer_size'] = isset($data['visualizer_size'])
        && in_array($data['visualizer_size'], array('small','medium','large'), true)
        ? (string) $data['visualizer_size']
        : 'medium';

    if (!isset($runtime['manual_requested'])) {
        $runtime['manual_requested'] = false;
    }

    return RADIO_youtubeWriteJson(RADIO_youtubeConfigPath(), $runtime);
}

function RADIO_youtubeSaveManualProgram($programId)
{
    $runtime = RADIO_youtubeReadJson(RADIO_youtubeConfigPath(), array());
    $runtime['manual_program_id'] = max(0, (int) $programId);

    return RADIO_youtubeWriteJson(RADIO_youtubeConfigPath(), $runtime);
}

function RADIO_youtubeSaveScheduleIds($scheduleIds)
{
    $runtime = RADIO_youtubeReadJson(RADIO_youtubeConfigPath(), array());
    $runtime['schedule_ids'] = is_array($scheduleIds)
        ? array_values(array_unique(array_filter(array_map('intval', $scheduleIds))))
        : array();

    return RADIO_youtubeWriteJson(RADIO_youtubeConfigPath(), $runtime);
}

function RADIO_youtubeSaveVisualConfig($data)
{
    $runtime = RADIO_youtubeReadJson(RADIO_youtubeConfigPath(), array());

    $runtime['visual_template'] = isset($data['visual_template'])
        && in_array($data['visual_template'], array('stationcard','fullbackground','minimal','visualizer'), true)
        ? (string) $data['visual_template']
        : 'stationcard';
    $runtime['show_station'] = !empty($data['show_station']);
    $runtime['station_name'] = isset($data['station_name'])
        ? RADIO_youtubeOverlayText($data['station_name'], 70)
        : '';
    $runtime['show_program'] = !empty($data['show_program']);
    $runtime['show_track'] = !empty($data['show_track']);
    $runtime['show_artwork'] = !empty($data['show_artwork']);
    $runtime['show_visualizer'] = !empty($data['show_visualizer']);
    $runtime['visualizer_size'] = isset($data['visualizer_size'])
        && in_array($data['visualizer_size'], array('small','medium','large'), true)
        ? (string) $data['visualizer_size']
        : 'medium';

    return RADIO_youtubeWriteJson(RADIO_youtubeConfigPath(), $runtime);
}

function RADIO_youtubeSetManualRequest($requested)
{
    $runtime = RADIO_youtubeReadJson(RADIO_youtubeConfigPath(), array(
        'schedule_ids' => array(),
        'manual_program_id' => 0,
        'manual_requested' => false
    ));
    $runtime['manual_requested'] = (bool) $requested;

    return RADIO_youtubeWriteJson(RADIO_youtubeConfigPath(), $runtime);
}

function RADIO_youtubeVisualSignature($config = null)
{
    if (!is_array($config)) {
        $config = RADIO_youtubeConfig();
    }

    $visual = array(
        'layout_version' => 2,
        'template' => isset($config['visual_template']) ? (string) $config['visual_template'] : 'stationcard',
        'show_station' => !empty($config['show_station']),
        'station_name' => isset($config['station_name']) ? (string) $config['station_name'] : '',
        'show_program' => !empty($config['show_program']),
        'show_track' => !empty($config['show_track']),
        'show_artwork' => !empty($config['show_artwork']),
        'show_visualizer' => !empty($config['show_visualizer']),
        'visualizer_size' => isset($config['visualizer_size']) ? (string) $config['visualizer_size'] : 'medium'
    );

    return sha1(json_encode($visual));
}

function RADIO_youtubeOverlayPath($name)
{
    $safe = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $name);
    return RADIO_storageDir() . 'youtube-live-' . trim($safe, '-') . '.txt';
}

function RADIO_youtubeOverlayText($value, $maxLength)
{
    $value = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $value));
    $value = preg_replace('/\s{2,}/', ' ', $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, (int) $maxLength, 'UTF-8');
    }
    return substr($value, 0, (int) $maxLength);
}

function RADIO_youtubeWriteOverlay($target, $status, $timestamp)
{
    global $_CONF;

    $timestamp = $timestamp ? (int) $timestamp : time();
    $elapsed = isset($target['elapsed']) ? max(0, (int) $target['elapsed']) : 0;

    if (isset($target['schedule_id']) && (int) $target['schedule_id'] === 0
        && isset($status['target_key']) && (string) $status['target_key'] === (string) $target['key']
        && !empty($status['started_at'])) {
        $started = strtotime((string) $status['started_at']);
        if ($started !== false && $started <= $timestamp) {
            $elapsed = max(0, $timestamp - $started);
        }
    }

    $media = RADIO_resolveProgramPlayback((int) $target['program_id'], $elapsed);
    $config = RADIO_youtubeConfig();
    $defaultStation = isset($_CONF['site_name']) && trim((string) $_CONF['site_name']) !== ''
        ? (string) $_CONF['site_name']
        : 'Radio';
    $station = $config['station_name'] !== '' ? $config['station_name'] : $defaultStation;
    $program = isset($target['program_title']) ? (string) $target['program_title'] : '';
    $track = $media !== false && isset($media['title']) ? (string) $media['title'] : '';

    $files = array(
        'station' => $config['show_station'] ? RADIO_youtubeOverlayText($station, 70) : '',
        'program' => $config['show_program'] ? RADIO_youtubeOverlayText($program, 90) : '',
        'track' => $config['show_track'] ? RADIO_youtubeOverlayText($track, 120) : ''
    );

    foreach ($files as $name => $text) {
        $path = RADIO_youtubeOverlayPath($name);
        if (@file_put_contents($path, $text . PHP_EOL, LOCK_EX) === false) {
            return false;
        }
        @chmod($path, 0600);
    }

    return true;
}

function RADIO_youtubeFilterPath($path)
{
    $path = str_replace('\\', '/', (string) $path);
    $path = str_replace(array(':', "'"), array('\\:', "\\'"), $path);
    return $path;
}

function RADIO_youtubeStatus()
{
    return RADIO_youtubeReadJson(RADIO_youtubeStatePath(), array(
        'running' => false,
        'pid' => 0,
        'target_key' => '',
        'program_id' => 0,
        'program_title' => '',
        'schedule_id' => 0,
        'target_end' => 0,
        'started_at' => '',
        'last_check' => '',
        'last_error' => ''
    ));
}

function RADIO_youtubeWriteStatus($data)
{
    $status = array_merge(RADIO_youtubeStatus(), $data);
    $status['last_check'] = date('Y-m-d H:i:s');
    return RADIO_youtubeWriteJson(RADIO_youtubeStatePath(), $status);
}

function RADIO_youtubeSuppressScheduledOccurrence($status)
{
    if (!is_array($status)) {
        return false;
    }

    $scheduleId = isset($status['schedule_id']) ? (int) $status['schedule_id'] : 0;
    $targetKey = isset($status['target_key']) ? (string) $status['target_key'] : '';
    if ($scheduleId < 1 || strpos($targetKey, 'schedule:' . $scheduleId . ':') !== 0) {
        return false;
    }

    $until = isset($status['target_end']) ? (int) $status['target_end'] : 0;
    if ($until <= time()) {
        $parts = explode(':', $targetKey);
        $start = isset($parts[2]) ? (int) $parts[2] : 0;
        if ($start > 0) {
            foreach (RADIO_getSchedules(false) as $schedule) {
                if ((int) $schedule['schedule_id'] === $scheduleId) {
                    $duration = RADIO_scheduleDuration($schedule);
                    if ($duration > 0) {
                        $until = $start + $duration;
                    }
                    break;
                }
            }
        }
    }

    if ($until <= time()) {
        return false;
    }

    $runtime = RADIO_youtubeReadJson(RADIO_youtubeConfigPath(), array());
    $runtime['suppressed_target_key'] = $targetKey;
    $runtime['suppressed_until'] = $until;
    $runtime['suppressed_program_title'] = isset($status['program_title'])
        ? RADIO_youtubeOverlayText($status['program_title'], 120)
        : '';

    return RADIO_youtubeWriteJson(RADIO_youtubeConfigPath(), $runtime);
}

function RADIO_youtubeSuppressedOccurrence($timestamp)
{
    $config = RADIO_youtubeConfig();
    $timestamp = $timestamp ? (int) $timestamp : time();

    if ($config['suppressed_target_key'] === '' || $config['suppressed_until'] <= $timestamp) {
        return false;
    }

    return array(
        'target_key' => $config['suppressed_target_key'],
        'until' => $config['suppressed_until'],
        'program_title' => $config['suppressed_program_title']
    );
}

function RADIO_youtubeNextScheduledOccurrence($timestamp)
{
    $config = RADIO_youtubeConfig();
    $allowed = array_flip($config['schedule_ids']);
    if (empty($allowed)) {
        return false;
    }

    $timestamp = $timestamp ? (int) $timestamp : time();
    $today = strtotime(date('Y-m-d', $timestamp) . ' 00:00:00');
    $best = false;

    foreach (RADIO_getSchedules(false) as $schedule) {
        $scheduleId = isset($schedule['schedule_id']) ? (int) $schedule['schedule_id'] : 0;
        if ($scheduleId < 1
            || empty($schedule['enabled'])
            || !isset($allowed[$scheduleId])
            || (isset($schedule['program_status']) && $schedule['program_status'] !== 'published')) {
            continue;
        }

        if (isset($schedule['recurrence']) && $schedule['recurrence'] === 'once') {
            $candidate = RADIO_scheduleOccurrence(
                $schedule,
                date('Y-m-d', strtotime($schedule['starts_at']))
            );
            if ($candidate !== false && $candidate['start'] > $timestamp
                && ($best === false || $candidate['start'] < $best['start'])) {
                $best = $candidate;
            }
            continue;
        }

        $scanStart = $today;
        if (!empty($schedule['active_from'])) {
            $activeFrom = strtotime($schedule['active_from'] . ' 00:00:00');
            if ($activeFrom !== false && $activeFrom > $scanStart) {
                $scanStart = $activeFrom;
            }
        }

        for ($offset = 0; $offset < 8; $offset++) {
            $dateTs = strtotime('+' . $offset . ' day', $scanStart);
            if ($dateTs === false) {
                continue;
            }
            $candidate = RADIO_scheduleOccurrence($schedule, date('Y-m-d', $dateTs));
            if ($candidate === false || $candidate['start'] <= $timestamp) {
                continue;
            }
            if ($best === false || $candidate['start'] < $best['start']) {
                $best = $candidate;
            }
            break;
        }
    }

    return $best;
}

function RADIO_youtubeTarget($timestamp)
{
    $config = RADIO_youtubeConfig();
    if (!$config['enabled'] || trim($config['stream_key']) === '') {
        return false;
    }

    $timestamp = $timestamp ? (int) $timestamp : time();

    /*
     * Manual start is a temporary override, not a mutually exclusive mode.
     * When no manual request is active, enabled YouTube schedules must still
     * be evaluated. This keeps the admin UI intuitive: "Start now" is manual,
     * while checked scheduled slots always run automatically.
     */
    if (!empty($config['manual_requested']) && $config['manual_program_id'] > 0) {
        $program = RADIO_getProgram($config['manual_program_id'], true);
        if ($program !== false) {
            return array(
                'key' => 'manual:' . (int) $program['program_id'],
                'program_id' => (int) $program['program_id'],
                'program_title' => $program['title'],
                'schedule_id' => 0,
                'start' => $timestamp,
                'end' => 0,
                'elapsed' => 0,
                'remaining' => 0
            );
        }
    }

    $allowed = array_flip($config['schedule_ids']);
    $date = date('Y-m-d', $timestamp);
    $schedules = RADIO_getSchedules(false);
    foreach ($schedules as $schedule) {
        $scheduleId = (int) $schedule['schedule_id'];
        if (empty($schedule['enabled']) || !isset($allowed[$scheduleId])) {
            continue;
        }
        if ($schedule['program_status'] !== 'published') {
            continue;
        }
        $occurrence = RADIO_scheduleOccurrence($schedule, $date);
        if ($occurrence !== false
            && $occurrence['start'] <= $timestamp
            && $occurrence['end'] > $timestamp) {
            $targetKey = 'schedule:' . $scheduleId . ':' . (int) $occurrence['start'];
            if ($config['suppressed_target_key'] === $targetKey
                && $config['suppressed_until'] > $timestamp) {
                continue;
            }

            return array(
                'key' => $targetKey,
                'program_id' => (int) $occurrence['program_id'],
                'program_title' => $occurrence['program_title'],
                'schedule_id' => $scheduleId,
                'start' => (int) $occurrence['start'],
                'end' => (int) $occurrence['end'],
                'elapsed' => max(0, $timestamp - (int) $occurrence['start']),
                'remaining' => max(1, (int) $occurrence['end'] - $timestamp)
            );
        }
    }
    return false;
}

function RADIO_youtubeProgramFiles($programId, &$error)
{
    $error = '';
    $items = RADIO_getProgramItems((int) $programId);
    $result = array();

    foreach ($items as $item) {
        if (!RADIO_isBroadcastAvailable($item)) {
            continue;
        }
        if (RADIO_sourceKind($item) !== 'local' || empty($item['storage_name'])) {
            $error = 'youtube_local_only';
            return false;
        }
        $path = RADIO_storageDir() . basename($item['storage_name']);
        if (!is_file($path) || !is_readable($path)) {
            $error = 'youtube_media_missing';
            return false;
        }
        $result[] = array(
            'path' => $path,
            'duration' => max(1, (int) $item['duration'])
        );
    }

    if (count($result) === 0) {
        $error = 'youtube_program_empty';
        return false;
    }
    return $result;
}

function RADIO_youtubeWriteConcat($programId, &$error)
{
    $files = RADIO_youtubeProgramFiles($programId, $error);
    if ($files === false) {
        return false;
    }

    $content = "ffconcat version 1.0\n";
    foreach ($files as $file) {
        $escaped = str_replace("'", "'\\''", $file['path']);
        $content .= "file '" . $escaped . "'\n";
        $content .= "duration " . (int) $file['duration'] . "\n";
    }

    $path = RADIO_youtubeConcatPath();
    if (@file_put_contents($path, $content, LOCK_EX) === false) {
        $error = 'youtube_concat_failed';
        return false;
    }
    @chmod($path, 0600);
    return $path;
}

function RADIO_youtubeAssPath()
{
    return RADIO_storageDir() . 'youtube-live.ass';
}

function RADIO_youtubeAssTime($seconds)
{
    $seconds = max(0, (int) $seconds);
    $hours = (int) floor($seconds / 3600);
    $minutes = (int) floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%d:%02d:%02d.00', $hours, $minutes, $secs);
}

function RADIO_youtubeAssText($value)
{
    $value = RADIO_youtubeOverlayText($value, 140);
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(array('{', '}'), array('\\{', '\\}'), $value);
    return str_replace(',', '‚', $value);
}

function RADIO_youtubeTextLength($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen((string) $value, 'UTF-8');
    }

    return strlen((string) $value);
}

function RADIO_youtubeTextSubstr($value, $start, $length = null)
{
    if (function_exists('mb_substr')) {
        return $length === null
            ? mb_substr((string) $value, (int) $start, null, 'UTF-8')
            : mb_substr((string) $value, (int) $start, (int) $length, 'UTF-8');
    }

    return $length === null
        ? substr((string) $value, (int) $start)
        : substr((string) $value, (int) $start, (int) $length);
}

function RADIO_youtubeWrapText($value, $maxChars, $maxLines)
{
    $value = trim(preg_replace('/[\\r\\n\\t]+/', ' ', (string) $value));
    $value = preg_replace('/\\s{2,}/', ' ', $value);
    $maxChars = max(8, (int) $maxChars);
    $maxLines = max(1, (int) $maxLines);

    if ($value === '') {
        return array('');
    }

    $words = preg_split('/\\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words) || empty($words)) {
        return array($value);
    }

    $lines = array();
    $line = '';
    $truncated = false;

    foreach ($words as $index => $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;

        if (RADIO_youtubeTextLength($candidate) <= $maxChars) {
            $line = $candidate;
            continue;
        }

        if ($line !== '') {
            $lines[] = $line;
            $line = '';
            if (count($lines) >= $maxLines) {
                $truncated = true;
                break;
            }
        }

        if (RADIO_youtubeTextLength($word) > $maxChars) {
            $line = RADIO_youtubeTextSubstr($word, 0, $maxChars);
            if (RADIO_youtubeTextLength($word) > $maxChars) {
                $truncated = true;
            }
        } else {
            $line = $word;
        }
    }

    if (!$truncated && $line !== '' && count($lines) < $maxLines) {
        $lines[] = $line;
    } elseif ($line !== '' && count($lines) < $maxLines) {
        $lines[] = $line;
    }

    if (empty($lines)) {
        $lines[] = RADIO_youtubeTextSubstr($value, 0, $maxChars);
        $truncated = RADIO_youtubeTextLength($value) > $maxChars;
    }

    if ($truncated) {
        $last = count($lines) - 1;
        $ellipsis = '…';
        $limit = max(1, $maxChars - RADIO_youtubeTextLength($ellipsis));
        $lines[$last] = rtrim(RADIO_youtubeTextSubstr($lines[$last], 0, $limit)) . $ellipsis;
    }

    return array_slice($lines, 0, $maxLines);
}

function RADIO_youtubeAssWrappedText($value, $maxChars, $maxLines)
{
    $lines = RADIO_youtubeWrapText($value, $maxChars, $maxLines);
    $escaped = array();

    foreach ($lines as $line) {
        $escaped[] = RADIO_youtubeAssText($line);
    }

    return implode('\\N', $escaped);
}

function RADIO_youtubeProgramCoverPath($programId)
{
    $program = RADIO_getProgram((int) $programId, true);
    if ($program === false || empty($program['cover_name'])) {
        return '';
    }

    $path = RADIO_coverDir() . basename((string) $program['cover_name']);
    return is_file($path) && is_readable($path) ? $path : '';
}

function RADIO_youtubeMediaCoverPath($mediaId)
{
    $media = RADIO_getMedia((int) $mediaId, true);
    if ($media === false || empty($media['cover_name'])) {
        return '';
    }

    $path = RADIO_coverDir() . basename((string) $media['cover_name']);
    return is_file($path) && is_readable($path) ? $path : '';
}

function RADIO_youtubeArtwork($target)
{
    $programCover = RADIO_youtubeProgramCoverPath((int) $target['program_id']);
    if ($programCover !== '') {
        return array(
            'type' => 'program',
            'path' => $programCover
        );
    }

    $elapsed = isset($target['elapsed']) ? max(0, (int) $target['elapsed']) : 0;
    $media = RADIO_resolveProgramPlayback((int) $target['program_id'], $elapsed);
    if ($media !== false && !empty($media['media_id'])) {
        $mediaCover = RADIO_youtubeMediaCoverPath((int) $media['media_id']);
        if ($mediaCover !== '') {
            return array(
                'type' => 'media',
                'path' => $mediaCover
            );
        }
    }

    return array(
        'type' => 'none',
        'path' => ''
    );
}

function RADIO_youtubeWriteAss($target, &$error)
{
    global $_CONF;

    $error = '';
    $config = RADIO_youtubeConfig();
    $size = isset($config['video_size']) ? (string) $config['video_size'] : '1280x720';
    $width = 1280;
    $height = 720;
    if (preg_match('/^(\\d+)x(\\d+)$/', $size, $m)) {
        $width = max(320, (int) $m[1]);
        $height = max(180, (int) $m[2]);
    }

    $defaultStation = isset($_CONF['site_name']) && trim((string) $_CONF['site_name']) !== ''
        ? (string) $_CONF['site_name']
        : 'Radio';
    $station = $config['station_name'] !== '' ? $config['station_name'] : $defaultStation;
    $program = isset($target['program_title']) ? (string) $target['program_title'] : '';
    $elapsed = isset($target['elapsed']) ? max(0, (int) $target['elapsed']) : 0;

    $header = "[Script Info]\n"
        . "ScriptType: v4.00+\n"
        . "PlayResX: " . $width . "\n"
        . "PlayResY: " . $height . "\n"
        . "WrapStyle: 2\n\n"
        . "[V4+ Styles]\n"
        . "Format: Name,Fontname,Fontsize,PrimaryColour,SecondaryColour,OutlineColour,BackColour,Bold,Italic,Underline,StrikeOut,ScaleX,ScaleY,Spacing,Angle,BorderStyle,Outline,Shadow,Alignment,MarginL,MarginR,MarginV,Encoding\n"
        . "Style: Station,DejaVu Sans,46,&H00FFFFFF,&H000000FF,&H80000000,&H00000000,-1,0,0,0,100,100,0,0,1,2,0,8,260,260,60,1\n"
        . "Style: Program,DejaVu Sans,30,&H00D8E6F3,&H000000FF,&H80000000,&H00000000,0,0,0,0,100,100,0,0,1,1,0,8,260,260,135,1\n"
        . "Style: Track,DejaVu Sans,30,&H00FFFFFF,&H000000FF,&H80000000,&H00000000,-1,0,0,0,100,100,0,0,1,2,0,2,250,250,210,1\n\n"
        . "[Events]\n"
        . "Format: Layer,Start,End,Style,Name,MarginL,MarginR,MarginV,Effect,Text\n";

    $events = '';
    $longEnd = RADIO_youtubeAssTime(86400);
    if ($config['show_station'] && $station !== '') {
        $events .= 'Dialogue: 0,0:00:00.00,' . $longEnd . ',Station,,0,0,0,,' . RADIO_youtubeAssText($station) . "\n";
    }
    if ($config['show_program'] && $program !== '') {
        $events .= 'Dialogue: 0,0:00:00.00,' . $longEnd . ',Program,,0,0,0,,' . RADIO_youtubeAssWrappedText($program, 34, 2) . "\n";
    }

    $items = RADIO_getProgramItems((int) $target['program_id']);
    $cursor = 0;
    foreach ($items as $item) {
        if (!RADIO_isBroadcastAvailable($item) || (int) $item['duration'] < 1) {
            continue;
        }
        $duration = max(1, (int) $item['duration']);
        $itemStart = $cursor;
        $itemEnd = $cursor + $duration;
        $cursor = $itemEnd;

        if ($itemEnd <= $elapsed) {
            continue;
        }
        $start = max(0, $itemStart - $elapsed);
        $end = max($start + 1, $itemEnd - $elapsed);
        $mediaType = isset($item['media_type']) ? (string) $item['media_type'] : '';
        if ($mediaType === 'jingle' || !$config['show_track']) {
            continue;
        }

        $title = isset($item['title']) ? (string) $item['title'] : '';
        if ($title === '') {
            continue;
        }
        $events .= 'Dialogue: 0,' . RADIO_youtubeAssTime($start) . ',' . RADIO_youtubeAssTime($end)
            . ',Track,,0,0,0,,' . RADIO_youtubeAssWrappedText($title, 32, 3) . "\n";
    }

    $path = RADIO_youtubeAssPath();
    if (@file_put_contents($path, $header . $events, LOCK_EX) === false) {
        $error = 'youtube_ass_write_failed';
        return false;
    }
    @chmod($path, 0600);
    return $path;
}

function RADIO_youtubeFfmpegHasFilter($ffmpegPath, $filter)
{
    $ffmpegPath = trim((string) $ffmpegPath);
    $filter = trim((string) $filter);
    if ($ffmpegPath === '' || $filter === '') {
        return false;
    }

    $output = array();
    $code = 1;
    @exec(escapeshellarg($ffmpegPath) . ' -hide_banner -filters 2>/dev/null', $output, $code);
    if ($code !== 0) {
        return false;
    }

    foreach ($output as $line) {
        if (preg_match('/\\b' . preg_quote($filter, '/') . '\\b/', (string) $line)) {
            return true;
        }
    }
    return false;
}

function RADIO_youtubeFfmpegCommand($target, &$error, $ffmpegPath = 'ffmpeg', $videoMode = 'auto')
{
    $error = '';
    $config = RADIO_youtubeConfig();
    $concat = RADIO_youtubeWriteConcat($target['program_id'], $error);
    if ($concat === false) {
        return false;
    }

    $destination = rtrim($config['rtmp_url'], '/') . '/' . ltrim($config['stream_key'], '/');
    $ffmpegPath = trim((string) $ffmpegPath);
    if ($ffmpegPath === '') {
        $ffmpegPath = 'ffmpeg';
    }
    $videoMode = in_array($videoMode, array('stationcard', 'compactwaves', 'drawtext', 'showwaves', 'showspectrum', 'color'), true)
        ? $videoMode
        : 'color';

    $parts = array(
        $ffmpegPath,
        '-hide_banner',
        '-loglevel', 'warning',
        '-re'
    );
    if (!empty($target['elapsed'])) {
        $parts[] = '-ss';
        $parts[] = (string) (int) $target['elapsed'];
    }
    $parts = array_merge($parts, array(
        '-f', 'concat',
        '-safe', '0',
        '-i', $concat
    ));

    if ($videoMode === 'stationcard') {
        $assError = '';
        $ass = RADIO_youtubeWriteAss($target, $assError);
        if ($ass === false) {
            $error = $assError;
            return false;
        }

        $template = isset($config['visual_template']) ? (string) $config['visual_template'] : 'stationcard';
        $showArtwork = !empty($config['show_artwork']);
        $showVisualizer = !empty($config['show_visualizer']);
        $visualizerSize = isset($config['visualizer_size']) ? (string) $config['visualizer_size'] : 'medium';

        $waveWidth = 760;
        $waveHeight = 80;
        if ($visualizerSize === 'small') {
            $waveWidth = 620;
            $waveHeight = 50;
        } elseif ($visualizerSize === 'large') {
            $waveWidth = 960;
            $waveHeight = 120;
        }

        $videoWidth = 1280;
        $videoHeight = 720;
        if (preg_match('/^(\\d+)x(\\d+)$/', (string) $config['video_size'], $sizeMatch)) {
            $videoWidth = max(320, (int) $sizeMatch[1]);
            $videoHeight = max(180, (int) $sizeMatch[2]);
        }

        $artwork = RADIO_youtubeArtwork($target);
        $cover = $showArtwork && isset($artwork['path']) ? (string) $artwork['path'] : '';

        $parts = array_merge($parts, array(
            '-f', 'lavfi',
            '-i', 'color=c=0x101820:s=' . $config['video_size'] . ':r=25'
        ));

        if ($cover !== '') {
            $parts = array_merge($parts, array(
                '-loop', '1',
                '-framerate', '1',
                '-i', $cover
            ));
        }

        $filters = array('[0:a]asplit=2[aout][awave]');

        if ($showVisualizer) {
            $filters[] = '[awave]showwaves=s=' . $waveWidth . 'x' . $waveHeight
                . ':mode=line:rate=25:colors=0xD8E6F3[wave]';
        } else {
            $filters[] = '[awave]anullsink';
        }

        if ($template === 'fullbackground' && $cover !== '') {
            $filters[] = '[2:v]scale=' . $videoWidth . ':' . $videoHeight
                . ':force_original_aspect_ratio=increase,crop=' . $videoWidth . ':' . $videoHeight
                . ',eq=brightness=-0.28[background]';
            $filters[] = "[background]subtitles='" . RADIO_youtubeFilterPath($ass) . "'[card]";
        } elseif ($template === 'minimal' || $template === 'visualizer') {
            $filters[] = "[1:v]subtitles='" . RADIO_youtubeFilterPath($ass) . "'[card]";
        } elseif ($cover !== '') {
            $filters[] = '[2:v]scale=320:320:force_original_aspect_ratio=decrease[cover]';
            $filters[] = '[1:v][cover]overlay=(W-w)/2:250[background]';
            $filters[] = "[background]subtitles='" . RADIO_youtubeFilterPath($ass) . "'[card]";
        } else {
            $filters[] = "[1:v]subtitles='" . RADIO_youtubeFilterPath($ass) . "'[card]";
        }

        if ($showVisualizer) {
            $waveBottom = $template === 'visualizer' ? 105 : 55;
            $filters[] = '[card][wave]overlay=(W-w)/2:H-h-' . $waveBottom . '[v]';
        } else {
            $filters[] = '[card]null[v]';
        }

        $parts = array_merge($parts, array(
            '-filter_complex', implode(';', $filters),
            '-map', '[v]',
            '-map', '[aout]'
        ));
    } elseif ($videoMode === 'compactwaves') {
        $parts = array_merge($parts, array(
            '-f', 'lavfi',
            '-i', 'color=c=0x101820:s=' . $config['video_size'] . ':r=25',
            '-filter_complex',
            '[0:a]asplit=2[aout][awave];'
            . '[awave]showwaves=s=860x90:mode=line:rate=25:colors=0xD8E6F3[wave];'
            . '[1:v][wave]overlay=(W-w)/2:H-h-65[v]',
            '-map', '[v]',
            '-map', '[aout]'
        ));
    } elseif ($videoMode === 'drawtext') {
        $parts = array_merge($parts, array(
            '-f', 'lavfi',
            '-i', 'color=c=0x101820:s=' . $config['video_size'] . ':r=25',
            '-map', '1:v:0',
            '-map', '0:a:0',
            '-vf',
            "drawtext=font=Sans:textfile='" . RADIO_youtubeFilterPath(RADIO_youtubeOverlayPath('station')) . "':reload=1:fontcolor=white:fontsize=52:x=(w-text_w)/2:y=h*0.24,"
            . "drawtext=font=Sans:textfile='" . RADIO_youtubeFilterPath(RADIO_youtubeOverlayPath('program')) . "':reload=1:fontcolor=white:fontsize=38:x=(w-text_w)/2:y=h*0.42,"
            . "drawtext=font=Sans:textfile='" . RADIO_youtubeFilterPath(RADIO_youtubeOverlayPath('track')) . "':reload=1:fontcolor=white:fontsize=32:x=(w-text_w)/2:y=h*0.58"
        ));
    } elseif ($videoMode === 'showwaves') {
        $parts = array_merge($parts, array(
            '-filter_complex',
            '[0:a]showwaves=s=' . $config['video_size'] . ':mode=line:rate=25:colors=white[v]',
            '-map', '[v]',
            '-map', '0:a:0'
        ));
    } elseif ($videoMode === 'showspectrum') {
        $parts = array_merge($parts, array(
            '-filter_complex',
            '[0:a]showspectrum=s=' . $config['video_size'] . ':mode=combined:color=intensity:slide=scroll:fps=25[v]',
            '-map', '[v]',
            '-map', '0:a:0'
        ));
    } else {
        $parts = array_merge($parts, array(
            '-f', 'lavfi',
            '-i', 'color=c=0x101820:s=' . $config['video_size'] . ':r=25',
            '-map', '1:v:0',
            '-map', '0:a:0'
        ));
    }

    $parts = array_merge($parts, array(
        '-c:v', 'libx264',
        '-preset', 'veryfast',
        '-tune', 'stillimage',
        '-pix_fmt', 'yuv420p',
        '-g', '50',
        '-b:v', $config['video_bitrate'],
        '-minrate', $config['video_bitrate'],
        '-maxrate', $config['video_bitrate'],
        '-bufsize', '5000k',
        '-x264-params', 'nal-hrd=cbr:force-cfr=1',
        '-c:a', 'aac',
        '-b:a', $config['audio_bitrate'],
        '-ar', '44100'
    ));
    if (!empty($target['remaining'])) {
        $parts[] = '-t';
        $parts[] = (string) (int) $target['remaining'];
    }
    $parts = array_merge($parts, array('-f', 'flv', $destination));

    $escaped = array();
    foreach ($parts as $part) {
        $escaped[] = escapeshellarg($part);
    }
    return implode(' ', $escaped);
}

function RADIO_youtubePidRunning($pid)
{
    $pid = (int) $pid;
    if ($pid < 2) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    $output = array();
    $code = 1;
    @exec('kill -0 ' . $pid . ' 2>/dev/null', $output, $code);
    return $code === 0;
}

function RADIO_youtubeStopPid($pid)
{
    $pid = (int) $pid;
    if ($pid < 2 || !RADIO_youtubePidRunning($pid)) {
        return true;
    }
    @exec('kill -TERM ' . $pid . ' 2>/dev/null');
    for ($i = 0; $i < 10; $i++) {
        usleep(100000);
        if (!RADIO_youtubePidRunning($pid)) {
            return true;
        }
    }
    @exec('kill -KILL ' . $pid . ' 2>/dev/null');
    return !RADIO_youtubePidRunning($pid);
}
