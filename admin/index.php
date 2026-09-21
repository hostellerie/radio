<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO;

$message = '';
if (isset($_GET['updated']) && (int) $_GET['updated'] === 1) {
    $message = COM_showMessageText(
        $LANG_RADIO['media_saved'],
        $LANG_RADIO['admin_title']
    );
}

$storage = RADIO_storageDir();
$ready = RADIO_ensureStorage();
$media = RADIO_getMediaList(100, false);

$content = '<section class="radio-admin__panel"><h2>'
    . htmlspecialchars($LANG_RADIO['storage'], ENT_QUOTES, 'UTF-8') . '</h2><p><strong>'
    . htmlspecialchars($ready ? $LANG_RADIO['storage_ready'] : $LANG_RADIO['storage_unavailable'], ENT_QUOTES, 'UTF-8')
    . '</strong><br><code>' . htmlspecialchars($storage, ENT_QUOTES, 'UTF-8') . '</code></p></section>';

$content .= RADIO_adminQuickActions();
$content .= RADIO_adminRenderMediaList($media);

$content = RADIO_adminRenderPage(
    'library',
    $LANG_RADIO['admin_title'],
    $LANG_RADIO['admin_library_intro'],
    $LANG_RADIO['admin_library_help_title'],
    $LANG_RADIO['admin_library_help_text'],
    $content,
    $message
);

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['admin_title'],
    'headercode' => RADIO_adminHeaderCode()
)));
