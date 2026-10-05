<?php

require_once dirname(__FILE__) . '/studio-output.inc.php';
require_once dirname(__FILE__) . '/youtube.inc.php';

function RADIO_studioYoutubeTrace($message)
{
    global $_CONF;

    $line = '[' . date('Y-m-d H:i:s') . '] [Radio Studio YouTube] '
        . trim((string) $message) . "\n";

    $youtubePath = function_exists('RADIO_youtubeLogPath') ? RADIO_youtubeLogPath() : '';
    if ($youtubePath !== '') {
        @file_put_contents($youtubePath, $line, FILE_APPEND | LOCK_EX);
    }

    $logDir = isset($_CONF['path_log']) ? rtrim((string) $_CONF['path_log'], '/\\') : '';
    if ($logDir !== '') {
        @file_put_contents(
            $logDir . DIRECTORY_SEPARATOR . 'radio.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    error_log(trim($line));
}

function RADIO_studioYoutubeDir()
{
    $base = RADIO_studioSiteStorageDir();
    return $base === '' ? '' : $base . 'studio-youtube' . DIRECTORY_SEPARATOR;
}

function RADIO_studioYoutubeEnsureStorage()
{
    if (!RADIO_studioEnsureSiteStorage()) {
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
    if ($helperPid > 1 && RADIO_youtubePidRunning($helperPid)) {
        return $status;
    }

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

    if ($fresh) {
        return $status;
    }

    $ffmpegPid = isset($status['ffmpeg_pid']) ? (int) $status['ffmpeg_pid'] : 0;
    if ($ffmpegPid > 1 && RADIO_youtubePidRunning($ffmpegPid)) {
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

function RADIO_studioYoutubeFindPhpCli()
{
    $candidates = array(
        '/usr/bin/php',
        '/usr/local/bin/php',
        '/usr/bin/php8.1',
        '/usr/local/bin/php8.1',
        '/usr/bin/php81',
        '/usr/local/bin/php81',
        'php-cli',
        'php'
    );

    foreach ($candidates as $candidate) {
        $output = array();
        $code = 1;
        @exec(escapeshellcmd($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>/dev/null', $output, $code);
        if ($code === 0 && isset($output[0]) && trim((string) $output[0]) === 'cli') {
            return $candidate;
        }
    }

    return '';
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
    $mimeInfo = RADIO_studioRecordingMimeInfo($mime);
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
    $sessionId = RADIO_studioRecordingSessionId();
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

    RADIO_studioYoutubeTrace('Detecting PHP CLI.');
    $phpCli = RADIO_studioYoutubeFindPhpCli();
    if ($phpCli === '') {
        @unlink($inputPath);
        $error = 'youtube_cli_not_detected';
        return false;
    }

    $worker = isset($_CONF['path']) ? rtrim((string) $_CONF['path'], '/\\')
        . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'radio'
        . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'studio-youtube-live.php' : '';
    $root = isset($_CONF['path_html']) ? rtrim((string) $_CONF['path_html'], '/\\') : '';
    if ($worker === '' || !is_file($worker) || $root === '' || !is_file($root . DIRECTORY_SEPARATOR . 'lib-common.php')) {
        @unlink($inputPath);
        $error = 'studio_youtube_worker_missing';
        return false;
    }

    $siteHost = '';
    if (!empty($_CONF['site_url'])) {
        $parsedHost = parse_url((string) $_CONF['site_url'], PHP_URL_HOST);
        if (is_string($parsedHost)) {
            $siteHost = trim($parsedHost);
        }
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

    $command = escapeshellcmd($phpCli) . ' ' . escapeshellarg($worker)
        . ' --geeklog-root=' . escapeshellarg($root)
        . ($siteHost !== '' ? ' --host=' . escapeshellarg($siteHost) : '')
        . ' --session=' . escapeshellarg($sessionId);

    $logDir = isset($_CONF['path_log']) ? rtrim((string) $_CONF['path_log'], '/\\') : '';
    $logPath = $logDir !== '' ? $logDir . DIRECTORY_SEPARATOR . 'radio.log' : '/dev/null';

    $output = array();
    $code = 1;
    RADIO_studioYoutubeTrace('Launching detached Studio YouTube helper.');
    @exec('nohup ' . $command . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null & echo $!', $output, $code);
    $helperPid = $code === 0 && isset($output[0]) ? (int) trim((string) $output[0]) : 0;

    if ($helperPid < 2) {
        @unlink($inputPath);
        RADIO_studioYoutubeWriteStatus(array(
            'state' => 'error',
            'last_error' => 'studio_youtube_start_failed'
        ));
        $error = 'studio_youtube_start_failed';
        return false;
    }

    RADIO_studioYoutubeWriteStatus(array('helper_pid' => $helperPid));
    RADIO_studioYoutubeTrace(
        'Detached helper launched with PID ' . (int) $helperPid
        . ' for session ' . $sessionId . '.'
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

    RADIO_studioYoutubeWriteStatus(array(
        'last_chunk_at' => date('Y-m-d H:i:s'),
        'chunks' => (int) $status['chunks'] + 1,
        'bytes' => (int) $status['bytes'] + $size
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
