<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO;
$storage = RADIO_storageDir();
$ready = RADIO_ensureStorage();

$content = COM_startBlock($LANG_RADIO['admin_title'], '', COM_getBlockTemplate('_admin_block', 'header'));
$content .= '<p>' . htmlspecialchars($LANG_RADIO['admin_intro'], ENT_QUOTES, 'UTF-8') . '</p>';
$content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['storage'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . htmlspecialchars($ready ? $LANG_RADIO['storage_ready'] : $LANG_RADIO['storage_unavailable'], ENT_QUOTES, 'UTF-8')
    . '<br><code>' . htmlspecialchars($storage, ENT_QUOTES, 'UTF-8') . '</code></p>';
$content .= '<p>' . htmlspecialchars($LANG_RADIO['development_notice'], ENT_QUOTES, 'UTF-8') . '</p>';
$content .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['admin_title'])));
