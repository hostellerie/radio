<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';

function radio_youtube_trace($number, $message)
{
    if (function_exists('COM_errorLog')) {
        COM_errorLog(
            'RADIO YOUTUBE TRACE ' . str_pad((string) $number, 2, '0', STR_PAD_LEFT)
            . ': ' . $message,
            1
        );
    }
}

radio_youtube_trace(2, 'after lib-common.php');

radio_youtube_trace(3, 'before auth.inc.php');
require_once dirname(__FILE__) . '/../../auth.inc.php';
radio_youtube_trace(4, 'after auth.inc.php');

radio_youtube_trace(5, 'before admin-ui.inc.php');
require_once __DIR__ . '/admin-ui.inc.php';
radio_youtube_trace(6, 'after admin-ui.inc.php');

radio_youtube_trace(7, 'before radio.admin ACL check');
if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio YouTube Live administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    radio_youtube_trace(8, 'access denied page rendered');
    exit;
}

radio_youtube_trace(8, 'radio.admin ACL granted');

global $LANG_RADIO, $_CONF;

radio_youtube_trace(9, 'before YouTube library path');
$youtubeLibrary = $_CONF['path'] . 'plugins/radio/lib/youtube.inc.php';
radio_youtube_trace(10, 'YouTube library path resolved: ' . $youtubeLibrary);
$youtubeLibraryReady = is_file($youtubeLibrary) && is_readable($youtubeLibrary);
radio_youtube_trace(11, $youtubeLibraryReady ? 'YouTube library file is readable' : 'YouTube library file missing or unreadable');

if ($youtubeLibraryReady) {
    radio_youtube_trace(12, 'before require youtube.inc.php');
    require_once $youtubeLibrary;
    radio_youtube_trace(13, 'after require youtube.inc.php');
    $youtubeLibraryReady = function_exists('RADIO_youtubeConfig')
        && function_exists('RADIO_youtubeStatus')
        && function_exists('RADIO_youtubeSaveConfig')
        && function_exists('RADIO_youtubeSetManualRequest');
    radio_youtube_trace(14, $youtubeLibraryReady ? 'YouTube library functions available' : 'YouTube library functions incomplete');
}

function radio_youtube_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$message = '';

radio_youtube_trace(15, 'before YouTube library readiness branch');
if (!$youtubeLibraryReady) {
    $errorText = isset($LANG_RADIO['youtube_module_unavailable'])
        ? $LANG_RADIO['youtube_module_unavailable']
        : 'The YouTube Live module is unavailable or incomplete.';
    $content = '<section class="radio-admin__panel"><p>'
        . radio_youtube_h($errorText)
        . '</p></section>';

    $content = RADIO_adminRenderPage(
        'youtube',
        isset($LANG_RADIO['youtube_live']) ? $LANG_RADIO['youtube_live'] : 'YouTube Live',
        isset($LANG_RADIO['admin_youtube_intro']) ? $LANG_RADIO['admin_youtube_intro'] : '',
        isset($LANG_RADIO['admin_youtube_help_title']) ? $LANG_RADIO['admin_youtube_help_title'] : '',
        isset($LANG_RADIO['admin_youtube_help_text']) ? $LANG_RADIO['admin_youtube_help_text'] : '',
        $content,
        ''
    );

    radio_youtube_trace(16, 'rendering module unavailable page');
    $document = COM_createHTMLDocument($content, array(
        'pagetitle' => isset($LANG_RADIO['youtube_live']) ? $LANG_RADIO['youtube_live'] : 'YouTube Live',
        'headercode' => RADIO_adminHeaderCode()
    ));
    radio_youtube_trace(17, 'module unavailable document created');
    COM_output($document);
    radio_youtube_trace(18, 'module unavailable document output complete');
    exit;
}

