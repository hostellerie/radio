<?php

require_once dirname(__FILE__) . '/youtube.inc.php';
require_once dirname(__FILE__) . '/studio-log.inc.php';
function RADIO_studioYoutubeSiteStorageDir()
{
    global $_CONF;

    $base = isset($_CONF['path_data']) ? rtrim((string) $_CONF['path_data'], "/\\") : '';
    if ($base === '') {
        return '';
    }

    return dirname($base) . DIRECTORY_SEPARATOR . basename($base) . '-radio' . DIRECTORY_SEPARATOR;
}

function RADIO_studioYoutubeEnsureSiteStorage()
{
    $dir = RADIO_studioYoutubeSiteStorageDir();
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        COM_errorLog('Radio: cannot create site-specific Studio storage directory ' . $dir, 1);
        return false;
    }

    return is_writable($dir);
}

function RADIO_studioYoutubeMimeInfo($mime)
{
    $mime = strtolower(trim((string) $mime));
    $base = trim(strtok($mime, ';'));

    $map = array(
        'audio/webm' => array('extension' => 'webm', 'mime' => 'audio/webm'),
        'video/webm' => array('extension' => 'webm', 'mime' => 'audio/webm'),
        'audio/ogg' => array('extension' => 'ogg', 'mime' => 'audio/ogg'),
        'audio/mp4' => array('extension' => 'm4a', 'mime' => 'audio/mp4')
    );

    return isset($map[$base]) ? $map[$base] : false;
}

function RADIO_studioYoutubeSessionId()
{
    return sha1(uniqid('radio-studio-youtube-', true) . mt_rand() . microtime(true));
}

function RADIO_studioYoutubeTrace($message)
{
    RADIO_studioLog('youtube.trace', array(
        'message' => trim((string) $message)
    ));
}

function RADIO_studioYoutubeDir()
{
    $base = RADIO_studioYoutubeSiteStorageDir();
    return $base === '' ? '' : $base . 'studio-youtube' . DIRECTORY_SEPARATOR;
}

function RADIO_studioYoutubeEnsureStorage()
{
    if (!RADIO_studioYoutubeEnsureSiteStorage()) {
        return false;
    }

    $dir = RADIO_studioYoutubeDir();
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        COM_errorLog('Radio: cannot create Studio YouTube directory ' . $dir, 1);
        return false;
    }

    return is_writable($dir);
}

function RADIO_studioYoutubeStatePath()
{
    return RADIO_studioYoutubeDir() . 'state.json';
}

function RADIO_studioYoutubeInputPath($sessionId)
{
    if (!preg_match('/^[a-f0-9]{40}$/', (string) $sessionId)) {
        return '';
    }
    return RADIO_studioYoutubeDir() . $sessionId . '.input';
}

function RADIO_studioYoutubeStatus()
{
    $defaults = array(
        'state' => 'idle',
        'session_id' => '',
        'owner_id' => 0,
        'program_id' => 0,
        'program_title' => '',
        'mime' => '',
        'helper_pid' => 0,
        'ffmpeg_pid' => 0,
        'started_at' => '',
        'last_chunk_at' => '',
        'chunks' => 0,
        'bytes' => 0,
        'stop_requested' => false,
        'last_error' => ''
    );

    $path = RADIO_studioYoutubeStatePath();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return $defaults;
    }

    $raw = @file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return $defaults;
    }

    return array_merge($defaults, $data);
}

