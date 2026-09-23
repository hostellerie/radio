<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';

if (!SEC_hasRights('radio.schedule')) {
    COM_accessLog('User tried to access the Radio studio without permission.');
    COM_output(COM_createHTMLDocument(COM_showMessageText($MESSAGE[29], $MESSAGE[30]), array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $_CONF;
$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
$url = rtrim($_CONF['site_admin_url'], '/') . '/plugins/radio/studio.php';
if ($programId > 0) {
    $url .= '?program_id=' . $programId;
}
header('Location: ' . $url, true, 302);
exit;
