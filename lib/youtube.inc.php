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
            : '128k'
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

    foreach (array('schedule_ids','manual_program_id','manual_requested') as $runtimeKey) {
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
        'manual_requested' => false
    ));

    $runtime['schedule_ids'] = isset($data['schedule_ids']) && is_array($data['schedule_ids'])
        ? array_values(array_unique(array_filter(array_map('intval', $data['schedule_ids']))))
        : array();
    $runtime['manual_program_id'] = isset($data['manual_program_id'])
        ? max(0, (int) $data['manual_program_id'])
        : 0;

    if (!isset($runtime['manual_requested'])) {
        $runtime['manual_requested'] = false;
    }

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
    $station = isset($_CONF['site_name']) && trim((string) $_CONF['site_name']) !== ''
        ? (string) $_CONF['site_name']
        : 'Radio';
    $program = isset($target['program_title']) ? (string) $target['program_title'] : '';
    $track = $media !== false && isset($media['title']) ? (string) $media['title'] : '';

    $files = array(
        'station' => RADIO_youtubeOverlayText($station, 70),
        'program' => RADIO_youtubeOverlayText($program, 90),
        'track' => RADIO_youtubeOverlayText($track, 120)
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

function RADIO_youtubeTarget($timestamp)
{
    $config = RADIO_youtubeConfig();
    if (!$config['enabled'] || trim($config['stream_key']) === '') {
        return false;
    }

    $timestamp = $timestamp ? (int) $timestamp : time();

    if ($config['mode'] === 'manual') {
        if (!$config['manual_requested'] || $config['manual_program_id'] < 1) {
            return false;
        }
        $program = RADIO_getProgram($config['manual_program_id'], false);
        if ($program === false) {
            return false;
        }
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
            return array(
                'key' => 'schedule:' . $scheduleId . ':' . (int) $occurrence['start'],
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

function RADIO_youtubeFfmpegCommand($target, &$error)
{
    $error = '';
    $config = RADIO_youtubeConfig();
    $concat = RADIO_youtubeWriteConcat($target['program_id'], $error);
    if ($concat === false) {
        return false;
    }

    $destination = rtrim($config['rtmp_url'], '/') . '/' . ltrim($config['stream_key'], '/');
    $parts = array(
        'ffmpeg',
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
        '-i', $concat,
        '-f', 'lavfi',
        '-i', 'color=c=0x101820:s=' . $config['video_size'] . ':r=25',
        '-map', '1:v:0',
        '-map', '0:a:0',
        '-vf',
        "drawtext=font=Sans:textfile='" . RADIO_youtubeFilterPath(RADIO_youtubeOverlayPath('station')) . "':reload=1:fontcolor=white:fontsize=52:x=(w-text_w)/2:y=h*0.24,"
        . "drawtext=font=Sans:textfile='" . RADIO_youtubeFilterPath(RADIO_youtubeOverlayPath('program')) . "':reload=1:fontcolor=white:fontsize=38:x=(w-text_w)/2:y=h*0.42,"
        . "drawtext=font=Sans:textfile='" . RADIO_youtubeFilterPath(RADIO_youtubeOverlayPath('track')) . "':reload=1:fontcolor=white:fontsize=32:x=(w-text_w)/2:y=h*0.58",
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