function RADIO_studioYoutubeWriteStatus($data)
{
    if (!RADIO_studioYoutubeEnsureStorage()) {
        return false;
    }

    $status = array_merge(RADIO_studioYoutubeStatus(), is_array($data) ? $data : array());
    $path = RADIO_studioYoutubeStatePath();
    $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

function RADIO_studioYoutubeActive($status = null)
{
    if (!is_array($status)) {
        $status = RADIO_studioYoutubeStatus();
    }

    if (!in_array($status['state'], array('starting','live','stopping'), true)) {
        return false;
    }

    $helperPid = isset($status['helper_pid']) ? (int) $status['helper_pid'] : 0;
    if ($helperPid > 1 && function_exists('RADIO_youtubePidRunning')
        && RADIO_youtubePidRunning($helperPid)) {
        return true;
    }

    /*
     * Treat a freshly-created starting/stopping session as owning the ingest
     * even during the short interval before the detached helper PID is saved.
     * This closes the race with the once-per-minute automatic worker.
     */
    $startedAt = !empty($status['started_at']) ? strtotime((string) $status['started_at']) : false;
    if ($startedAt !== false
        && in_array($status['state'], array('starting','stopping'), true)
        && (time() - $startedAt) <= 30) {
        return true;
    }

    $lastChunk = !empty($status['last_chunk_at']) ? strtotime((string) $status['last_chunk_at']) : false;
    return $lastChunk !== false && (time() - $lastChunk) <= 20;
}

function RADIO_studioYoutubeCleanupStale()
{
    $status = RADIO_studioYoutubeStatus();
    if (!in_array($status['state'], array('starting','live','stopping'), true)) {
        return $status;
    }

    $helperPid = isset($status['helper_pid']) ? (int) $status['helper_pid'] : 0;
    $helperRunning = $helperPid > 1 && RADIO_youtubePidRunning($helperPid);

    $now = time();
    $startedAt = !empty($status['started_at']) ? strtotime((string) $status['started_at']) : false;
    $lastChunkAt = !empty($status['last_chunk_at']) ? strtotime((string) $status['last_chunk_at']) : false;

    $fresh = false;
    if ($status['state'] === 'starting' && $startedAt !== false && ($now - $startedAt) <= 30) {
        $fresh = true;
    } elseif ($status['state'] === 'live' && $lastChunkAt !== false && ($now - $lastChunkAt) <= 20) {
        $fresh = true;
    } elseif ($status['state'] === 'stopping' && $startedAt !== false && ($now - $startedAt) <= 30) {
        $fresh = true;
    }

    if ($fresh && $helperRunning) {
        return $status;
    }

    /*
     * A fresh session whose encoder already disappeared is an immediate error.
     * An old session is stale even when its detached process group is still
     * running; this is how browser/network loss is bounded without a PHP worker.
     */
    if ($helperRunning) {
        if (!RADIO_studioYoutubeStopEncoder($helperPid)) {
            RADIO_studioYoutubeWriteStatus(array('state' => 'stopping',
                'stop_requested' => true, 'last_error' => 'studio_youtube_stop_failed'));
            return RADIO_studioYoutubeStatus();
        }
    }

    $ffmpegPid = isset($status['ffmpeg_pid']) ? (int) $status['ffmpeg_pid'] : 0;
    if ($ffmpegPid > 1 && $ffmpegPid !== $helperPid && RADIO_youtubePidRunning($ffmpegPid)) {
        RADIO_youtubeStopPid($ffmpegPid);
    }

    $sessionId = isset($status['session_id']) ? (string) $status['session_id'] : '';
    $inputPath = RADIO_studioYoutubeInputPath($sessionId);
    if ($inputPath !== '' && is_file($inputPath)) {
        @unlink($inputPath);
    }

    $nextState = $status['state'] === 'stopping' ? 'idle' : 'error';
    $lastError = $status['state'] === 'stopping'
        ? ''
        : 'studio_youtube_stale_session';

    RADIO_studioYoutubeWriteStatus(array(
        'state' => $nextState,
        'helper_pid' => 0,
        'ffmpeg_pid' => 0,
        'stop_requested' => false,
        'last_error' => $lastError
    ));

    return RADIO_studioYoutubeStatus();
}

function RADIO_studioYoutubeFindFfmpeg()
{
    /*
     * The automatic YouTube worker runs from CLI and may see a broader PATH
     * than the web PHP process. Reuse its last proven FFmpeg path first instead
     * of assuming both environments are identical.
     */
    $youtubeStatus = function_exists('RADIO_youtubeStatus')
        ? RADIO_youtubeStatus()
        : array();
    $knownPath = isset($youtubeStatus['ffmpeg_path'])
        ? trim((string) $youtubeStatus['ffmpeg_path'])
        : '';

    $homeDir = rtrim((string) getenv('HOME'), '/\\');
    $candidates = array(
        $knownPath,
        getenv('FFMPEG_BIN'),
        $homeDir !== '' ? $homeDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg' : '',
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/local/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg'
    );

    if (function_exists('exec')) {
        $output = array();
        $code = 1;
        @exec('command -v ffmpeg 2>/dev/null', $output, $code);
        if ($code === 0 && isset($output[0])) {
            array_unshift($candidates, trim((string) $output[0]));
        }
    }

    $checked = array();
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '' || isset($checked[$candidate])) {
            continue;
        }
        $checked[$candidate] = true;

        if (@is_file($candidate) && @is_executable($candidate)) {
            RADIO_studioYoutubeTrace('FFmpeg resolved to ' . $candidate . '.');
            return $candidate;
        }
    }

    RADIO_studioYoutubeTrace(
        'FFmpeg not found. Known CLI path='
        . ($knownPath !== '' ? $knownPath : '[none]')
        . '; web PATH=' . (string) getenv('PATH')
        . '; HOME=' . (string) getenv('HOME')
        . '; candidates=' . implode(',', array_keys($checked))
    );

    return '';
}

