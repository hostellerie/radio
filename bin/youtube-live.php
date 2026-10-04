<?php
function radio_youtube_worker_stderr($message)
{
    @file_put_contents('php://stderr', (string) $message, FILE_APPEND);
}

if (PHP_SAPI !== 'cli') {
    radio_youtube_worker_stderr(
        "This worker requires PHP CLI. Current SAPI: " . PHP_SAPI . "\n"
    );
    exit(1);
}

$root = getenv('GEEKLOG_ROOT');
$host = getenv('GEEKLOG_HOST');
$quietEnv = getenv('RADIO_YOUTUBE_QUIET');
$quiet = $quietEnv === '1' || strtolower((string) $quietEnv) === 'true';
$args = isset($argv) && is_array($argv) ? $argv : array();

foreach ($args as $arg) {
    if (strpos($arg, '--geeklog-root=') === 0) {
        $root = substr($arg, strlen('--geeklog-root='));
    } elseif (strpos($arg, '--host=') === 0) {
        $host = substr($arg, strlen('--host='));
    } elseif ($arg === '--quiet') {
        $quiet = true;
    }
}

$root = rtrim((string) $root, '/\\');
$host = trim((string) $host);

if ($root === '' || !is_file($root . '/lib-common.php')) {
    radio_youtube_worker_stderr("Set GEEKLOG_ROOT or pass --geeklog-root=/path/to/public_html.\n");
    exit(2);
}

if ($host !== '') {
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        radio_youtube_worker_stderr("Invalid --host value.\n");
        exit(2);
    }
    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['SERVER_NAME'] = $host;
}

/*
 * Geeklog's normal bootstrap assumes an HTTP request, including on 2.1.1.
 * Provide the minimal request context required by device/session/routing code
 * before lib-common.php is loaded so the CLI worker behaves like the selected
 * site without depending on a web server.
 */
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] = 'Geeklog Radio YouTube CLI';
}
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
if (!isset($_SERVER['SERVER_PROTOCOL'])) {
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
}
if (!isset($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}
if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}
if (!isset($_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = '/radio-youtube-worker';
}
if (!isset($_SERVER['PHP_SELF'])) {
    $_SERVER['PHP_SELF'] = '/radio-youtube-worker';
}

require_once $root . '/lib-common.php';
global $_CONF;
require_once $_CONF['path'] . 'plugins/radio/functions.inc';
require_once $_CONF['path'] . 'plugins/radio/lib/youtube.inc.php';

function radio_youtube_worker_log($message)
{
    global $_CONF;

    $line = '[' . date('Y-m-d H:i:s') . '] [Radio YouTube] ' . (string) $message . PHP_EOL;
    $logDir = isset($_CONF['path_log']) ? rtrim((string) $_CONF['path_log'], '/\\') : '';
    $logPath = $logDir !== '' ? $logDir . DIRECTORY_SEPARATOR . 'radio.log' : '';

    if ($logPath !== '' && @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) !== false) {
        return;
    }

    if (function_exists('COM_errorLog')) {
        COM_errorLog('[Radio YouTube] Unable to write radio.log: ' . (string) $message, 1);
    }
}

function radio_youtube_worker_log_error_once($status, $errorKey, $message)
{
    $previous = isset($status['last_error']) ? (string) $status['last_error'] : '';
    if ($previous !== (string) $errorKey) {
        radio_youtube_worker_log($message);
    }
}

$status = RADIO_youtubeStatus();

$ffmpegPath = '';
$homeDir = rtrim((string) getenv('HOME'), '/\\');
$ffmpegCandidates = array(
    getenv('FFMPEG_BIN'),
    $homeDir !== '' ? $homeDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg' : '',
    '/usr/bin/ffmpeg',
    '/usr/local/bin/ffmpeg',
    '/opt/local/bin/ffmpeg',
    '/opt/homebrew/bin/ffmpeg'
);

$ffmpegOutput = array();
$ffmpegCode = 1;
@exec('command -v ffmpeg 2>/dev/null', $ffmpegOutput, $ffmpegCode);
if ($ffmpegCode === 0 && isset($ffmpegOutput[0])) {
    array_unshift($ffmpegCandidates, trim((string) $ffmpegOutput[0]));
}

foreach ($ffmpegCandidates as $candidate) {
    $candidate = trim((string) $candidate);
    if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
        $ffmpegPath = $candidate;
        break;
    }
}

