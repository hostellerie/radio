<?php
require_once '../lib-common.php';
global $_TABLES, $_CONF, $LANG_RADIO;

$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';
$result = DB_query("SELECT media_id,title,description,media_type FROM {$_TABLES['radio_media']} WHERE status='published' ORDER BY modified DESC LIMIT 50");

$content = '<div class="radio-catalogue"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
if (DB_numRows($result) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['public_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ul>';
    while ($row = DB_fetchArray($result)) {
        $content .= '<li><strong>' . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '</strong>';
        if ($row['description'] !== '') {
            $content .= '<br>' . nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8'));
        }
        $content .= '</li>';
    }
    $content .= '</ul>';
}
$content .= '</div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title)));