function RADIO_studioYoutubeStopEncoder($pid)
{
    $pid = (int) $pid;
    if ($pid < 2) {
        return true;
    }

    /*
     * Studio Live is launched with setsid, so the returned PID is also the
     * process-group id. Stop the whole tail -> FFmpeg pipeline in one action.
     */
    // Check the entire process group, since its shell leader can exit first.
    // The double dash prevents negative PGIDs from being parsed as options.
    $target = '-- -' . $pid;
    $out = array();
    $code = 1;
    @exec('/bin/kill -0 ' . $target . ' 2>/dev/null', $out, $code);
    if ($code !== 0) {
        return true;
    }
    @exec('/bin/kill -TERM ' . $target . ' 2>/dev/null');
    for ($i = 0; $i < 15; $i++) {
        usleep(100000);
        $code = 1;
        @exec('/bin/kill -0 ' . $target . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return true;
        }
    }
    @exec('/bin/kill -KILL ' . $target . ' 2>/dev/null');
    for ($i = 0; $i < 10; $i++) {
        usleep(100000);
        $code = 1;
        @exec('/bin/kill -0 ' . $target . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return true;
        }
    }
    return false;
}

function RADIO_studioYoutubeStart($programId, $mime, $uid, &$error)
{
    global $_CONF;

    $error = '';
    RADIO_studioYoutubeTrace(
        'Start requested for programme ' . (int) $programId
        . ' by user ' . (int) $uid
        . ' with MIME ' . (string) $mime . '.'
    );
    RADIO_studioYoutubeTrace('Resolving Studio MIME.');
    $mimeInfo = RADIO_studioYoutubeMimeInfo($mime);
    if ($mimeInfo === false) {
        $error = 'studio_recording_mime_unsupported';
        RADIO_studioYoutubeTrace('Start rejected: ' . $error . '.');
        return false;
    }

    RADIO_studioYoutubeTrace('Loading YouTube configuration.');
    $config = RADIO_youtubeConfig();
    if (empty($config['enabled'])) {
        $error = 'youtube_disabled';
        RADIO_studioYoutubeTrace('Start rejected: ' . $error . '.');
        return false;
    }
    if (empty($config['stream_key'])) {
        $error = 'youtube_stream_key_missing';
        RADIO_studioYoutubeTrace('Start rejected: ' . $error . '.');
        return false;
    }

    RADIO_studioYoutubeTrace('Loading programme.');
    $program = RADIO_getProgram((int) $programId, false);
    if ($program === false) {
        $error = 'youtube_program_missing';
        RADIO_studioYoutubeTrace('Start rejected: ' . $error . '.');
        return false;
    }

    RADIO_studioYoutubeTrace('Checking existing Studio YouTube session.');
    $current = RADIO_studioYoutubeCleanupStale();
    if (RADIO_studioYoutubeActive($current)) {
        $error = 'studio_youtube_already_live';
        RADIO_studioYoutubeTrace('Start rejected: ' . $error . '.');
        return false;
    }

    RADIO_studioYoutubeTrace('Checking Studio YouTube storage.');
    if (!RADIO_studioYoutubeEnsureStorage()) {
        $error = 'storage_unavailable';
        RADIO_studioYoutubeTrace('Start rejected: ' . $error . '.');
        return false;
    }

    /*
     * Studio owns the single YouTube ingest while it is active. Stop any
     * automatic/manual programme encoder before launching the Studio helper.
     */
    RADIO_studioYoutubeTrace('Checking existing automatic YouTube encoder.');
    $automatic = RADIO_youtubeStatus();
    $automaticPid = isset($automatic['pid']) ? (int) $automatic['pid'] : 0;
    if ($automaticPid > 1 && RADIO_youtubePidRunning($automaticPid)) {
        RADIO_youtubeStopPid($automaticPid);
        RADIO_youtubeWriteStatus(array(
            'running' => false,
            'pid' => 0,
            'target_key' => '',
            'last_error' => ''
        ));
    }

    RADIO_studioYoutubeTrace('Creating Studio YouTube session.');
    $sessionId = RADIO_studioYoutubeSessionId();
    $inputPath = RADIO_studioYoutubeInputPath($sessionId);
    if ($inputPath === '' || @file_put_contents($inputPath, '') === false) {
        $error = 'studio_youtube_start_failed';
        return false;
    }
    @chmod($inputPath, 0600);

    RADIO_studioYoutubeTrace('Writing Studio YouTube overlay.');
    if (!RADIO_youtubeWriteStudioOverlay((int) $programId, '')) {
        @unlink($inputPath);
        $error = 'youtube_overlay_write_failed';
        return false;
    }

    RADIO_studioYoutubeTrace('Detecting FFmpeg.');
    $ffmpegPath = RADIO_studioYoutubeFindFfmpeg();
    if ($ffmpegPath === '') {
        @unlink($inputPath);
        $error = 'youtube_ffmpeg_missing';
        return false;
    }

    $videoMode = 'color';
    if (RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'showwaves')
        && RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'overlay')
        && RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'drawtext')) {
        $videoMode = 'stationcard';
    } elseif (RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'drawtext')) {
        $videoMode = 'drawtext';
    } elseif (RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'showwaves')
        && RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'overlay')) {
        $videoMode = 'showwaves';
    }

    $ffmpegError = '';
    $ffmpegCommand = RADIO_youtubeStudioFfmpegCommand(
        (int) $programId,
        $ffmpegError,
        $ffmpegPath,
        $videoMode
    );
    if ($ffmpegCommand === false) {
        @unlink($inputPath);
        $error = $ffmpegError !== '' ? $ffmpegError : 'youtube_ffmpeg_start_failed';
        return false;
    }

    $status = array(
        'state' => 'starting',
        'session_id' => $sessionId,
        'owner_id' => max(0, (int) $uid),
        'program_id' => (int) $programId,
        'program_title' => isset($program['title']) ? (string) $program['title'] : '',
        'mime' => $mimeInfo['mime'],
        'helper_pid' => 0,
        'ffmpeg_pid' => 0,
        'started_at' => date('Y-m-d H:i:s'),
        'last_chunk_at' => '',
        'chunks' => 0,
        'bytes' => 0,
        'stop_requested' => false,
        'last_error' => ''
    );

    RADIO_studioYoutubeTrace('Writing initial Studio YouTube state.');
    if (!RADIO_studioYoutubeWriteStatus($status)) {
        @unlink($inputPath);
        $error = 'studio_youtube_start_failed';
        return false;
    }

    $logPath = RADIO_youtubeLogPath();
    @file_put_contents($logPath, '', LOCK_EX);

    /*
     * Keep the web request short: a detached process group owns the append-only
     * relay file and FFmpeg. There is no second PHP process and no second
     * Geeklog bootstrap. tail follows bytes appended by bounded upload requests
     * and feeds the one persistent FFmpeg/RTMPS session through stdin.
     */
    $pipeline = 'tail -c +1 -F ' . escapeshellarg($inputPath)
        . ' | ' . $ffmpegCommand;

    $output = array();
    $code = 1;
    RADIO_studioYoutubeTrace('Launching detached Studio YouTube encoder pipeline.');
    @exec(
        'nohup setsid sh -c ' . escapeshellarg($pipeline)
        . ' >> ' . escapeshellarg($logPath)
        . ' 2>&1 < /dev/null & echo $!',
        $output,
        $code
    );
    $encoderPid = $code === 0 && isset($output[0]) ? (int) trim((string) $output[0]) : 0;

    if ($encoderPid < 2) {
        @unlink($inputPath);
        RADIO_studioYoutubeWriteStatus(array(
            'state' => 'error',
            'last_error' => 'youtube_ffmpeg_start_failed'
        ));
        $error = 'youtube_ffmpeg_start_failed';
        return false;
    }

    RADIO_studioYoutubeWriteStatus(array(
        'helper_pid' => $encoderPid,
        'ffmpeg_pid' => 0
    ));
    RADIO_studioYoutubeTrace(
        'Detached Studio encoder pipeline launched with process-group PID '
        . (int) $encoderPid . ' for session ' . $sessionId . '.'
    );
    return RADIO_studioYoutubeStatus();
}

