<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';
require_once $_CONF['path'] . 'plugins/radio/lib/youtube.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio YouTube Live administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;

$youtubeLanguageDefaults = array(
    'youtube_live' => 'YouTube Live',
    'youtube_status' => 'YouTube Live status',
    'youtube_running' => 'Running',
    'youtube_idle' => 'Idle',
    'youtube_last_worker_check' => 'Last worker check',
    'youtube_configuration' => 'YouTube configuration',
    'youtube_enabled' => 'Enable YouTube Live output',
    'youtube_mode' => 'Mode',
    'youtube_mode_scheduled' => 'Scheduled slots',
    'youtube_mode_manual' => 'Manual test',
    'youtube_rtmp_url' => 'RTMP/RTMPS server',
    'youtube_stream_key' => 'Stream key',
    'youtube_stream_key_saved' => 'Saved — leave blank to keep it',
    'youtube_stream_key_help' => 'The stream key is stored in Radio private storage.',
    'youtube_video_size' => 'Video size',
    'youtube_audio_bitrate' => 'Audio bitrate',
    'youtube_scheduled_slots' => 'YouTube scheduled slots',
    'youtube_no_schedules' => 'No Radio schedule is available yet.',
    'youtube_manual_test' => 'Manual test',
    'youtube_start_now' => 'Request start',
    'youtube_stop' => 'Request stop',
    'youtube_saved' => 'YouTube Live configuration saved.',
    'youtube_save_failed' => 'Unable to save the YouTube Live configuration.',
    'youtube_start_requested' => 'Manual YouTube start requested.',
    'youtube_stop_requested' => 'YouTube stop requested.',
    'youtube_worker' => 'Server worker',
    'youtube_worker_help' => 'Run this command from the server to process the current YouTube state.',
    'youtube_worker_cron_help' => 'For scheduled slots, run it every minute with cron:',
    'youtube_beta_warning' => 'Beta: local programme media only.',
    'admin_youtube_intro' => 'Send selected Radio programmes to YouTube Live from the server.',
    'admin_youtube_help_title' => 'Server-side YouTube output',
    'admin_youtube_help_text' => 'The existing Radio players remain unchanged.'
);
foreach ($youtubeLanguageDefaults as $youtubeLanguageKey => $youtubeLanguageValue) {
    if (!isset($LANG_RADIO[$youtubeLanguageKey]) || $LANG_RADIO[$youtubeLanguageKey] === '') {
        $LANG_RADIO[$youtubeLanguageKey] = $youtubeLanguageValue;
    }
}

$message = '';

if (isset($_POST['save_youtube'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['youtube_live']);
    } else {
        $ok = RADIO_youtubeSaveConfig($_POST);
        $message = COM_showMessageText(
            $ok ? $LANG_RADIO['youtube_saved'] : $LANG_RADIO['youtube_save_failed'],
            $LANG_RADIO['youtube_live']
        );
    }
}

if (isset($_POST['youtube_start_request'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['youtube_live']);
    } else {
        RADIO_youtubeSaveConfig($_POST);
        $ok = RADIO_youtubeSetManualRequest(true);
        $message = COM_showMessageText(
            $ok ? $LANG_RADIO['youtube_start_requested'] : $LANG_RADIO['youtube_save_failed'],
            $LANG_RADIO['youtube_live']
        );
    }
}