radio_youtube_trace(16, 'YouTube library ready');
radio_youtube_trace(17, 'before POST handling');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['youtube_live']);
    } elseif (isset($_POST['save_youtube_runtime'])) {
        $ok = RADIO_youtubeSaveConfig($_POST);
        $message = COM_showMessageText(
            $ok ? $LANG_RADIO['youtube_saved'] : $LANG_RADIO['youtube_save_failed'],
            $LANG_RADIO['youtube_live']
        );
    } elseif (isset($_POST['youtube_start_request'])) {
        $saved = RADIO_youtubeSaveConfig($_POST);
        $ok = $saved && RADIO_youtubeSetManualRequest(true);
        $message = COM_showMessageText(
            $ok ? $LANG_RADIO['youtube_start_requested'] : $LANG_RADIO['youtube_save_failed'],
            $LANG_RADIO['youtube_live']
        );
    } elseif (isset($_POST['youtube_stop_request'])) {
        $ok = RADIO_youtubeSetManualRequest(false);
        $message = COM_showMessageText(
            $ok ? $LANG_RADIO['youtube_stop_requested'] : $LANG_RADIO['youtube_save_failed'],
            $LANG_RADIO['youtube_live']
        );
    }
}
radio_youtube_trace(18, 'after POST handling');

radio_youtube_trace(19, 'before RADIO_youtubeConfig');
$youtubeConfig = RADIO_youtubeConfig();
radio_youtube_trace(20, 'after RADIO_youtubeConfig');

radio_youtube_trace(21, 'before RADIO_youtubeStatus');
$status = RADIO_youtubeStatus();
radio_youtube_trace(22, 'after RADIO_youtubeStatus');

radio_youtube_trace(23, 'before RADIO_getSchedules');
$schedules = RADIO_getSchedules(false);
radio_youtube_trace(24, 'after RADIO_getSchedules count=' . count($schedules));

radio_youtube_trace(25, 'before RADIO_getPrograms');
$programs = array_values(array_filter(RADIO_getPrograms(200, false), function ($row) {
    return RADIO_hasReadAccess($row) || RADIO_hasEditAccess($row);
}));
radio_youtube_trace(26, 'after RADIO_getPrograms count=' . count($programs));

radio_youtube_trace(27, 'before SEC_createToken');
$token = SEC_createToken();
radio_youtube_trace(28, 'after SEC_createToken');
$scheduleIds = array_flip($youtubeConfig['schedule_ids']);

radio_youtube_trace(29, 'before page content construction');
$content = '';

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h(isset($LANG_RADIO['youtube_getting_started']) ? $LANG_RADIO['youtube_getting_started'] : 'Getting started')
    . '</h2>'
    . '<ol>'
    . '<li>' . radio_youtube_h(isset($LANG_RADIO['youtube_help_step_1']) ? $LANG_RADIO['youtube_help_step_1'] : 'Open YouTube Studio, click Create, then Go Live.') . '</li>'
    . '<li>' . radio_youtube_h(isset($LANG_RADIO['youtube_help_step_2']) ? $LANG_RADIO['youtube_help_step_2'] : 'Open the Stream tab and locate Stream key.') . '</li>'
    . '<li>' . radio_youtube_h(isset($LANG_RADIO['youtube_help_step_3']) ? $LANG_RADIO['youtube_help_step_3'] : 'Copy the stream key into Radio Configuration. Keep it private.') . '</li>'
    . '<li>' . radio_youtube_h(isset($LANG_RADIO['youtube_help_step_4']) ? $LANG_RADIO['youtube_help_step_4'] : 'For a first test, use Manual mode and select a Radio programme made from local audio files.') . '</li>'
    . '<li>' . radio_youtube_h(isset($LANG_RADIO['youtube_help_step_5']) ? $LANG_RADIO['youtube_help_step_5'] : 'Run the Radio YouTube worker from cron or the server command shown below.') . '</li>'
    . '</ol>'
    . '<p><strong>' . radio_youtube_h(isset($LANG_RADIO['youtube_help_stream_key_warning']) ? $LANG_RADIO['youtube_help_stream_key_warning'] : 'Security: the stream key acts like a password. Do not publish or share it.') . '</strong></p>'
    . '<p><a href="https://support.google.com/youtube/answer/2907883" target="_blank" rel="noopener noreferrer">'
    . radio_youtube_h(isset($LANG_RADIO['youtube_help_official']) ? $LANG_RADIO['youtube_help_official'] : 'Official YouTube encoder setup help')
    . '</a></p>'
    . '</section>';

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_configuration']) . '</h2>'
    . '<p>' . radio_youtube_h($LANG_RADIO['youtube_config_in_main']) . '</p>'
    . '<dl>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_enabled']) . '</dt><dd>'
    . radio_youtube_h(
        $youtubeConfig['enabled']
            ? (isset($LANG_RADIO['enabled']) ? $LANG_RADIO['enabled'] : 'Enabled')
            : (isset($LANG_RADIO['disabled']) ? $LANG_RADIO['disabled'] : 'Disabled')
    ) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_mode']) . '</dt><dd>'
    . radio_youtube_h(
        $youtubeConfig['mode'] === 'manual'
            ? $LANG_RADIO['youtube_mode_manual']
            : $LANG_RADIO['youtube_mode_scheduled']
    ) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_rtmp_url']) . '</dt><dd>'
    . radio_youtube_h($youtubeConfig['rtmp_url']) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_stream_key']) . '</dt><dd>'
    . radio_youtube_h(
        trim((string) $youtubeConfig['stream_key']) !== ''
            ? $LANG_RADIO['youtube_stream_key_configured']
            : $LANG_RADIO['youtube_stream_key_missing']
    ) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_video_size']) . '</dt><dd>'
    . radio_youtube_h($youtubeConfig['video_size']) . '</dd>'
    . '<dt>' . radio_youtube_h(isset($LANG_RADIO['youtube_video_bitrate']) ? $LANG_RADIO['youtube_video_bitrate'] : 'Video bitrate') . '</dt><dd>'
    . radio_youtube_h($youtubeConfig['video_bitrate']) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_audio_bitrate']) . '</dt><dd>'
    . radio_youtube_h($youtubeConfig['audio_bitrate']) . '</dd>'
    . '</dl></section>';

