<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';

if (!SEC_hasRights('radio.schedule')) {
    COM_accessLog('User tried to access Radio schedule administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;
$message = '';
$selectedId = isset($_REQUEST['schedule_id']) ? (int) $_REQUEST['schedule_id'] : 0;

if (isset($_POST['save_schedule'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['schedule']);
    } else {
        $error = '';
        $saved = RADIO_saveSchedule($selectedId, $_POST, $error);
        if ($saved !== false) {
            $selectedId = (int) $saved;
            $message = COM_showMessageText($LANG_RADIO['schedule_saved'], $LANG_RADIO['schedule']);
        } else {
            $key = isset($LANG_RADIO[$error]) ? $error : 'schedule_save_failed';
            $message = COM_showMessageText($LANG_RADIO[$key], $LANG_RADIO['schedule']);
        }
    }
}

if (isset($_POST['delete_schedule'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['schedule']);
    } else {
        $ok = RADIO_deleteSchedule($selectedId);
        $selectedId = 0;
        $message = COM_showMessageText($ok ? $LANG_RADIO['schedule_deleted'] : $LANG_RADIO['schedule_delete_failed'], $LANG_RADIO['schedule']);
    }
}

$selected = $selectedId > 0 ? RADIO_getSchedule($selectedId) : false;
$programs = RADIO_getPrograms(200, false);
$schedules = RADIO_getSchedules(false);
$token = SEC_createToken();

$weekStart = isset($_GET['week']) ? strtotime($_GET['week'] . ' 00:00:00') : false;
if ($weekStart === false) {
    $weekStart = strtotime('monday this week 00:00:00');
}
$weekStart = strtotime('monday this week 00:00:00', $weekStart);
$weekEnd = strtotime('+7 days', $weekStart);
$occurrences = RADIO_getOccurrences($weekStart, $weekEnd, false);

$content = COM_startBlock($LANG_RADIO['schedule'], '', COM_getBlockTemplate('_admin_block', 'header'));
$content .= $message;
$content .= '<p><a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library_admin'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/programs.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['manage_programs'], ENT_QUOTES, 'UTF-8') . '</a></p>';

$content .= '<h2>' . htmlspecialchars($selected ? $LANG_RADIO['edit_schedule'] : $LANG_RADIO['new_schedule'], ENT_QUOTES, 'UTF-8') . '</h2>';
if (count($programs) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['schedule_requires_program'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $startValue = $selected ? date('Y-m-d\TH:i', strtotime($selected['starts_at'])) : date('Y-m-d\TH:i', strtotime('+1 hour'));
    $endValue = $selected ? date('Y-m-d\TH:i', strtotime($selected['ends_at'])) : date('Y-m-d\TH:i', strtotime('+2 hours'));
    $selectedDays = $selected && $selected['weekdays'] !== '' ? array_map('intval', explode(',', $selected['weekdays'])) : array();

    $content .= '<form method="post" action=""><p><label>' . htmlspecialchars($LANG_RADIO['programs'], ENT_QUOTES, 'UTF-8')
        . ' <select name="program_id">';
    foreach ($programs as $program) {
        $content .= '<option value="' . (int) $program['program_id'] . '"'
            . ($selected && (int) $selected['program_id'] === (int) $program['program_id'] ? ' selected' : '') . '>'
            . htmlspecialchars($program['title'], ENT_QUOTES, 'UTF-8') . ' [' . htmlspecialchars($program['status'], ENT_QUOTES, 'UTF-8') . ']</option>';
    }
    $content .= '</select></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['starts_at'], ENT_QUOTES, 'UTF-8')
        . ' <input type="datetime-local" name="starts_at" value="' . htmlspecialchars($startValue, ENT_QUOTES, 'UTF-8') . '" required></label> '
        . '<label>' . htmlspecialchars($LANG_RADIO['ends_at'], ENT_QUOTES, 'UTF-8')
        . ' <input type="datetime-local" name="ends_at" value="' . htmlspecialchars($endValue, ENT_QUOTES, 'UTF-8') . '" required></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['recurrence'], ENT_QUOTES, 'UTF-8') . ' <select name="recurrence">';

    $recurrences = array('once','daily','weekly','weekdays');
    foreach ($recurrences as $recurrence) {
        $content .= '<option value="' . $recurrence . '"'
            . ($selected && $selected['recurrence'] === $recurrence ? ' selected' : '') . '>'
            . htmlspecialchars($LANG_RADIO['recurrence_' . $recurrence], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $content .= '</select></label></p><p>' . htmlspecialchars($LANG_RADIO['weekdays'], ENT_QUOTES, 'UTF-8') . ': ';
    for ($day=1; $day<=7; $day++) {
        $content .= '<label style="margin-right:.7rem"><input type="checkbox" name="weekdays[]" value="' . $day . '"'
            . (in_array($day, $selectedDays, true) ? ' checked' : '') . '> '
            . htmlspecialchars($LANG_RADIO['weekday_' . $day], ENT_QUOTES, 'UTF-8') . '</label>';
    }
    $content .= '</p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['active_from'], ENT_QUOTES, 'UTF-8')
        . ' <input type="date" name="active_from" value="'
        . htmlspecialchars($selected && $selected['active_from'] ? $selected['active_from'] : date('Y-m-d', strtotime($startValue)), ENT_QUOTES, 'UTF-8') . '"></label> '
        . '<label>' . htmlspecialchars($LANG_RADIO['active_until'], ENT_QUOTES, 'UTF-8')
        . ' <input type="date" name="active_until" value="'
        . htmlspecialchars($selected && $selected['active_until'] ? $selected['active_until'] : '', ENT_QUOTES, 'UTF-8') . '"></label></p>'
        . '<p><label><input type="checkbox" name="enabled" value="1"' . (!$selected || !empty($selected['enabled']) ? ' checked' : '') . '> '
        . htmlspecialchars($LANG_RADIO['enabled'], ENT_QUOTES, 'UTF-8') . '</label></p>'
        . '<input type="hidden" name="schedule_id" value="' . (int) $selectedId . '">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" name="save_schedule" value="1">' . htmlspecialchars($LANG_RADIO['save'], ENT_QUOTES, 'UTF-8') . '</button>';
    if ($selected) {
        $content .= ' <button type="submit" name="delete_schedule" value="1" onclick="return confirm('
            . htmlspecialchars(json_encode($LANG_RADIO['confirm_schedule_delete']), ENT_QUOTES, 'UTF-8') . ');">'
            . htmlspecialchars($LANG_RADIO['delete'], ENT_QUOTES, 'UTF-8') . '</button>';
    }
    $content .= '</form>';
}

$content .= '<h2>' . htmlspecialchars($LANG_RADIO['week_view'], ENT_QUOTES, 'UTF-8') . '</h2>';
$content .= '<p><a href="?week=' . date('Y-m-d', strtotime('-7 days', $weekStart)) . '">← '
    . htmlspecialchars($LANG_RADIO['previous_week'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<strong>' . date('Y-m-d', $weekStart) . ' → ' . date('Y-m-d', strtotime('-1 second', $weekEnd)) . '</strong> · '
    . '<a href="?week=' . date('Y-m-d', strtotime('+7 days', $weekStart)) . '">'
    . htmlspecialchars($LANG_RADIO['next_week'], ENT_QUOTES, 'UTF-8') . ' →</a></p>';

$content .= '<div style="display:grid;grid-template-columns:repeat(7,minmax(130px,1fr));gap:.5rem;overflow-x:auto">';
for ($day=0; $day<7; $day++) {
    $dayTs = strtotime('+' . $day . ' days', $weekStart);
    $date = date('Y-m-d', $dayTs);
    $content .= '<section style="border:1px solid rgba(127,127,127,.3);padding:.6rem;min-width:130px"><h3>'
        . htmlspecialchars($LANG_RADIO['weekday_' . (int) date('N', $dayTs)], ENT_QUOTES, 'UTF-8') . '<br><small>' . $date . '</small></h3>';
    $has = false;
    foreach ($occurrences as $occ) {
        if (date('Y-m-d', $occ['start']) === $date) {
            $has = true;
            $content .= '<p><a href="?schedule_id=' . (int) $occ['schedule_id'] . '"><strong>'
                . date('H:i', $occ['start']) . '–' . date('H:i', $occ['end']) . '</strong><br>'
                . htmlspecialchars($occ['program_title'], ENT_QUOTES, 'UTF-8') . '</a></p>';
        }
    }
    if (!$has) {
        $content .= '<p><small>—</small></p>';
    }
    $content .= '</section>';
}
$content .= '</div>';

$content .= '<h2>' . htmlspecialchars($LANG_RADIO['schedule_entries'], ENT_QUOTES, 'UTF-8') . '</h2><ul>';
foreach ($schedules as $row) {
    $content .= '<li><a href="?schedule_id=' . (int) $row['schedule_id'] . '">'
        . htmlspecialchars($row['program_title'], ENT_QUOTES, 'UTF-8') . '</a> — '
        . htmlspecialchars($row['starts_at'], ENT_QUOTES, 'UTF-8') . ' — '
        . htmlspecialchars($LANG_RADIO['recurrence_' . $row['recurrence']], ENT_QUOTES, 'UTF-8')
        . (!empty($row['enabled']) ? '' : ' [' . htmlspecialchars($LANG_RADIO['disabled'], ENT_QUOTES, 'UTF-8') . ']')
        . '</li>';
}
$content .= '</ul>';
$content .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['schedule'])));
