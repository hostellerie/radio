<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio statistics without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;

$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
if (!in_array($days, array(7, 30, 90), true)) {
    $days = 30;
}
$stats = RADIO_getStatsSummary($days);

$content = '<section class="radio-admin__panel"><p class="radio-admin__muted">'
    . htmlspecialchars($LANG_RADIO['stats_privacy_note'], ENT_QUOTES, 'UTF-8') . '</p>';
$content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['stats_period'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . '<a href="?days=7">7</a> · <a href="?days=30">30</a> · <a href="?days=90">90</a> days</p>';
$content .= '<ul>'
    . '<li><strong>' . htmlspecialchars($LANG_RADIO['stats_plays'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . (int) $stats['plays'] . '</li>'
    . '<li><strong>' . htmlspecialchars($LANG_RADIO['stats_downloads'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . (int) $stats['downloads'] . '</li>'
    . '<li><strong>' . htmlspecialchars($LANG_RADIO['stats_listen_time'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . gmdate('H:i:s', (int) $stats['listen_seconds']) . '</li>'
    . '</ul>';

$content .= '<h2>' . htmlspecialchars($LANG_RADIO['stats_top_media'], ENT_QUOTES, 'UTF-8') . '</h2>';
if (empty($stats['top_media'])) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['admin_empty_value'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ol>';
    foreach ($stats['top_media'] as $item) {
        $content .= '<li><a href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</a> — '
            . (int) $item['plays'] . '</li>';
    }
    $content .= '</ol>';
}
$content = RADIO_adminRenderPage(
    'stats',
    $LANG_RADIO['statistics'],
    $LANG_RADIO['admin_stats_intro'],
    $LANG_RADIO['admin_stats_help_title'],
    $LANG_RADIO['admin_stats_help_text'],
    $content,
    ''
);
COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['statistics'],
    'headercode' => RADIO_adminHeaderCode()
)));