if (!$youtubeConfig['enabled'] || trim((string) $youtubeConfig['stream_key']) === '') {
    $content .= '<p class="radio-admin__notice">'
        . radio_youtube_h($LANG_RADIO['youtube_configuration_required'])
        . '</p>';
}

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_status']) . '</h2>';

if (!empty($status['running']) && !empty($status['pid'])) {
    $content .= '<p><strong>● ' . radio_youtube_h($LANG_RADIO['youtube_running']) . '</strong><br>'
        . radio_youtube_h(isset($status['program_title']) ? $status['program_title'] : '')
        . ' · PID ' . (int) $status['pid'] . '</p>';
} else {
    $content .= '<p><strong>○ ' . radio_youtube_h($LANG_RADIO['youtube_idle']) . '</strong></p>';
}

if (!empty($status['last_error'])) {
    $errorKey = (string) $status['last_error'];
    $errorText = isset($LANG_RADIO[$errorKey]) ? $LANG_RADIO[$errorKey] : $errorKey;
    $content .= '<p class="radio-admin__notice">' . radio_youtube_h($errorText) . '</p>';
}
if (!empty($status['video_mode'])) {
    $content .= '<p><small>Video mode: ' . radio_youtube_h($status['video_mode']) . '</small></p>';
}
if (isset($status['artwork_type'])) {
    $artworkLabel = (string) $status['artwork_type'];
    if (!empty($status['artwork_path'])) {
        $artworkLabel .= ' — ' . basename((string) $status['artwork_path']);
    }
    $content .= '<p><small>Artwork: ' . radio_youtube_h($artworkLabel) . '</small></p>';
}
if (!empty($status['last_check'])) {
    $lastWorkerTs = strtotime((string) $status['last_check']);
    $workerAge = $lastWorkerTs !== false ? max(0, time() - $lastWorkerTs) : null;
    $workerFresh = $workerAge !== null && $workerAge <= 120;

    $content .= '<p><strong>'
        . ($workerFresh ? '✓ ' : '⚠ ')
        . radio_youtube_h(
            $workerFresh
                ? $LANG_RADIO['youtube_worker_active']
                : $LANG_RADIO['youtube_worker_stale']
        )
        . '</strong><br><small>'
        . radio_youtube_h($LANG_RADIO['youtube_last_worker_check'])
        . ': ' . radio_youtube_h($status['last_check']);

    if ($workerFresh) {
        $content .= '<br>' . radio_youtube_h($LANG_RADIO['youtube_worker_next_expected']);
    }

    $content .= '</small></p>';
} else {
    $content .= '<p><strong>⚠ '
        . radio_youtube_h($LANG_RADIO['youtube_worker_not_detected'])
        . '</strong><br><small>'
        . radio_youtube_h($LANG_RADIO['youtube_worker_not_detected_help'])
        . '</small></p>';
}
$content .= '</section>';

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_scheduled_slots']) . '</h2>'
    . '<form method="post" action="">';