function RADIO_studioYoutubeAppend($sessionId, $tmpPath, $size, $chunkIndex, $uid, &$error)
{
    $error = '';
    $status = RADIO_studioYoutubeStatus();

    if ((string) $status['session_id'] !== (string) $sessionId
        || !in_array($status['state'], array('starting','live'), true)) {
        $error = 'studio_youtube_session_invalid';
        return false;
    }
    if ((int) $status['owner_id'] !== (int) $uid) {
        $error = 'access_denied';
        return false;
    }

    $size = (int) $size;
    if ($size < 1 || $size > 8388608 || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $error = 'studio_youtube_chunk_invalid';
        return false;
    }
    if ((int) $chunkIndex !== (int) $status['chunks']) {
        $error = 'studio_youtube_chunk_order';
        return false;
    }

    $source = @fopen($tmpPath, 'rb');
    $target = @fopen(RADIO_studioYoutubeInputPath($sessionId), 'ab');
    if ($source === false || $target === false) {
        if (is_resource($source)) {
            @fclose($source);
        }
        if (is_resource($target)) {
            @fclose($target);
        }
        $error = 'studio_youtube_write_failed';
        return false;
    }

    if (!@flock($target, LOCK_EX)) {
        @fclose($source);
        @fclose($target);
        $error = 'studio_youtube_write_failed';
        return false;
    }

    $written = stream_copy_to_stream($source, $target);
    @fflush($target);
    @flock($target, LOCK_UN);
    @fclose($source);
    @fclose($target);

    if ($written === false || (int) $written !== $size) {
        $error = 'studio_youtube_write_failed';
        return false;
    }

    $nextState = $status['state'] === 'starting' ? 'live' : $status['state'];
    RADIO_studioYoutubeWriteStatus(array(
        'state' => $nextState,
        'last_chunk_at' => date('Y-m-d H:i:s'),
        'chunks' => (int) $status['chunks'] + 1,
        'bytes' => (int) $status['bytes'] + $size,
        'last_error' => ''
    ));

    return RADIO_studioYoutubeStatus();
}

