<?php

function radio_studio_youtube_stderr($message)
{
    @file_put_contents('php://stderr', (string) $message, FILE_APPEND);
}

if (PHP_SAPI !== 'cli') {
    radio_studio_youtube_stderr("This worker requires PHP CLI.\n");
    exit(1);
}

$root = getenv('GEEKLOG_ROOT');
$host = getenv('GEEKLOG_HOST');
$sessionId = '';
$args = isset($argv) && is_array($argv) ? $argv : array();

foreach ($args as $arg) {
    if (strpos($arg, '--geeklog-root=') === 0) {
        $root = substr($arg, strlen('--geeklog-root='));
    } elseif (strpos($arg, '--host=') === 0) {
        $host = substr($arg, strlen('--host='));
    } elseif (strpos($arg, '--session=') === 0) {
        $sessionId = substr($arg, strlen('--session='));
    }
}

$root = rtrim((string) $root, '/\\');
$host = trim((string) $host);
$sessionId = trim((string) $sessionId);

if ($root === '' || !is_file($root . '/lib-common.php')
    || !preg_match('/^[a-f0-9]{40}$/', $sessionId)) {
    radio_studio_youtube_stderr("Invalid Studio YouTube worker arguments.\n");
    exit(2);
}

if ($host !== '') {
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        radio_studio_youtube_stderr("Invalid --host value.\n");
        exit(2);
    }
    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['SERVER_NAME'] = $host;
}

if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] = 'Geeklog Radio Studio YouTube CLI';
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
    $_SERVER['SCRIPT_NAME'] = '/radio-studio-youtube-worker';
}
if (!isset($_SERVER['PHP_SELF'])) {
    $_SERVER['PHP_SELF'] = '/radio-studio-youtube-worker';
}

require_once $root . '/lib-common.php';
global $_CONF;
require_once $_CONF['path'] . 'plugins/radio/functions.inc';
require_once $_CONF['path'] . 'plugins/radio/lib/youtube.inc.php';
require_once $_CONF['path'] . 'plugins/radio/lib/studio-live.inc.php';

function radio_studio_youtube_log($message)
{
    global $_CONF;

    $line = '[' . date('Y-m-d H:i:s') . '] [Radio Studio YouTube] ' . (string) $message . PHP_EOL;
    $logDir = isset($_CONF['path_log']) ? rtrim((string) $_CONF['path_log'], '/\\') : '';
    $path = $logDir !== '' ? $logDir . DIRECTORY_SEPARATOR . 'radio.log' : '';
    if ($path !== '' && @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) {
        return;
    }
    if (function_exists('COM_errorLog')) {
        COM_errorLog('[Radio Studio YouTube] ' . (string) $message, 1);
    }
}

$status = RADIO_studioYoutubeStatus();
if ((string) $status['session_id'] !== $sessionId
    || !in_array($status['state'], array('starting','live'), true)) {
    radio_studio_youtube_log('Worker stopped: Studio YouTube session is no longer active.');
    exit(0);
}

$ffmpegPath = '';
$homeDir = rtrim((string) getenv('HOME'), '/\\');
$candidates = array(
    getenv('FFMPEG_BIN'),
    $homeDir !== '' ? $homeDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg' : '',
    '/usr/bin/ffmpeg',
    '/usr/local/bin/ffmpeg',
    '/opt/local/bin/ffmpeg',
    '/opt/homebrew/bin/ffmpeg'
);

$output = array();
$code = 1;
@exec('command -v ffmpeg 2>/dev/null', $output, $code);
if ($code === 0 && isset($output[0])) {
    array_unshift($candidates, trim((string) $output[0]));
}

foreach ($candidates as $candidate) {
    $candidate = trim((string) $candidate);
    if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
        $ffmpegPath = $candidate;
        break;
    }
}

if ($ffmpegPath === '') {
    RADIO_studioYoutubeWriteStatus(array(
        'state' => 'error',
        'last_error' => 'youtube_ffmpeg_missing',
        'helper_pid' => 0,
        'ffmpeg_pid' => 0
    ));
    radio_studio_youtube_log('FFmpeg is unavailable.');
    exit(3);
}

