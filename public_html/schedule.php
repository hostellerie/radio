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

$today = date('Y-m-d');
$previousUrl = '?week=' . date('Y-m-d', strtotime('-7 days', $weekStart));
$nextUrl = '?week=' . date('Y-m-d', strtotime('+7 days', $weekStart));
$weekLabel = date('Y-m-d', $weekStart) . ' → ' . date('Y-m-d', strtotime('-1 second', $weekEnd));

$content = '<div class="radio-public radio-public-schedule">'
    . '<header class="radio-schedule-header">'
    . '<div><span class="radio-schedule-kicker">' . htmlspecialchars($LANG_RADIO['plugin_name'], ENT_QUOTES, 'UTF-8') . '</span>'
    . '<h1>' . htmlspecialchars($LANG_RADIO['public_schedule'], ENT_QUOTES, 'UTF-8') . '</h1></div>'
    . '<nav class="radio-week-nav" aria-label="' . htmlspecialchars($LANG_RADIO['public_schedule'], ENT_QUOTES, 'UTF-8') . '">'
    . '<a class="radio-week-nav__previous" href="' . htmlspecialchars($previousUrl, ENT_QUOTES, 'UTF-8') . '">← '
    . htmlspecialchars($LANG_RADIO['previous_week'], ENT_QUOTES, 'UTF-8') . '</a>'
    . '<strong class="radio-week-range">' . htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8') . '</strong>'
    . '<a class="radio-week-nav__next" href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['next_week'], ENT_QUOTES, 'UTF-8') . ' →</a>'
    . '</nav></header>';

$content .= '<div class="radio-schedule-grid">';
for ($day = 0; $day < 7; $day++) {
    $dayTs = strtotime('+' . $day . ' days', $weekStart);
    $date = date('Y-m-d', $dayTs);
    $isToday = $date === $today;

    $content .= '<section class="radio-day-card' . ($isToday ? ' radio-day-card--today' : '') . '">'
        . '<header class="radio-day-card__header">'
        . '<div class="radio-day-name">'
        . htmlspecialchars($LANG_RADIO['weekday_' . (int) date('N', $dayTs)], ENT_QUOTES, 'UTF-8')
        . '</div>'
        . '<time datetime="' . $date . '" class="radio-day-date">' . $date . '</time>'
        . '</header>'
        . '<div class="radio-day-card__body">';

    $has = false;
    foreach ($occurrences as $occ) {
        if ($occ['program_status'] !== 'published' || date('Y-m-d', $occ['start']) !== $date) {
            continue;
        }

        $has = true;
        $url = $_CONF['site_url'] . '/radio/program.php?id=' . (int) $occ['program_id'];
        $content .= '<article class="radio-schedule-slot">'
            . '<time class="radio-schedule-slot__time" datetime="' . date('c', $occ['start']) . '">'
            . date('H:i', $occ['start']) . '–' . date('H:i', $occ['end']) . '</time>'
            . '<a class="radio-schedule-slot__title" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($occ['program_title'], ENT_QUOTES, 'UTF-8') . '</a>'
            . '</article>';
    }

    if (!$has) {
        $content .= '<div class="radio-day-empty" aria-hidden="true">—</div>';
    }

    $content .= '</div></section>';
}
$content .= '</div>';

$content .= '<nav class="radio-schedule-footer">'
    . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/replay.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['replays'], ENT_QUOTES, 'UTF-8') . '</a>'
    . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a>'
    . '</nav></div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['public_schedule'] . ' - ' . $title)));
