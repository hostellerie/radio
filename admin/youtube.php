<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';

require_once dirname(__FILE__) . '/../../auth.inc.php';

require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio YouTube Live administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;

$youtubeLibrary = $_CONF['path'] . 'plugins/radio/lib/youtube.inc.php';
$youtubeLibraryReady = is_file($youtubeLibrary) && is_readable($youtubeLibrary);

if ($youtubeLibraryReady) {
    require_once $youtubeLibrary;
    $youtubeLibraryReady = function_exists('RADIO_youtubeConfig')
        && function_exists('RADIO_youtubeStatus')
        && function_exists('RADIO_youtubeSaveConfig')
        && function_exists('RADIO_youtubeSetManualRequest');
}

function radio_youtube_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function radio_youtube_status_html($status)
{
    global $LANG_RADIO;

    $pid = isset($status['pid']) ? (int) $status['pid'] : 0;
    $reportedRunning = !empty($status['running']) && $pid > 1;
    $lastWorkerTs = !empty($status['last_check']) ? strtotime((string) $status['last_check']) : false;
    $workerAge = $lastWorkerTs !== false ? max(0, time() - $lastWorkerTs) : null;
    $workerFresh = $workerAge !== null && $workerAge <= 120;
    $processAlive = $workerFresh
        && $reportedRunning
        && function_exists('RADIO_youtubePidRunning')
        && RADIO_youtubePidRunning($pid);

    $html = '';

    if (!$workerFresh) {
        $html .= '<div class="radio-youtube-idle-card">'
            . '<span class="radio-youtube-idle-dot"></span>'
            . '<strong>' . radio_youtube_h($LANG_RADIO['youtube_status_unknown']) . '</strong>'
            . '</div>';
    } elseif ($processAlive) {
        $html .= '<div class="radio-youtube-live-card">'
            . '<div class="radio-youtube-live-card__header">'
            . '<span class="radio-youtube-live-badge"><span class="radio-youtube-live-badge__dot"></span>LIVE</span>'
            . '<strong>' . radio_youtube_h($LANG_RADIO['youtube_running']) . '</strong>'
            . '</div>'
            . '<p class="radio-youtube-status__state radio-youtube-status__state--running">';
        if (!empty($status['program_title'])) {
            $html .= '<br><span class="radio-youtube-status__program">'
                . radio_youtube_h($status['program_title'])
                . '</span>';
        }
        $html .= ' · PID ' . $pid . '</p>';

        if (!empty($status['program_id'])) {
            $elapsed = 0;
            if (!empty($status['started_at'])) {
                $startedAt = strtotime((string) $status['started_at']);
                if ($startedAt !== false) {
                    $elapsed = max(0, time() - $startedAt);
                }
            }

            $currentMedia = RADIO_resolveProgramPlayback((int) $status['program_id'], $elapsed);
            if ($currentMedia !== false && !empty($currentMedia['title'])) {
                $html .= '<p class="radio-youtube-status__track"><strong>'
                    . radio_youtube_h($LANG_RADIO['youtube_now_playing'])
                    . '</strong><br>'
                    . radio_youtube_h($currentMedia['title'])
                    . '</p>';
            }
        }

        $html .= '</div>';
    } elseif ($workerFresh && $reportedRunning) {
        $html .= '<p class="radio-admin__notice"><strong>⚠ '
            . radio_youtube_h($LANG_RADIO['youtube_process_stopped'])
            . '</strong></p>';
    } else {
        $html .= '<div class="radio-youtube-idle-card">'
            . '<span class="radio-youtube-idle-dot"></span>'
            . '<strong>' . radio_youtube_h($LANG_RADIO['youtube_idle']) . '</strong>'
            . '</div>';
    }

    if (!empty($status['last_error'])) {
        $errorKey = (string) $status['last_error'];
        $errorText = isset($LANG_RADIO[$errorKey]) ? $LANG_RADIO[$errorKey] : $errorKey;
        $html .= '<p class="radio-admin__notice">' . radio_youtube_h($errorText) . '</p>';
    }

    if (!empty($status['visual_template'])) {
        $templateKey = 'youtube_template_' . (string) $status['visual_template'];
        $templateLabel = isset($LANG_RADIO[$templateKey])
            ? $LANG_RADIO[$templateKey]
            : (string) $status['visual_template'];
        $html .= '<p><small>'
            . radio_youtube_h($LANG_RADIO['youtube_visual_template'])
            . ': ' . radio_youtube_h($templateLabel)
            . '</small></p>';
    }

    if (!empty($status['video_mode'])) {
        $html .= '<p><small>Video mode: ' . radio_youtube_h($status['video_mode']) . '</small></p>';
    }

    if (!empty($status['ffmpeg_path'])) {
        $html .= '<p><small>FFmpeg: ' . radio_youtube_h($status['ffmpeg_path']) . '</small></p>';
    }

    if (isset($status['artwork_type'])) {
        $artworkLabel = (string) $status['artwork_type'];
        if (!empty($status['artwork_path'])) {
            $artworkLabel .= ' — ' . basename((string) $status['artwork_path']);
        }
        $html .= '<p><small>Artwork: ' . radio_youtube_h($artworkLabel) . '</small></p>';
    }

    $nextSchedule = RADIO_youtubeNextScheduledOccurrence(time());
    if ($nextSchedule !== false) {
        $nextLabel = date('Y-m-d H:i:s', (int) $nextSchedule['start']);
        $html .= '<p class="radio-youtube-status__next"><strong>'
            . radio_youtube_h($LANG_RADIO['youtube_next_schedule'])
            . '</strong><br>'
            . radio_youtube_h($nextSchedule['program_title'])
            . ' — ' . radio_youtube_h($nextLabel)
            . '</p>';
    }

    if (!empty($status['last_check'])) {
        $html .= '<p><strong>'
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
            $html .= '<br>' . radio_youtube_h($LANG_RADIO['youtube_worker_next_expected']);
        }

        $html .= '</small></p>';
    } else {
        $html .= '<p><strong>⚠ '
            . radio_youtube_h($LANG_RADIO['youtube_worker_not_detected'])
            . '</strong><br><small>'
            . radio_youtube_h($LANG_RADIO['youtube_worker_not_detected_help'])
            . '</small></p>';
    }

    $html .= '<p class="radio-youtube-status__refresh"><small>'
        . radio_youtube_h($LANG_RADIO['youtube_status_auto_refresh'])
        . '</small></p>';

    return $html;
}