if ($ffmpegPath === '' || !is_file($ffmpegPath) || !is_executable($ffmpegPath)) {
    radio_youtube_worker_log_error_once(
        $status,
        'youtube_ffmpeg_missing',
        'FFmpeg is not installed or is not available in PATH.'
    );
    RADIO_youtubeWriteStatus(array(
        'running' => false,
        'pid' => 0,
        'target_key' => '',
        'program_id' => 0,
        'program_title' => '',
        'schedule_id' => 0,
        'last_error' => 'youtube_ffmpeg_missing'
    ));
    radio_youtube_worker_stderr("YouTube Live error: FFmpeg is not installed or is not available in PATH.\n");
    exit(5);
}

$hasShowwaves = RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'showwaves');
$hasOverlay = RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'overlay');
$hasSubtitles = RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'subtitles');

$videoMode = 'color';
if ($hasShowwaves && $hasOverlay && $hasSubtitles) {
    $videoMode = 'stationcard';
} elseif (RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'drawtext')) {
    $videoMode = 'drawtext';
} elseif ($hasShowwaves && $hasOverlay) {
    $videoMode = 'compactwaves';
} elseif ($hasShowwaves) {
    $videoMode = 'showwaves';
} elseif (RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'showspectrum')) {
    $videoMode = 'showspectrum';
}

$pid = isset($status['pid']) ? (int) $status['pid'] : 0;
$running = RADIO_youtubePidRunning($pid);
if (!$running && $pid > 0) {
    radio_youtube_worker_log(
        'Streaming process disappeared unexpectedly (PID ' . $pid . ').'
    );
    $status['pid'] = 0;
    $status['running'] = false;
}

$config = RADIO_youtubeConfig();
if (!$running
    && isset($config['mode']) && $config['mode'] === 'manual'
    && !empty($config['manual_requested'])
    && !empty($status['target_key'])
    && strpos((string) $status['target_key'], 'manual:') === 0
    && !empty($status['started_at'])
    && !empty($status['program_id'])) {
    $startedAt = strtotime((string) $status['started_at']);
    $duration = RADIO_programDuration((int) $status['program_id']);
    if ($startedAt !== false && $duration > 0 && time() >= ($startedAt + $duration)) {
        RADIO_youtubeSetManualRequest(false);
        RADIO_youtubeWriteStatus(array(
            'running' => false,
            'pid' => 0,
            'target_key' => '',
            'last_error' => ''
        ));
        radio_youtube_worker_log(
            'Manual programme completed: ' . (string) $status['program_title'] . '.'
        );
        if (!$quiet) {
            echo "YouTube Live manual programme completed.\n";
        }
        exit(0);
    }
}

if (!$running) {
    $status['target_key'] = '';
}

$target = RADIO_youtubeTarget(time());
if ($target === false) {
    if ($running) {
        $stopped = RADIO_youtubeStopPid($pid);
        if ($stopped) {
            radio_youtube_worker_log(
                'YouTube Live stopped'
                . (!empty($status['program_title']) ? ': ' . (string) $status['program_title'] : '')
                . '.'
            );
        } else {
            radio_youtube_worker_log('Unable to stop YouTube Live process (PID ' . $pid . ').');
        }
    }
    RADIO_youtubeWriteStatus(array(
        'running' => false,
        'pid' => 0,
        'target_key' => '',
        'program_id' => 0,
        'program_title' => '',
        'schedule_id' => 0,
        'last_error' => ''
    ));
    if (!$quiet) {
        echo "YouTube Live idle.\n";
    }
    exit(0);
}

$artwork = RADIO_youtubeArtwork($target);
$artworkType = isset($artwork['type']) ? (string) $artwork['type'] : 'none';
$artworkPath = isset($artwork['path']) ? (string) $artwork['path'] : '';

if (!RADIO_youtubeWriteOverlay($target, $status, time())) {
    radio_youtube_worker_log_error_once(
        $status,
        'youtube_overlay_write_failed',
        'Unable to update YouTube station card text.'
    );
    RADIO_youtubeWriteStatus(array('last_error' => 'youtube_overlay_write_failed'));
    $status['last_error'] = 'youtube_overlay_write_failed';
    radio_youtube_worker_stderr("YouTube Live warning: unable to update station card text.\n");
}