$hasShowwaves = RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'showwaves');
$hasOverlay = RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'overlay');
$hasDrawtext = RADIO_youtubeFfmpegHasFilter($ffmpegPath, 'drawtext');

$videoMode = 'color';
if ($hasShowwaves && $hasOverlay && $hasDrawtext) {
    $videoMode = 'stationcard';
} elseif ($hasDrawtext) {
    $videoMode = 'drawtext';
} elseif ($hasShowwaves && $hasOverlay) {
    $videoMode = 'showwaves';
}

$inputPath = RADIO_studioYoutubeInputPath($sessionId);
if ($inputPath === '' || !is_file($inputPath)) {
    RADIO_studioYoutubeWriteStatus(array(
        'state' => 'error',
        'last_error' => 'studio_youtube_input_missing',
        'helper_pid' => 0,
        'ffmpeg_pid' => 0
    ));
    exit(4);
}

$error = '';
$command = RADIO_youtubeStudioFfmpegCommand(
    (int) $status['program_id'],
    $error,
    $ffmpegPath,
    $videoMode
);
if ($command === false) {
    if (is_file($inputPath)) {
        @unlink($inputPath);
    }
    RADIO_studioYoutubeWriteStatus(array(
        'state' => 'error',
        'last_error' => $error,
        'helper_pid' => 0,
        'ffmpeg_pid' => 0
    ));
    radio_studio_youtube_log('Unable to build FFmpeg command: ' . $error . '.');
    exit(4);
}

$logPath = RADIO_youtubeLogPath();
@file_put_contents($logPath, '', LOCK_EX);

$descriptors = array(
    0 => array('pipe', 'r'),
    1 => array('file', $logPath, 'a'),
    2 => array('file', $logPath, 'a')
);

$pipes = array();
$process = @proc_open($command, $descriptors, $pipes);
if (!is_resource($process) || !isset($pipes[0]) || !is_resource($pipes[0])) {
    if (is_file($inputPath)) {
        @unlink($inputPath);
    }
    RADIO_studioYoutubeWriteStatus(array(
        'state' => 'error',
        'last_error' => 'youtube_ffmpeg_start_failed',
        'helper_pid' => 0,
        'ffmpeg_pid' => 0
    ));
    radio_studio_youtube_log('Unable to start FFmpeg process.');
    exit(5);
}

@stream_set_blocking($pipes[0], true);
$processStatus = proc_get_status($process);
$ffmpegPid = is_array($processStatus) && isset($processStatus['pid'])
    ? (int) $processStatus['pid']
    : 0;

RADIO_studioYoutubeWriteStatus(array(
    'ffmpeg_pid' => $ffmpegPid,
    'last_error' => ''
));

$input = @fopen($inputPath, 'rb');
if ($input === false) {
    @fclose($pipes[0]);
    @proc_terminate($process);
    @proc_close($process);
    if (is_file($inputPath)) {
        @unlink($inputPath);
    }
    RADIO_studioYoutubeWriteStatus(array(
        'state' => 'error',
        'last_error' => 'studio_youtube_input_missing',
        'helper_pid' => 0,
        'ffmpeg_pid' => 0
    ));
    exit(6);
}

$offset = 0;
$firstDataAt = 0;
$lastDataAt = time();
$normalStop = false;
$failure = '';

radio_studio_youtube_log(
    'Studio YouTube encoder starting for "' . (string) $status['program_title']
    . '" (helper PID ' . (int) getmypid()
    . ', FFmpeg PID ' . $ffmpegPid
    . ', video mode ' . $videoMode . ').'
);

