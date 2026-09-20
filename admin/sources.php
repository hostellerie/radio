<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio feed sources without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;
$message = '';
$preview = false;
$previewSource = false;

if (isset($_POST['save_source'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['feed_sources']);
    } else {
        $error = '';
        $saved = RADIO_saveFeedSource(isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0, $_POST, $error);
        $key = $saved !== false ? 'feed_source_saved' : (isset($LANG_RADIO[$error]) ? $error : 'feed_source_save_failed');
        $message = COM_showMessageText($LANG_RADIO[$key], $LANG_RADIO['feed_sources']);
    }
}

if (isset($_POST['delete_source'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['feed_sources']);
    } else {
        $id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        $message = COM_showMessageText(RADIO_deleteFeedSource($id) ? $LANG_RADIO['feed_source_deleted'] : $LANG_RADIO['feed_source_delete_failed'], $LANG_RADIO['feed_sources']);
    }
}

if (isset($_POST['preview_source']) || isset($_POST['import_episode'])) {
    $sourceId = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
    $previewSource = RADIO_getFeedSource($sourceId, false);
    if ($previewSource !== false) {
        $error = '';
        $preview = RADIO_fetchFeedSource($previewSource, $error, !isset($_POST['import_episode']));
        if ($preview === false) {
            $key = isset($LANG_RADIO[$error]) ? $error : 'feed_fetch_failed';
            $message .= COM_showMessageText($LANG_RADIO[$key], $LANG_RADIO['feed_sources']);
        } elseif (!empty($preview['not_modified'])) {
            $message .= COM_showMessageText($LANG_RADIO['feed_not_modified'], $LANG_RADIO['feed_sources']);
        }
    }
}

if (isset($_POST['import_episode']) && SEC_checkToken() && is_array($preview) && $previewSource !== false) {
    $episodeKey = isset($_POST['episode_key']) ? (string)$_POST['episode_key'] : '';
    $found = false;
    foreach ($preview['items'] as $episode) {
        if ($episode['key'] === $episodeKey) { $found = $episode; break; }
    }
    if ($found !== false) {
        $error = '';
        $imported = RADIO_importFeedEpisode($previewSource, $found, $error);
        $key = $imported !== false ? 'feed_imported' : (isset($LANG_RADIO[$error]) ? $error : 'feed_import_failed');
        $message .= COM_showMessageText($LANG_RADIO[$key], $LANG_RADIO['feed_sources']);
    }
}

$sources = RADIO_getFeedSources(100, false);
$token = SEC_createToken();

$content = COM_startBlock($LANG_RADIO['feed_sources'], '', COM_getBlockTemplate('_admin_block', 'header'));
$content .= $message;
$content .= '<p><a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/index.php', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($LANG_RADIO['back_to_library_admin'], ENT_QUOTES, 'UTF-8') . '</a></p>';
$content .= '<h2>' . htmlspecialchars($LANG_RADIO['feed_source_new'], ENT_QUOTES, 'UTF-8') . '</h2>'
    . '<form method="post" action="">'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['feed_source_title'], ENT_QUOTES, 'UTF-8') . '<br><input type="text" name="source_title" maxlength="255" required style="width:100%"></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['source_url'], ENT_QUOTES, 'UTF-8') . '<br><input type="url" name="source_url" maxlength="2048" required style="width:100%"></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['source_provider'], ENT_QUOTES, 'UTF-8') . '<br><input type="text" name="source_provider" maxlength="255" style="width:100%"></label></p>'
    . '<p><label><input type="checkbox" name="source_enabled" value="1" checked> ' . htmlspecialchars($LANG_RADIO['enabled'], ENT_QUOTES, 'UTF-8') . '</label></p>'
    . '<fieldset><legend>' . htmlspecialchars($LANG_RADIO['permissions'], ENT_QUOTES, 'UTF-8') . '</legend>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['group'], ENT_QUOTES, 'UTF-8') . ' ' . SEC_getGroupDropdown(RADIO_defaultGroupId(), 3) . '</label></p>'
    . SEC_getPermissionsHTML(3, 2, 2, 0) . '</fieldset>'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<p><button type="submit" name="save_source" value="1">' . htmlspecialchars($LANG_RADIO['save'], ENT_QUOTES, 'UTF-8') . '</button></p></form>';

foreach ($sources as $source) {
    $content .= '<hr><form method="post" action=""><p><strong>' . htmlspecialchars($source['title'], ENT_QUOTES, 'UTF-8') . '</strong><br><code>'
        . htmlspecialchars($source['source_url'], ENT_QUOTES, 'UTF-8') . '</code></p><p><small>'
        . htmlspecialchars($LANG_RADIO['feed_last_checked'], ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars((string)$source['last_checked'], ENT_QUOTES, 'UTF-8') . ' · '
        . htmlspecialchars($LANG_RADIO['feed_last_status'], ENT_QUOTES, 'UTF-8') . ': ' . (int)$source['last_status']
        . (!empty($source['last_error']) ? ' · ' . htmlspecialchars($LANG_RADIO['feed_last_error'], ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($source['last_error'], ENT_QUOTES, 'UTF-8') : '')
        . '</small></p><input type="hidden" name="source_id" value="' . (int)$source['source_id'] . '"><input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" name="preview_source" value="1">' . htmlspecialchars($LANG_RADIO['feed_preview'], ENT_QUOTES, 'UTF-8') . '</button> '
        . '<button type="submit" name="delete_source" value="1">' . htmlspecialchars($LANG_RADIO['delete'], ENT_QUOTES, 'UTF-8') . '</button></form>';
}

if (is_array($preview) && $previewSource !== false && empty($preview['not_modified'])) {
    $content .= '<h2>' . htmlspecialchars($LANG_RADIO['feed_items'], ENT_QUOTES, 'UTF-8') . '</h2>';
    if (empty($preview['items'])) {
        $content .= '<p>' . htmlspecialchars($LANG_RADIO['feed_items_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
    } else {
        foreach ($preview['items'] as $episode) {
            $content .= '<article style="margin:1rem 0;padding:1rem;border:1px solid rgba(127,127,127,.3)"><strong>'
                . htmlspecialchars($episode['title'], ENT_QUOTES, 'UTF-8') . '</strong>'
                . (!empty($episode['published']) ? '<br><small>' . htmlspecialchars($episode['published'], ENT_QUOTES, 'UTF-8') . '</small>' : '')
                . (!empty($episode['description']) ? '<p>' . htmlspecialchars(COM_truncate($episode['description'], 500, '...'), ENT_QUOTES, 'UTF-8') . '</p>' : '')
                . '<form method="post" action=""><input type="hidden" name="source_id" value="' . (int)$previewSource['source_id'] . '">'
                . '<input type="hidden" name="episode_key" value="' . htmlspecialchars($episode['key'], ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" name="import_episode" value="1">' . htmlspecialchars($LANG_RADIO['feed_import'], ENT_QUOTES, 'UTF-8') . '</button></form></article>';
        }
    }
}
$content .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));
COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['feed_sources'])));
