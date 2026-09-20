<?php
require_once '../lib-common.php';
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';
$weekStart = isset($_GET['week']) ? strtotime($_GET['week'] . ' 00:00:00') : false;
if ($weekStart === false) {
    $weekStart = strtotime('monday this week 00:00:00');
}
$weekStart = strtotime('monday this week 00:00:00', $weekStart);
$weekEnd = strtotime('+7 days', $weekStart);
$occurrences = RADIO_getOccurrences($weekStart, $weekEnd, true);

$content = '<div class="radio-public-schedule"><h1>'
    . htmlspecialchars($LANG_RADIO['public_schedule'], ENT_QUOTES, 'UTF-8') . '</h1>';
$content .= '<p><a href="?week=' . date('Y-m-d', strtotime('-7 days', $weekStart)) . '">← '
    . htmlspecialchars($LANG_RADIO['previous_week'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<strong>' . date('Y-m-d', $weekStart) . ' → ' . date('Y-m-d', strtotime('-1 second', $weekEnd)) . '</strong> · '
    . '<a href="?week=' . date('Y-m-d', strtotime('+7 days', $weekStart)) . '">'
    . htmlspecialchars($LANG_RADIO['next_week'], ENT_QUOTES, 'UTF-8') . ' →</a></p>';

$content .= '<div style="display:grid;grid-template-columns:repeat(7,minmax(135px,1fr));gap:.5rem;overflow-x:auto">';
for ($day=0; $day<7; $day++) {
    $dayTs = strtotime('+' . $day . ' days', $weekStart);
    $date = date('Y-m-d', $dayTs);
    $content .= '<section style="border:1px solid rgba(127,127,127,.3);padding:.65rem;min-width:135px"><h2 style="font-size:1rem">'
        . htmlspecialchars($LANG_RADIO['weekday_' . (int) date('N', $dayTs)], ENT_QUOTES, 'UTF-8')
        . '<br><small>' . $date . '</small></h2>';
    $has = false;
    foreach ($occurrences as $occ) {
        if ($occ['program_status'] !== 'published' || date('Y-m-d', $occ['start']) !== $date) {
            continue;
        }
        $has = true;
        $url = $_CONF['site_url'] . '/radio/program.php?id=' . (int) $occ['program_id'];
        $content .= '<p><strong>' . date('H:i', $occ['start']) . '–' . date('H:i', $occ['end']) . '</strong><br>'
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($occ['program_title'], ENT_QUOTES, 'UTF-8') . '</a></p>';
    }
    if (!$has) {
        $content .= '<p><small>—</small></p>';
    }
    $content .= '</section>';
}
$content .= '</div><p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/replay.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['replays'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['public_schedule'] . ' - ' . $title)));
