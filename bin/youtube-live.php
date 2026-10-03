<?php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker can only run from CLI.\n");
    exit(1);
}

$root = getenv('GEEKLOG_ROOT');
$host = getenv('GEEKLOG_HOST');

foreach ($argv as $arg) {
    if (strpos($arg, '--geeklog-root=') === 0) {
        $root = substr($arg, strlen('--geeklog-root='));
    } elseif (strpos($arg, '--host=') === 0) {
        $host = substr($arg, strlen('--host='));
    }
}

$root = rtrim((string) $root, '/\\');
$host = trim((string) $host);

if ($root === '' || !is_file($root . '/lib-common.php')) {
    fwrite(STDERR, "Set GEEKLOG_ROOT or pass --geeklog-root=/path/to/public_html.\n");
    exit(2);
}

if ($host !== '') {
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        fwrite(STDERR, "Invalid --host value.\n");
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

$status = RADIO_youtubeStatus();
$pid = isset($status['pid']) ? (int) $status['pid'] : 0;
$running = RADIO_youtubePidRunning($pid);
if (!$running && $pid > 0) {
    $status['pid'] = 0;
    $status['running'] = false;
    $status['target_key'] = '';
}

$target = RADIO_youtubeTarget(time());
if ($target === false) {
    if ($running) {
        RADIO_youtubeStopPid($pid);
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
    echo "YouTube Live idle.\n";
    exit(0);
}

if ($running && isset($status['target_key']) && $status['target_key'] === $target['key']) {
    RADIO_youtubeWriteStatus(array('running' => true, 'pid' => $pid));
    echo "YouTube Live already running for " . $target['program_title'] . ".\n";
    exit(0);
}

if ($running) {
    RADIO_youtubeStopPid($pid);
}

$error = '';
$command = RADIO_youtubeFfmpegCommand($target, $error);
if ($command === false) {
    RADIO_youtubeWriteStatus(array(
        'running' => false,
        'pid' => 0,
        'target_key' => '',
        'program_id' => (int) $target['program_id'],
        'program_title' => $target['program_title'],
        'schedule_id' => (int) $target['schedule_id'],
        'last_error' => $error
    ));
    fwrite(STDERR, "YouTube Live error: " . $error . "\n");
    exit(3);
}

$log = RADIO_youtubeLogPath();
$output = array();
$code = 1;
$launch = 'nohup ' . $command . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
@exec($launch, $output, $code);
$newPid = $code === 0 && isset($output[0]) ? (int) trim($output[0]) : 0;

if ($newPid < 2) {
    RADIO_youtubeWriteStatus(array(
        'running' => false,
        'pid' => 0,
        'target_key' => '',
        'program_id' => (int) $target['program_id'],
        'program_title' => $target['program_title'],
        'schedule_id' => (int) $target['schedule_id'],
        'last_error' => 'youtube_ffmpeg_start_failed'
    ));
    fwrite(STDERR, "Unable to start FFmpeg.\n");
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
    'last_error' => ''
));

echo "YouTube Live started: " . $target['program_title'] . " (PID " . $newPid . ").\n";
