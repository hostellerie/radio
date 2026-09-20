<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.upload')) {
    COM_accessLog('User tried to access Radio upload administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;
$message = '';

if (isset($_POST['upload_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['upload_title']);
    } else {
        $error = '';
        $coverError = '';
        $coverName = RADIO_saveCoverUpload(isset($_FILES['cover_file']) ? $_FILES['cover_file'] : array(), $coverError);
        if ($coverName === false) {
            $error = $coverError;
            $id = false;
        } else {
            $_POST['cover_name'] = $coverName;
            $id = RADIO_saveUpload(isset($_FILES['audio_file']) ? $_FILES['audio_file'] : array(), $_POST, $error);
            if ($id === false && $coverName !== '') {
                RADIO_deleteCover($coverName);
            }
        }

        if ($id !== false) {
            $message = COM_showMessageText($LANG_RADIO['upload_saved'], $LANG_RADIO['upload_title']);
        } else {
            $key = isset($LANG_RADIO[$error]) ? $error : 'upload_failed';
            $message = COM_showMessageText($LANG_RADIO[$key], $LANG_RADIO['upload_title']);
        }
    }
}

$token = SEC_createToken();
$template = RADIO_adminTemplate('media-upload.thtml');
$template->set_var(array(
    'audio_file_label' => htmlspecialchars($LANG_RADIO['audio_file'], ENT_QUOTES, 'UTF-8'),
    'title_label' => htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8'),
    'author_label' => htmlspecialchars($LANG_RADIO['author'], ENT_QUOTES, 'UTF-8'),
    'series_title_label' => htmlspecialchars($LANG_RADIO['series_title'], ENT_QUOTES, 'UTF-8'),
    'season_number_label' => htmlspecialchars($LANG_RADIO['season_number'], ENT_QUOTES, 'UTF-8'),
    'episode_number_label' => htmlspecialchars($LANG_RADIO['episode_number'], ENT_QUOTES, 'UTF-8'),
    'cover_label' => htmlspecialchars($LANG_RADIO['cover'], ENT_QUOTES, 'UTF-8'),
    'description_label' => htmlspecialchars($LANG_RADIO['description'], ENT_QUOTES, 'UTF-8'),
    'type_label' => htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8'),
    'duration_label' => htmlspecialchars($LANG_RADIO['duration_seconds'], ENT_QUOTES, 'UTF-8'),
    'status_label' => htmlspecialchars($LANG_RADIO['status'], ENT_QUOTES, 'UTF-8'),
    'allow_download_label' => htmlspecialchars($LANG_RADIO['allow_download'], ENT_QUOTES, 'UTF-8'),
    'permissions_label' => htmlspecialchars($LANG_RADIO['permissions'], ENT_QUOTES, 'UTF-8'),
    'group_label' => htmlspecialchars($LANG_RADIO['group'], ENT_QUOTES, 'UTF-8'),
    'group_dropdown' => SEC_getGroupDropdown(RADIO_defaultGroupId(), 3),
    'permissions_html' => SEC_getPermissionsHTML(3, 2, 2, 2),
    'media_type_options' => RADIO_adminMediaTypeOptions('music', true),
    'status_options' => RADIO_adminStatusOptions('draft'),
    'csrf_name' => CSRF_TOKEN,
    'csrf_token' => htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
    'submit_label' => htmlspecialchars($LANG_RADIO['upload'], ENT_QUOTES, 'UTF-8'),
    'cancel_url' => htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/index.php', ENT_QUOTES, 'UTF-8'),
    'cancel_label' => htmlspecialchars($LANG_RADIO['admin_cancel'], ENT_QUOTES, 'UTF-8')
));
$content = $template->finish($template->parse('output', 'page'));

$content = RADIO_adminRenderPage(
    'library',
    $LANG_RADIO['upload_title'],
    $LANG_RADIO['admin_upload_intro'],
    $LANG_RADIO['admin_upload_help_title'],
    $LANG_RADIO['admin_upload_help_text'],
    $content,
    $message
);

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['upload_title'],
    'headercode' => RADIO_adminHeaderCode()
)));