while (true) {
    $processStatus = proc_get_status($process);
    if (!is_array($processStatus) || empty($processStatus['running'])) {
        $failure = 'youtube_ffmpeg_disappeared';
        break;
    }

    clearstatcache(true, $inputPath);
    $size = @filesize($inputPath);
    $size = $size === false ? 0 : (int) $size;

    if ($size > $offset) {
        @fseek($input, $offset);
        $toRead = min(65536, $size - $offset);
        $data = @fread($input, $toRead);
        if (is_string($data) && $data !== '') {
            $length = strlen($data);
            $written = 0;
            while ($written < $length) {
                $result = @fwrite($pipes[0], substr($data, $written));
                if ($result === false || $result < 1) {
                    $failure = 'studio_youtube_pipe_failed';
                    break 2;
                }
                $written += $result;
            }
            @fflush($pipes[0]);
            $offset += $length;
            $lastDataAt = time();

            if ($firstDataAt === 0) {
                $firstDataAt = time();
                RADIO_studioYoutubeWriteStatus(array(
                    'state' => 'live',
                    'last_error' => ''
                ));
                radio_studio_youtube_log('Studio YouTube master audio connected.');
            }
            continue;
        }
    }

    /*
     * The relay file is append-only from short web requests, but keeping every
     * already-consumed byte for a multi-hour show would make it grow without
     * bound. When FFmpeg has consumed the whole file and at least 8 MiB have
     * accumulated, compact it under the same exclusive lock used by appenders.
     *
     * Re-check the size after acquiring the lock: if a new chunk arrived in
     * the meantime, do not truncate it. The next loop will consume it first.
     */
    if ($offset >= 8388608 && $size === $offset) {
        @fclose($input);
        $compact = @fopen($inputPath, 'c+b');

        if ($compact !== false && @flock($compact, LOCK_EX)) {
            clearstatcache(true, $inputPath);
            $lockedSize = @filesize($inputPath);
            $lockedSize = $lockedSize === false ? -1 : (int) $lockedSize;

            if ($lockedSize === $offset && @ftruncate($compact, 0)) {
                @fflush($compact);
                $offset = 0;
            }

            @flock($compact, LOCK_UN);
        }
        if (is_resource($compact)) {
            @fclose($compact);
        }

        $input = @fopen($inputPath, 'rb');
        if ($input === false) {
            $failure = 'studio_youtube_input_missing';
            break;
        }
    }

    $status = RADIO_studioYoutubeStatus();
    if ((string) $status['session_id'] !== $sessionId) {
        $failure = 'studio_youtube_session_replaced';
        break;
    }

    if (!empty($status['stop_requested'])) {
        clearstatcache(true, $inputPath);
        $latestSize = @filesize($inputPath);
        $latestSize = $latestSize === false ? 0 : (int) $latestSize;
        if ($offset >= $latestSize && (time() - $lastDataAt) >= 1) {
            $normalStop = true;
            break;
        }
    }

    $startedAt = !empty($status['started_at']) ? strtotime((string) $status['started_at']) : false;
    $lastChunkAt = !empty($status['last_chunk_at']) ? strtotime((string) $status['last_chunk_at']) : false;

    if ($firstDataAt === 0 && $startedAt !== false && (time() - $startedAt) > 20) {
        $failure = 'studio_youtube_input_timeout';
        break;
    }
    if ($firstDataAt > 0 && $lastChunkAt !== false && (time() - $lastChunkAt) > 15) {
        $failure = 'studio_youtube_input_timeout';
        break;
    }

    usleep(50000);
}

@fclose($input);
@fclose($pipes[0]);

for ($i = 0; $i < 20; $i++) {
    $processStatus = proc_get_status($process);
    if (!is_array($processStatus) || empty($processStatus['running'])) {
        break;
    }
    usleep(100000);
}

$processStatus = proc_get_status($process);
if (is_array($processStatus) && !empty($processStatus['running'])) {
    @proc_terminate($process);
}
@proc_close($process);

/*
 * The browser-to-helper input file is only a transient relay buffer. Once the
 * persistent FFmpeg session ends—normally or with an error—it must not remain
 * in site storage.
 */
if (is_file($inputPath)) {
    @unlink($inputPath);
}

if ($normalStop) {
    RADIO_studioYoutubeWriteStatus(array(
        'state' => 'idle',
        'helper_pid' => 0,
        'ffmpeg_pid' => 0,
        'stop_requested' => false,
        'last_error' => ''
    ));
    radio_studio_youtube_log('Studio YouTube Live stopped normally.');
    exit(0);
}

RADIO_studioYoutubeWriteStatus(array(
    'state' => 'error',
    'helper_pid' => 0,
    'ffmpeg_pid' => 0,
    'stop_requested' => false,
    'last_error' => $failure !== '' ? $failure : 'studio_youtube_worker_failed'
));
radio_studio_youtube_log('Studio YouTube Live stopped with error: ' . $failure . '.');
exit(7);