if (count($schedules) === 0) {
    $content .= '<p>' . radio_youtube_h($LANG_RADIO['youtube_no_schedules']) . '</p>';
} else {
    foreach ($schedules as $schedule) {
        $id = (int) $schedule['schedule_id'];
        $recurrenceKey = 'recurrence_' . $schedule['recurrence'];
        $recurrenceLabel = isset($LANG_RADIO[$recurrenceKey])
            ? $LANG_RADIO[$recurrenceKey]
            : (string) $schedule['recurrence'];

        $content .= '<p><label><input type="checkbox" name="schedule_ids[]" value="' . $id . '"'
            . (isset($scheduleIds[$id]) ? ' checked' : '') . '> '
            . radio_youtube_h($schedule['program_title']) . ' — '
            . radio_youtube_h($schedule['starts_at']) . ' — '
            . radio_youtube_h($recurrenceLabel)
            . '</label></p>';
    }
}

$content .= '<h3>' . radio_youtube_h($LANG_RADIO['youtube_manual_test']) . '</h3>'
    . '<p><label>' . radio_youtube_h($LANG_RADIO['programs']) . ' '
    . '<select name="manual_program_id"><option value="0">—</option>';

foreach ($programs as $program) {
    $content .= '<option value="' . (int) $program['program_id'] . '"'
        . ((int) $youtubeConfig['manual_program_id'] === (int) $program['program_id'] ? ' selected' : '') . '>'
        . radio_youtube_h($program['title']) . '</option>';
}

$content .= '</select></label></p>'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_youtube_h($token) . '">'
    . '<button type="submit" name="save_youtube_runtime" value="1">'
    . radio_youtube_h($LANG_RADIO['save']) . '</button> '
    . '<button type="submit" name="youtube_start_request" value="1">'
    . radio_youtube_h($LANG_RADIO['youtube_start_now']) . '</button> '
    . '<button type="submit" name="youtube_stop_request" value="1">'
    . radio_youtube_h($LANG_RADIO['youtube_stop']) . '</button>'
    . '</form></section>';

$worker = $_CONF['path'] . 'plugins/radio/bin/youtube-live.php';
$root = isset($_CONF['path_html']) && $_CONF['path_html'] !== ''
    ? rtrim((string) $_CONF['path_html'], '/\\')
    : '<GEEKLOG_PUBLIC_ROOT>';
$siteHost = '';
if (!empty($_CONF['site_url'])) {
    $parsedHost = parse_url((string) $_CONF['site_url'], PHP_URL_HOST);
    if (is_string($parsedHost)) {
        $siteHost = trim($parsedHost);
    }
}
$command = 'php ' . $worker . ' --geeklog-root=' . $root
    . ($siteHost !== '' ? ' --host=' . $siteHost : '');

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_worker']) . '</h2>'
    . '<p>' . radio_youtube_h($LANG_RADIO['youtube_worker_help']) . '</p>'
    . '<pre><code>' . radio_youtube_h($command) . '</code></pre>'
    . '<p>' . radio_youtube_h($LANG_RADIO['youtube_worker_cron_help']) . '</p>'
    . '<pre><code>* * * * * ' . radio_youtube_h($command) . ' >/dev/null 2>&1</code></pre>'
    . '<p><small>' . radio_youtube_h($LANG_RADIO['youtube_beta_warning']) . '</small></p>'
    . '</section>';

radio_youtube_trace(30, 'before RADIO_adminRenderPage');
$content = RADIO_adminRenderPage(
    'youtube',
    $LANG_RADIO['youtube_live'],
    $LANG_RADIO['admin_youtube_intro'],
    $LANG_RADIO['admin_youtube_help_title'],
    $LANG_RADIO['admin_youtube_help_text'],
    $content,
    $message
);
radio_youtube_trace(31, 'after RADIO_adminRenderPage');

radio_youtube_trace(32, 'before COM_createHTMLDocument');
$document = COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['youtube_live'],
    'headercode' => RADIO_adminHeaderCode()
));
radio_youtube_trace(33, 'after COM_createHTMLDocument');

radio_youtube_trace(34, 'before COM_output');
COM_output($document);
radio_youtube_trace(35, 'after COM_output');
