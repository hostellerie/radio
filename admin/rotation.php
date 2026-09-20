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

$content = '<section class="radio-admin__panel">';
$content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['rotation_enabled'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . htmlspecialchars(!empty($_RADIO_CONF['fallback_enabled']) ? $LANG_RADIO['yes'] : $LANG_RADIO['no'], ENT_QUOTES, 'UTF-8') . '<br>'
    . '<strong>' . htmlspecialchars($LANG_RADIO['rotation_jingle_interval'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . (int) (isset($_RADIO_CONF['fallback_jingle_interval']) ? $_RADIO_CONF['fallback_jingle_interval'] : 4) . '<br>'
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
        . htmlspecialchars($current['media_type'], ENT_QUOTES, 'UTF-8') . ' — '
        . (int) $current['offset'] . 's / ' . (int) $current['duration'] . 's</p>';
}

$content .= '<h2>' . htmlspecialchars($LANG_RADIO['rotation_today'], ENT_QUOTES, 'UTF-8') . '</h2>';
if (count($sequence) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['rotation_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ol>';
    foreach ($sequence as $item) {
        $content .= '<li><strong>' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong> '
            . '<small>(' . htmlspecialchars($item['media_type'], ENT_QUOTES, 'UTF-8') . ' · '
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