if (isset($_POST['youtube_stop_request'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['youtube_live']);
    } else {
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
$keyConfigured = trim((string) $config['stream_key']) !== '';

$content = '<section class="radio-admin__panel"><h2>'
    . htmlspecialchars($LANG_RADIO['youtube_status'], ENT_QUOTES, 'UTF-8') . '</h2>';

if (!empty($status['running']) && !empty($status['pid'])) {
    $content .= '<p><strong>● ' . htmlspecialchars($LANG_RADIO['youtube_running'], ENT_QUOTES, 'UTF-8') . '</strong><br>'
        . htmlspecialchars($status['program_title'], ENT_QUOTES, 'UTF-8')
        . ' · PID ' . (int) $status['pid'] . '</p>';
} else {
    $content .= '<p><strong>○ ' . htmlspecialchars($LANG_RADIO['youtube_idle'], ENT_QUOTES, 'UTF-8') . '</strong></p>';
}

if (!empty($status['last_error'])) {
    $errorKey = isset($LANG_RADIO[$status['last_error']]) ? $status['last_error'] : '';
    $errorText = $errorKey !== '' ? $LANG_RADIO[$errorKey] : $status['last_error'];
    $content .= '<p class="radio-admin__notice">' . htmlspecialchars($errorText, ENT_QUOTES, 'UTF-8') . '</p>';
}
if (!empty($status['last_check'])) {
    $content .= '<p><small>' . htmlspecialchars($LANG_RADIO['youtube_last_worker_check'], ENT_QUOTES, 'UTF-8')
        . ': ' . htmlspecialchars($status['last_check'], ENT_QUOTES, 'UTF-8') . '</small></p>';
}
$content .= '</section>';

$content .= '<section class="radio-admin__panel"><h2>'
    . htmlspecialchars($LANG_RADIO['youtube_configuration'], ENT_QUOTES, 'UTF-8') . '</h2>'
    . '<form method="post" action="">'
    . '<p><label><input type="checkbox" name="enabled" value="1"' . ($config['enabled'] ? ' checked' : '') . '> '
    . htmlspecialchars($LANG_RADIO['youtube_enabled'], ENT_QUOTES, 'UTF-8') . '</label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['youtube_mode'], ENT_QUOTES, 'UTF-8') . ' '
    . '<select name="mode">'
    . '<option value="scheduled"' . ($config['mode'] === 'scheduled' ? ' selected' : '') . '>'
    . htmlspecialchars($LANG_RADIO['youtube_mode_scheduled'], ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="manual"' . ($config['mode'] === 'manual' ? ' selected' : '') . '>'
    . htmlspecialchars($LANG_RADIO['youtube_mode_manual'], ENT_QUOTES, 'UTF-8') . '</option>'
    . '</select></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['youtube_rtmp_url'], ENT_QUOTES, 'UTF-8') . '<br>'
    . '<input type="text" name="rtmp_url" size="60" value="'
    . htmlspecialchars($config['rtmp_url'], ENT_QUOTES, 'UTF-8') . '"></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['youtube_stream_key'], ENT_QUOTES, 'UTF-8') . '<br>'
    . '<input type="password" name="stream_key" size="45" value="" autocomplete="new-password" placeholder="'
    . htmlspecialchars($keyConfigured ? $LANG_RADIO['youtube_stream_key_saved'] : '', ENT_QUOTES, 'UTF-8') . '"></label><br>'
    . '<small>' . htmlspecialchars($LANG_RADIO['youtube_stream_key_help'], ENT_QUOTES, 'UTF-8') . '</small></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['youtube_video_size'], ENT_QUOTES, 'UTF-8') . ' '
    . '<select name="video_size">'
    . '<option value="1280x720"' . ($config['video_size'] === '1280x720' ? ' selected' : '') . '>1280×720</option>'
    . '<option value="1920x1080"' . ($config['video_size'] === '1920x1080' ? ' selected' : '') . '>1920×1080</option>'
    . '</select></label> '
    . '<label>' . htmlspecialchars($LANG_RADIO['youtube_audio_bitrate'], ENT_QUOTES, 'UTF-8') . ' '
    . '<select name="audio_bitrate">';
foreach (array('96k','128k','160k','192k') as $bitrate) {
    $content .= '<option value="' . $bitrate . '"' . ($config['audio_bitrate'] === $bitrate ? ' selected' : '') . '>'
        . $bitrate . '</option>';
}
$content .= '</select></label></p>';

$content .= '<h3>' . htmlspecialchars($LANG_RADIO['youtube_scheduled_slots'], ENT_QUOTES, 'UTF-8') . '</h3>';
if (count($schedules) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['youtube_no_schedules'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<div class="radio-admin__stack">';
    foreach ($schedules as $schedule) {
        $id = (int) $schedule['schedule_id'];
        $content .= '<label><input type="checkbox" name="schedule_ids[]" value="' . $id . '"'
            . (isset($scheduleIds[$id]) ? ' checked' : '') . '> '
            . htmlspecialchars($schedule['program_title'], ENT_QUOTES, 'UTF-8') . ' — '
            . htmlspecialchars($schedule['starts_at'], ENT_QUOTES, 'UTF-8') . ' — '
            . htmlspecialchars(
                isset($LANG_RADIO['recurrence_' . $schedule['recurrence']])
                    ? $LANG_RADIO['recurrence_' . $schedule['recurrence']]
                    : (string) $schedule['recurrence'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '</label><br>';
    }
    $content .= '</div>';
}

$content .= '<h3>' . htmlspecialchars($LANG_RADIO['youtube_manual_test'], ENT_QUOTES, 'UTF-8') . '</h3>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['programs'], ENT_QUOTES, 'UTF-8') . ' '
    . '<select name="manual_program_id"><option value="0">—</option>';
foreach ($programs as $program) {
    $content .= '<option value="' . (int) $program['program_id'] . '"'
        . ((int) $config['manual_program_id'] === (int) $program['program_id'] ? ' selected' : '') . '>'
        . htmlspecialchars($program['title'], ENT_QUOTES, 'UTF-8') . '</option>';
}
$content .= '</select></label></p>'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<button type="submit" name="save_youtube" value="1">' . htmlspecialchars($LANG_RADIO['save'], ENT_QUOTES, 'UTF-8') . '</button> '
    . '<button type="submit" name="youtube_start_request" value="1">' . htmlspecialchars($LANG_RADIO['youtube_start_now'], ENT_QUOTES, 'UTF-8') . '</button> '
    . '<button type="submit" name="youtube_stop_request" value="1">' . htmlspecialchars($LANG_RADIO['youtube_stop'], ENT_QUOTES, 'UTF-8') . '</button>'
    . '</form></section>';

$worker = $_CONF['path'] . 'plugins/radio/bin/youtube-live.php';
$root = isset($_CONF['path_html']) && $_CONF['path_html'] !== ''
    ? rtrim((string) $_CONF['path_html'], '/\\')
    : '<GEEKLOG_PUBLIC_ROOT>';
$command = 'php ' . $worker . ' --geeklog-root=' . $root;

$content .= '<section class="radio-admin__panel"><h2>'
    . htmlspecialchars($LANG_RADIO['youtube_worker'], ENT_QUOTES, 'UTF-8') . '</h2>'
    . '<p>' . htmlspecialchars($LANG_RADIO['youtube_worker_help'], ENT_QUOTES, 'UTF-8') . '</p>'
    . '<pre><code>' . htmlspecialchars($command, ENT_QUOTES, 'UTF-8') . '</code></pre>'
    . '<p>' . htmlspecialchars($LANG_RADIO['youtube_worker_cron_help'], ENT_QUOTES, 'UTF-8') . '</p>'
    . '<pre><code>* * * * * ' . htmlspecialchars($command, ENT_QUOTES, 'UTF-8') . ' >/dev/null 2>&1</code></pre>'
    . '<p><small>' . htmlspecialchars($LANG_RADIO['youtube_beta_warning'], ENT_QUOTES, 'UTF-8') . '</small></p>'
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
