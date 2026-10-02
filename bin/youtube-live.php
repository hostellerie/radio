<?php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker can only run from CLI.\n");
    exit(1);
}

$root = getenv('GEEKLOG_ROOT');
foreach ($argv as $arg) {
    if (strpos($arg, '--geeklog-root=') === 0) {
        $root = substr($arg, strlen('--geeklog-root='));
    }
}
$root = rtrim((string) $root, '/\\');
if ($root === '' || !is_file($root . '/lib-common.php')) {
    fwrite(STDERR, "Set GEEKLOG_ROOT or pass --geeklog-root=/path/to/public_html.\n");
    exit(2);
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
