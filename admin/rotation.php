<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio rotation administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF, $_RADIO_CONF;

$sequence = RADIO_buildRotationSequence(date('Y-m-d'));
$totalDuration = RADIO_rotationDuration($sequence);
$diagnostics = RADIO_rotationDiagnostics(date('Y-m-d'));
$current = RADIO_getRotationState(time());
$jinglePool = RADIO_getRotationMediaByTypes(array('jingle'));
$jingleCount = count($jinglePool);
$jingleInterval = isset($_RADIO_CONF['fallback_jingle_interval'])
    ? max(0, (int) $_RADIO_CONF['fallback_jingle_interval'])
    : 4;

$content = '<section class="radio-admin__panel">';
$content .= '<h2>' . htmlspecialchars($LANG_RADIO['rotation_jingle_pool_title'], ENT_QUOTES, 'UTF-8') . '</h2>'
    . '<p>' . sprintf(
        htmlspecialchars($LANG_RADIO['rotation_jingle_pool_summary'], ENT_QUOTES, 'UTF-8'),
        $jingleCount,
        $jingleInterval
    ) . '</p>'
    . '<p class="radio-admin__muted">'
    . htmlspecialchars($LANG_RADIO['rotation_jingle_pool_help'], ENT_QUOTES, 'UTF-8')
    . '</p>';
$content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['rotation_enabled'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . htmlspecialchars(!empty($_RADIO_CONF['fallback_enabled']) ? $LANG_RADIO['yes'] : $LANG_RADIO['no'], ENT_QUOTES, 'UTF-8') . '<br>'
    . '<strong>' . htmlspecialchars($LANG_RADIO['rotation_jingle_interval'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . $jingleInterval . '<br>'
    . '<strong>' . htmlspecialchars($LANG_RADIO['rotation_announcement_interval'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . (int) (isset($_RADIO_CONF['fallback_announcement_interval']) ? $_RADIO_CONF['fallback_announcement_interval'] : 8) . '<br>'
    . '<strong>' . htmlspecialchars($LANG_RADIO['rotation_weights'], ENT_QUOTES, 'UTF-8') . ':</strong> <code>'
    . htmlspecialchars(isset($_RADIO_CONF['fallback_type_weights']) ? $_RADIO_CONF['fallback_type_weights'] : '', ENT_QUOTES, 'UTF-8') . '</code><br>'
    . '<strong>' . htmlspecialchars($LANG_RADIO['rotation_duration'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . ($totalDuration > 0 ? gmdate('H:i:s', $totalDuration) : '00:00:00') . '<br>'
    . '<strong>' . htmlspecialchars($LANG_RADIO['rotation_repeat_target'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . (int) $diagnostics['target_repeat_minutes'] . ' ' . htmlspecialchars($LANG_RADIO['admin_minutes_short'], ENT_QUOTES, 'UTF-8') . ' — '
    . htmlspecialchars($diagnostics['repeat_target_met'] ? $LANG_RADIO['rotation_repeat_ok'] : $LANG_RADIO['rotation_repeat_short'], ENT_QUOTES, 'UTF-8')
    . '</p></section>';

if ($current !== false) {
    $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['rotation_current'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . htmlspecialchars($current['title'], ENT_QUOTES, 'UTF-8') . ' — '
        . htmlspecialchars(RADIO_adminMediaTypeLabel($current['media_type']), ENT_QUOTES, 'UTF-8') . ' — '
        . (int) $current['offset'] . ' ' . htmlspecialchars($LANG_RADIO['admin_seconds_short'], ENT_QUOTES, 'UTF-8') . ' / ' . (int) $current['duration'] . ' ' . htmlspecialchars($LANG_RADIO['admin_seconds_short'], ENT_QUOTES, 'UTF-8') . '</p>';
}


$eligibility = RADIO_rotationEligibilityDiagnostics();
$content .= '<section class="radio-admin__panel"><h2>'
    . htmlspecialchars($LANG_RADIO['rotation_diagnostics_title'], ENT_QUOTES, 'UTF-8')
    . '</h2>';

if (count($eligibility) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['rotation_diagnostics_none'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<div class="radio-admin__table-wrap"><table class="radio-admin__table"><thead><tr>'
        . '<th>' . htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8') . '</th>'
        . '<th>' . htmlspecialchars($LANG_RADIO['status'], ENT_QUOTES, 'UTF-8') . '</th>'
        . '<th>' . htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8') . '</th>'
        . '<th>' . htmlspecialchars($LANG_RADIO['duration_seconds'], ENT_QUOTES, 'UTF-8') . '</th>'
        . '<th>' . htmlspecialchars($LANG_RADIO['rotation_public_access'], ENT_QUOTES, 'UTF-8') . '</th>'
        . '<th>' . htmlspecialchars($LANG_RADIO['rotation_eligibility'], ENT_QUOTES, 'UTF-8') . '</th>'
        . '</tr></thead><tbody>';

    foreach ($eligibility as $item) {
        $reasonLabels = array();
        foreach ($item['_rotation_reasons'] as $reason) {
            $key = 'rotation_reason_' . $reason;
            $reasonLabels[] = isset($LANG_RADIO[$key]) ? $LANG_RADIO[$key] : $reason;
        }
        $content .= '<tr><td><strong>' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong><br><small>'
            . htmlspecialchars($item['original_name'], ENT_QUOTES, 'UTF-8') . '</small></td>'
            . '<td>' . htmlspecialchars(RADIO_adminStatusLabel($item['status']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars(RADIO_adminMediaTypeLabel($item['media_type']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . (int) $item['duration'] . '</td>'
            . '<td>' . htmlspecialchars((int) $item['perm_anon'] >= 2 ? $LANG_RADIO['yes'] : $LANG_RADIO['no'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td><strong>' . htmlspecialchars($item['_rotation_eligible'] ? $LANG_RADIO['rotation_eligible'] : $LANG_RADIO['rotation_excluded'], ENT_QUOTES, 'UTF-8') . '</strong>'
            . (!$item['_rotation_eligible'] ? '<br><small>' . htmlspecialchars(implode(', ', $reasonLabels), ENT_QUOTES, 'UTF-8') . '</small>' : '')
            . '</td></tr>';
    }

    $content .= '</tbody></table></div>';
}
$content .= '</section>';

$content .= '<h2>' . htmlspecialchars($LANG_RADIO['rotation_today'], ENT_QUOTES, 'UTF-8') . '</h2>';
if (count($sequence) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['rotation_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ol>';
    foreach ($sequence as $item) {
        $content .= '<li><strong>' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong> '
            . '<small>(' . htmlspecialchars(RADIO_adminMediaTypeLabel($item['media_type']), ENT_QUOTES, 'UTF-8') . ' · '
            . gmdate('H:i:s', (int) $item['duration']) . ')</small></li>';
    }
    $content .= '</ol>';
}

$content = RADIO_adminRenderPage(
    'rotation',
    $LANG_RADIO['automatic_rotation'],
    $LANG_RADIO['admin_rotation_intro'],
    $LANG_RADIO['admin_rotation_help_title'],
    $LANG_RADIO['admin_rotation_help_text'],
    $content,
    ''
);
COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['automatic_rotation'],
    'headercode' => RADIO_adminHeaderCode()
)));