function RADIO_studioYoutubeUpdateMetadata($sessionId, $trackTitle, $uid, &$error)
{
    $error = '';
    $status = RADIO_studioYoutubeStatus();
    if ((string) $status['session_id'] !== (string) $sessionId
        || !in_array($status['state'], array('starting','live'), true)) {
        $error = 'studio_youtube_session_invalid';
        return false;
    }
    if ((int) $status['owner_id'] !== (int) $uid) {
        $error = 'access_denied';
        return false;
    }

    if (!RADIO_youtubeWriteStudioOverlay((int) $status['program_id'], (string) $trackTitle)) {
        $error = 'youtube_overlay_write_failed';
        return false;
    }

    return true;
}

function RADIO_studioYoutubeRequestStop($sessionId, $uid, &$error)
{
    $error = '';
    $status = RADIO_studioYoutubeStatus();
    if ((string) $status['session_id'] !== (string) $sessionId
        || !in_array($status['state'], array('starting','live','stopping'), true)) {
        $error = 'studio_youtube_session_invalid';
        return false;
    }
    if ((int) $status['owner_id'] !== (int) $uid) {
        $error = 'access_denied';
        return false;
    }

    if (!RADIO_studioYoutubeWriteStatus(array(
        'state' => 'stopping',
        'stop_requested' => true
    ))) {
        $error = 'studio_youtube_stop_failed';
        return false;
    }

    /*
     * The browser serializes chunk uploads before the stop request. Give tail a
     * short bounded drain window, then stop the complete detached process group.
     */
    usleep(500000);
    $encoderPid = isset($status['helper_pid']) ? (int) $status['helper_pid'] : 0;
    if (!RADIO_studioYoutubeStopEncoder($encoderPid)) {
        RADIO_studioYoutubeWriteStatus(array('last_error' => 'studio_youtube_stop_failed'));
        $error = 'studio_youtube_stop_failed';
        return false;
    }

    $inputPath = RADIO_studioYoutubeInputPath($sessionId);
    if ($inputPath !== '' && is_file($inputPath)) {
        @unlink($inputPath);
    }

    RADIO_studioYoutubeWriteStatus(array(
        'state' => 'idle',
        'helper_pid' => 0,
        'ffmpeg_pid' => 0,
        'stop_requested' => false,
        'last_error' => '',
        'stopped_at' => date('Y-m-d H:i:s')
    ));

    return RADIO_studioYoutubeStatus();
}

function RADIO_studioYoutubePublicStatus()
{
    $status = RADIO_studioYoutubeCleanupStale();
    $metrics = function_exists('RADIO_youtubeFfmpegMetrics')
        ? RADIO_youtubeFfmpegMetrics()
        : array('available' => false);

    return array(
        'state' => (string) $status['state'],
        'session_id' => (string) $status['session_id'],
        'program_id' => (int) $status['program_id'],
        'program_title' => (string) $status['program_title'],
        'started_at' => (string) $status['started_at'],
        'chunks' => (int) $status['chunks'],
        'bytes' => (int) $status['bytes'],
        'last_error' => (string) $status['last_error'],
        'metrics' => array(
            'available' => !empty($metrics['available']),
            'fps' => isset($metrics['fps']) ? $metrics['fps'] : null,
            'bitrate_kbps' => isset($metrics['bitrate_kbps']) ? $metrics['bitrate_kbps'] : null,
            'speed' => isset($metrics['speed']) ? $metrics['speed'] : null
        )
    );
}
