<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';
require_once $_CONF['path_system'] . 'classes/config.class.php';
require_once $_CONF['path'] . 'plugins/radio/install_defaults.php';

RADIO_ensureConfig();
$radioConfig = config::get_instance();
if ($radioConfig->group_exists('radio')) {
    $_RADIO_CONF = $radioConfig->get_config('radio');
}

require_once $_CONF['path'] . 'plugins/radio/lib/youtube.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio YouTube Live administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;

function radio_youtube_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

$config = RADIO_youtubeConfig();
$status = RADIO_youtubeStatus();
$schedules = RADIO_getSchedules(false);
$programs = RADIO_getPrograms(200, false);
$token = SEC_createToken();
$scheduleIds = array_flip($config['schedule_ids']);

$content = '';

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_configuration']) . '</h2>'
    . '<p>' . radio_youtube_h($LANG_RADIO['youtube_config_in_main']) . '</p>'
    . '<dl>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_enabled']) . '</dt><dd>'
    . radio_youtube_h($config['enabled'] ? $LANG_RADIO['enabled'] : $LANG_RADIO['disabled']) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_mode']) . '</dt><dd>'
    . radio_youtube_h(
        $config['mode'] === 'manual'
            ? $LANG_RADIO['youtube_mode_manual']
            : $LANG_RADIO['youtube_mode_scheduled']
    ) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_rtmp_url']) . '</dt><dd>'
    . radio_youtube_h($config['rtmp_url']) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_stream_key']) . '</dt><dd>'
    . radio_youtube_h(
        trim((string) $config['stream_key']) !== ''
            ? $LANG_RADIO['youtube_stream_key_configured']
            : $LANG_RADIO['youtube_stream_key_missing']
    ) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_video_size']) . '</dt><dd>'
    . radio_youtube_h($config['video_size']) . '</dd>'
    . '<dt>' . radio_youtube_h($LANG_RADIO['youtube_audio_bitrate']) . '</dt><dd>'
    . radio_youtube_h($config['audio_bitrate']) . '</dd>'
    . '</dl></section>';

if (!$config['enabled'] || trim((string) $config['stream_key']) === '') {
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
if (!empty($status['last_check'])) {
    $content .= '<p><small>' . radio_youtube_h($LANG_RADIO['youtube_last_worker_check'])
        . ': ' . radio_youtube_h($status['last_check']) . '</small></p>';
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
        . ((int) $config['manual_program_id'] === (int) $program['program_id'] ? ' selected' : '') . '>'
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
$command = 'php ' . $worker . ' --geeklog-root=' . $root;

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_worker']) . '</h2>'
    . '<p>' . radio_youtube_h($LANG_RADIO['youtube_worker_help']) . '</p>'
    . '<pre><code>' . radio_youtube_h($command) . '</code></pre>'
    . '<p>' . radio_youtube_h($LANG_RADIO['youtube_worker_cron_help']) . '</p>'
    . '<pre><code>* * * * * ' . radio_youtube_h($command) . ' >/dev/null 2>&1</code></pre>'
    . '<p><small>' . radio_youtube_h($LANG_RADIO['youtube_beta_warning']) . '</small></p>'
    . '</section>';

$content = RADIO_adminRenderPage(
    'youtube',
    $LANG_RADIO['youtube_live'],
    $LANG_RADIO['admin_youtube_intro'],
    $LANG_RADIO['admin_youtube_help_title'],
    $LANG_RADIO['admin_youtube_help_text'],
    $content,
    $message
);

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['youtube_live'],
    'headercode' => RADIO_adminHeaderCode()
)));
