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

if (isset($_POST['sync_shared_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['admin_title']);
    } elseif (RADIO_sharedMediaEnabled()) {
        $syncSummary = RADIO_syncSharedMediaLibrary(true);
        $message = COM_showMessageText(
            sprintf(
                $LANG_RADIO['shared_media_sync_result'],
                (int) $syncSummary['scanned'],
                (int) $syncSummary['imported'],
                (int) $syncSummary['updated'],
                (int) $syncSummary['errors']
            ),
            $LANG_RADIO['admin_title']
        );
    }
}

if (isset($_GET['updated']) && (int) $_GET['updated'] === 1) {
    $message = COM_showMessageText(
        $LANG_RADIO['media_saved'],
        $LANG_RADIO['admin_title']
    );
}

$sort = isset($_GET['sort']) ? (string) $_GET['sort'] : 'modified';
$direction = isset($_GET['direction']) ? strtolower((string) $_GET['direction']) : 'desc';
$allowedSorts = array('title','type','category','collection','status','availability','source','duration','size','modified');
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'modified';
}
if ($direction !== 'asc' && $direction !== 'desc') {
    $direction = 'desc';
}

$filters = array();
foreach (array('q','type','category','collection','tag','status','source','on_demand','broadcast','automatic_rotation') as $filterKey) {
    $filters[$filterKey] = isset($_GET[$filterKey]) ? trim((string) $_GET[$filterKey]) : '';
}

$storage = RADIO_storageDir();
$ready = RADIO_ensureStorage();
$classificationOptions = RADIO_mediaClassificationOptions();
$media = RADIO_getMediaList(500, false, $sort, $direction, $filters);

$libraryInfo = RADIO_libraryInfo();
$storageDetails = '<section class="radio-admin__panel"><h2>'
    . htmlspecialchars($LANG_RADIO['storage'], ENT_QUOTES, 'UTF-8') . '</h2><p><strong>'
    . htmlspecialchars($ready ? $LANG_RADIO['storage_ready'] : $LANG_RADIO['storage_unavailable'], ENT_QUOTES, 'UTF-8')
    . '</strong><br><code>' . htmlspecialchars($storage, ENT_QUOTES, 'UTF-8') . '</code></p>'
    . '<p><strong>' . htmlspecialchars($LANG_RADIO['library_mode_label'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . htmlspecialchars(
        RADIO_sharedMediaEnabled()
            ? $LANG_RADIO['shared_media_mode']
            : $LANG_RADIO['local_media_mode'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';

if (RADIO_sharedMediaEnabled()) {
    $storageDetails .= '<form method="post" action="">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
        . htmlspecialchars(SEC_createToken(), ENT_QUOTES, 'UTF-8') . '">'
        . '<button class="radio-admin__button" type="submit" name="sync_shared_media" value="1">'
        . htmlspecialchars($LANG_RADIO['sync_shared_media'], ENT_QUOTES, 'UTF-8')
        . '</button></form>';
}

$content = $storageDetails . '</section>';

$content .= RADIO_adminQuickActions();
$content .= RADIO_adminRenderMediaFilters($filters, $classificationOptions, $sort, $direction);
$content .= RADIO_adminRenderMediaList($media, $sort, $direction, $filters);

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