if ($running && isset($status['target_key']) && $status['target_key'] === $target['key']) {
    RADIO_youtubeWriteStatus(array(
        'running' => true,
        'pid' => $pid,
        'video_mode' => $videoMode,
        'ffmpeg_path' => $ffmpegPath,
        'artwork_type' => $artworkType,
        'artwork_path' => $artworkPath,
        'last_error' => ''
    ));
    if (!$quiet) {
        echo "YouTube Live already running for " . $target['program_title'] . ".\n";
    }
    exit(0);
}

if ($running) {
    $previousTitle = isset($status['program_title']) ? (string) $status['program_title'] : '';
    $stopped = RADIO_youtubeStopPid($pid);
    if ($stopped) {
        radio_youtube_worker_log(
            'Switching YouTube Live target'
            . ($previousTitle !== '' ? ' from "' . $previousTitle . '"' : '')
            . ' to "' . (string) $target['program_title'] . '".'
        );
    } else {
        radio_youtube_worker_log('Unable to stop previous YouTube Live process (PID ' . $pid . ').');
    }
}

$error = '';
$command = RADIO_youtubeFfmpegCommand($target, $error, $ffmpegPath, $videoMode);
if ($command === false) {
    radio_youtube_worker_log_error_once(
        $status,
        $error,
        'Unable to prepare YouTube Live FFmpeg command: ' . (string) $error . '.'
    );
    RADIO_youtubeWriteStatus(array(
        'running' => false,
        'pid' => 0,
        'target_key' => '',
        'program_id' => (int) $target['program_id'],
        'program_title' => $target['program_title'],
        'schedule_id' => (int) $target['schedule_id'],
        'last_error' => $error
    ));
    radio_youtube_worker_stderr("YouTube Live error: " . $error . "\n");
    exit(3);
}

$log = RADIO_youtubeLogPath();
$output = array();
$code = 1;
$launch = 'nohup ' . $command . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
@exec($launch, $output, $code);
$newPid = $code === 0 && isset($output[0]) ? (int) trim($output[0]) : 0;

if ($newPid < 2) {
    radio_youtube_worker_log_error_once(
        $status,
        'youtube_ffmpeg_start_failed',
        'Unable to start FFmpeg for YouTube Live.'
    );
    RADIO_youtubeWriteStatus(array(
        'running' => false,
        'pid' => 0,
        'target_key' => '',
        'program_id' => (int) $target['program_id'],
        'program_title' => $target['program_title'],
        'schedule_id' => (int) $target['schedule_id'],
        'last_error' => 'youtube_ffmpeg_start_failed'
    ));
    radio_youtube_worker_stderr("Unable to start FFmpeg.\n");
    exit(4);
}

/*
 * A background shell can return a PID even when FFmpeg exits immediately
 * because of a filter, codec, input or RTMP error. Give the process a short
 * grace period and verify that it is still alive before reporting success.
 */
sleep(1);
if (!RADIO_youtubePidRunning($newPid)) {
    radio_youtube_worker_log_error_once(
        $status,
        'youtube_ffmpeg_exited_early',
        'FFmpeg exited immediately after YouTube Live start. Check youtube-live.log.'
    );
    RADIO_youtubeWriteStatus(array(
        'running' => false,
        'pid' => 0,
        'target_key' => '',
        'program_id' => (int) $target['program_id'],
        'program_title' => $target['program_title'],
        'schedule_id' => (int) $target['schedule_id'],
        'last_error' => 'youtube_ffmpeg_exited_early'
    ));
    radio_youtube_worker_stderr("YouTube Live error: FFmpeg exited immediately. Check youtube-live.log.\n");
    exit(4);
}

RADIO_youtubeWriteStatus(array(
    'running' => true,
    'pid' => $newPid,
    'target_key' => $target['key'],
    'program_id' => (int) $target['program_id'],
    'program_title' => $target['program_title'],
    'schedule_id' => (int) $target['schedule_id'],
    'started_at' => date('Y-m-d H:i:s'),
    'video_mode' => $videoMode,
    'ffmpeg_path' => $ffmpegPath,
    'artwork_type' => $artworkType,
    'artwork_path' => $artworkPath,
    'last_error' => ''
));

radio_youtube_worker_log(
    'YouTube Live started: ' . (string) $target['program_title']
    . ' (PID ' . $newPid . ', video mode ' . $videoMode . ').'
);
if (!$quiet) {
    echo "YouTube Live started: " . $target['program_title'] . " (PID " . $newPid . ").\n";
}