$message = '';

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

    $document = COM_createHTMLDocument($content, array(
        'pagetitle' => isset($LANG_RADIO['youtube_live']) ? $LANG_RADIO['youtube_live'] : 'YouTube Live',
        'headercode' => RADIO_adminHeaderCode()
    ));
    COM_output($document);
    exit;
}

if (isset($_GET['youtube_status_json']) && $_GET['youtube_status_json'] === '1') {
    $liveStatus = RADIO_youtubeStatus();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(array(
        'html' => radio_youtube_status_html($liveStatus),
        'running' => !empty($liveStatus['running']),
        'pid' => isset($liveStatus['pid']) ? (int) $liveStatus['pid'] : 0,
        'last_check' => isset($liveStatus['last_check']) ? (string) $liveStatus['last_check'] : '',
        'server_time' => date('Y-m-d H:i:s')
    ));
    exit;
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['youtube_live']);
    } elseif (isset($_POST['save_youtube_visual'])) {
        $ok = RADIO_youtubeSaveVisualConfig($_POST);
        $message = COM_showMessageText(
            $ok ? $LANG_RADIO['youtube_visual_saved'] : $LANG_RADIO['youtube_save_failed'],
            $LANG_RADIO['youtube_live']
        );
    } elseif (isset($_POST['save_youtube_schedules'])) {
        $ok = RADIO_youtubeSaveScheduleIds(
            isset($_POST['schedule_ids']) && is_array($_POST['schedule_ids'])
                ? $_POST['schedule_ids']
                : array()
        );
        $message = COM_showMessageText(
            $ok ? $LANG_RADIO['youtube_schedules_saved'] : $LANG_RADIO['youtube_save_failed'],
            $LANG_RADIO['youtube_live']
        );
    } elseif (isset($_POST['youtube_start_request'])) {
        $saved = RADIO_youtubeSaveManualProgram(
            isset($_POST['manual_program_id']) ? (int) $_POST['manual_program_id'] : 0
        );
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

$youtubeConfig = RADIO_youtubeConfig();

$status = RADIO_youtubeStatus();

$schedules = RADIO_getSchedules(false);

$programs = RADIO_getPrograms(200, true);

$token = SEC_createToken();
$scheduleIds = array_flip($youtubeConfig['schedule_ids']);

$content = '';

if (!$youtubeConfig['enabled'] || trim((string) $youtubeConfig['stream_key']) === '') {
    $content .= '<p class="radio-admin__notice">'
        . radio_youtube_h($LANG_RADIO['youtube_configuration_required'])
        . '</p>';
}

$content .= '<section class="radio-admin__panel radio-youtube-status">'
    . '<h2>' . radio_youtube_h($LANG_RADIO['youtube_status']) . '</h2>'
    . '<div id="radio-youtube-status" data-status-url="'
    . radio_youtube_h(rtrim($_CONF['site_admin_url'], '/') . '/plugins/radio/youtube.php?youtube_status_json=1')
    . '">'
    . radio_youtube_status_html($status)
    . '</div>'
    . '</section>';

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_manual_live']) . '</h2>'
    . '<p><small>' . radio_youtube_h($LANG_RADIO['youtube_manual_live_help']) . '</small></p>'
    . '<form method="post" action="">'
    . '<p><label>' . radio_youtube_h($LANG_RADIO['programs']) . ' '
    . '<select name="manual_program_id"><option value="0">—</option>';

foreach ($programs as $program) {
    $content .= '<option value="' . (int) $program['program_id'] . '"'
        . ((int) $youtubeConfig['manual_program_id'] === (int) $program['program_id'] ? ' selected' : '') . '>'
        . radio_youtube_h($program['title']) . '</option>';
}

$content .= '</select></label></p>'
    . '<p class="radio-youtube-actions">'
    . '<button type="submit" name="youtube_start_request" value="1">'
    . radio_youtube_h($LANG_RADIO['youtube_start_now']) . '</button> '
    . '<button type="submit" name="youtube_stop_request" value="1">'
    . radio_youtube_h($LANG_RADIO['youtube_stop']) . '</button>'
    . '</p>'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_youtube_h($token) . '">'
    . '</form></section>';

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_video_appearance']) . '</h2>'
    . '<form method="post" action="">'
    . '<div class="radio-youtube-appearance">'
    . '<p><label>' . radio_youtube_h($LANG_RADIO['youtube_visual_template']) . ' '
    . '<select name="visual_template">'
    . '<option value="stationcard"' . ($youtubeConfig['visual_template'] === 'stationcard' ? ' selected' : '') . '>'
    . radio_youtube_h($LANG_RADIO['youtube_template_stationcard']) . '</option>'
    . '<option value="fullbackground"' . ($youtubeConfig['visual_template'] === 'fullbackground' ? ' selected' : '') . '>'
    . radio_youtube_h($LANG_RADIO['youtube_template_fullbackground']) . '</option>'
    . '<option value="minimal"' . ($youtubeConfig['visual_template'] === 'minimal' ? ' selected' : '') . '>'
    . radio_youtube_h($LANG_RADIO['youtube_template_minimal']) . '</option>'
    . '<option value="visualizer"' . ($youtubeConfig['visual_template'] === 'visualizer' ? ' selected' : '') . '>'
    . radio_youtube_h($LANG_RADIO['youtube_template_visualizer']) . '</option>'
    . '</select></label></p>'
    . '<p><label><input type="checkbox" name="show_station" value="1"'
    . (!empty($youtubeConfig['show_station']) ? ' checked' : '') . '> '
    . radio_youtube_h($LANG_RADIO['youtube_show_station']) . '</label></p>'
    . '<p><label>' . radio_youtube_h($LANG_RADIO['youtube_station_name']) . ' '
    . '<input type="text" name="station_name" value="' . radio_youtube_h($youtubeConfig['station_name']) . '" size="32" maxlength="70"'
    . ' placeholder="' . radio_youtube_h($LANG_RADIO['youtube_station_name_placeholder']) . '"></label></p>'
    . '<p><label><input type="checkbox" name="show_program" value="1"'
    . (!empty($youtubeConfig['show_program']) ? ' checked' : '') . '> '
    . radio_youtube_h($LANG_RADIO['youtube_show_program']) . '</label></p>'
    . '<p><label><input type="checkbox" name="show_track" value="1"'
    . (!empty($youtubeConfig['show_track']) ? ' checked' : '') . '> '
    . radio_youtube_h($LANG_RADIO['youtube_show_track']) . '</label></p>'
    . '<p><label><input type="checkbox" name="show_artwork" value="1"'
    . (!empty($youtubeConfig['show_artwork']) ? ' checked' : '') . '> '
    . radio_youtube_h($LANG_RADIO['youtube_show_artwork']) . '</label></p>'
    . '<p><label><input type="checkbox" name="show_visualizer" value="1"'
    . (!empty($youtubeConfig['show_visualizer']) ? ' checked' : '') . '> '
    . radio_youtube_h($LANG_RADIO['youtube_show_visualizer']) . '</label> '
    . '<label>' . radio_youtube_h($LANG_RADIO['youtube_visualizer_size']) . ' '
    . '<select name="visualizer_size">'
    . '<option value="small"' . ($youtubeConfig['visualizer_size'] === 'small' ? ' selected' : '') . '>'
    . radio_youtube_h($LANG_RADIO['youtube_size_small']) . '</option>'
    . '<option value="medium"' . ($youtubeConfig['visualizer_size'] === 'medium' ? ' selected' : '') . '>'
    . radio_youtube_h($LANG_RADIO['youtube_size_medium']) . '</option>'
    . '<option value="large"' . ($youtubeConfig['visualizer_size'] === 'large' ? ' selected' : '') . '>'
    . radio_youtube_h($LANG_RADIO['youtube_size_large']) . '</option>'
    . '</select></label></p>'
    . '<p><small>' . radio_youtube_h($LANG_RADIO['youtube_visual_changes_help']) . '</small></p>'
    . '<p><small>' . radio_youtube_h($LANG_RADIO['youtube_image_recommendations']) . '</small></p>'
    . '</div>'
    . '<p class="radio-youtube-actions"><button type="submit" name="save_youtube_visual" value="1">'
    . radio_youtube_h($LANG_RADIO['youtube_save_appearance']) . '</button></p>'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_youtube_h($token) . '">'
    . '</form></section>';

$content .= '<section class="radio-admin__panel"><h2>'
    . radio_youtube_h($LANG_RADIO['youtube_scheduled_output']) . '</h2>'
    . '<p><small>' . radio_youtube_h($LANG_RADIO['youtube_scheduled_output_help']) . '</small></p>'
    . '<form method="post" action="">';

$publishedScheduleCount = 0;
foreach ($schedules as $schedule) {
    if (isset($schedule['program_status']) && $schedule['program_status'] !== 'published') {
        continue;
    }
    $publishedScheduleCount++;
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

if ($publishedScheduleCount === 0) {
    $content .= '<p>' . radio_youtube_h($LANG_RADIO['youtube_no_schedules']) . '</p>';
} else {
    $content .= '<p class="radio-youtube-actions"><button type="submit" name="save_youtube_schedules" value="1">'
        . radio_youtube_h($LANG_RADIO['youtube_save_schedules']) . '</button></p>';
}

$content .= '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . radio_youtube_h($token) . '">'
    . '</form></section>';

$content .= '<details class="radio-admin__panel radio-admin__details"><summary>'
    . radio_youtube_h($LANG_RADIO['youtube_configuration'])
    . '</summary><div class="radio-admin__details-body">'
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
    )
    . ' <small>(' . radio_youtube_h($LANG_RADIO['youtube_mode_legacy_help']) . ')</small></dd>'
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
    . '</dl></div></details>';

$content .= '<details class="radio-admin__panel radio-admin__details"><summary>'
    . radio_youtube_h(isset($LANG_RADIO['youtube_getting_started']) ? $LANG_RADIO['youtube_getting_started'] : 'Getting started')
    . '</summary><div class="radio-admin__details-body">'
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
    . '</div></details>';

$worker = $_CONF['path'] . 'plugins/radio/bin/youtube-live.php';

$phpCli = '';
$phpCandidates = array(
    '/usr/bin/php',
    '/usr/local/bin/php',
    '/usr/bin/php8.1',
    '/usr/local/bin/php8.1',
    '/usr/bin/php81',
    '/usr/local/bin/php81',
    'php-cli',
    'php'
);
foreach ($phpCandidates as $candidate) {
    $probeOutput = array();
    $probeCode = 1;
    @exec(escapeshellcmd($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>/dev/null', $probeOutput, $probeCode);
    if ($probeCode === 0 && isset($probeOutput[0]) && trim((string) $probeOutput[0]) === 'cli') {
        $phpCli = $candidate;
        break;
    }
}

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
$command = ($phpCli !== '' ? $phpCli : 'php-cli') . ' ' . escapeshellarg($worker)
    . ' --geeklog-root=' . escapeshellarg($root)
    . ($siteHost !== '' ? ' --host=' . escapeshellarg($siteHost) : '');
$logDir = isset($_CONF['path_log']) ? rtrim((string) $_CONF['path_log'], '/\\') : '';
$radioLog = $logDir !== '' ? $logDir . DIRECTORY_SEPARATOR . 'radio.log' : 'radio.log';
$cronCommand = ($phpCli !== '' ? $phpCli : 'php-cli')
    . ' -d display_errors=0 ' . escapeshellarg($worker)
    . ' --geeklog-root=' . escapeshellarg($root)
    . ($siteHost !== '' ? ' --host=' . escapeshellarg($siteHost) : '')
    . ' --quiet >> ' . escapeshellarg($radioLog) . ' 2>&1';

$content .= '<details class="radio-admin__panel radio-admin__details"><summary>'
    . radio_youtube_h($LANG_RADIO['youtube_worker'])
    . '</summary><div class="radio-admin__details-body">'
    . '<p>' . radio_youtube_h($LANG_RADIO['youtube_worker_help']) . '</p>'
    . ($phpCli === ''
        ? '<p class="radio-admin__notice">⚠ ' . radio_youtube_h($LANG_RADIO['youtube_cli_not_detected']) . '</p>'
        : '<p><strong>PHP CLI:</strong> <code>' . radio_youtube_h($phpCli) . '</code></p>')
    . '<pre><code>' . radio_youtube_h($command) . '</code></pre>'
    . '<p>' . radio_youtube_h($LANG_RADIO['youtube_worker_cron_help']) . '</p>'
    . '<pre><code>* * * * * ' . radio_youtube_h($cronCommand) . '</code></pre>'
    . '<p><small>' . radio_youtube_h($LANG_RADIO['youtube_beta_warning']) . '</small></p>'
    . '</div></details>';

$content = RADIO_adminRenderPage(
    'youtube',
    $LANG_RADIO['youtube_live'],
    $LANG_RADIO['admin_youtube_intro'],
    $LANG_RADIO['admin_youtube_help_title'],
    $LANG_RADIO['admin_youtube_help_text'],
    $content,
    $message
);

$document = COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['youtube_live'],
    'headercode' => RADIO_adminHeaderCode()
));

COM_output($document);
