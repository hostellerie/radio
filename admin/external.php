<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.upload')) {
    COM_accessLog('User tried to access Radio external media administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;
$message = '';

if (isset($_POST['save_external_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['external_source_title']);
    } else {
        $error = '';
        $coverError = '';
        $coverName = RADIO_saveCoverUpload(isset($_FILES['cover_file']) ? $_FILES['cover_file'] : array(), $coverError);
        if ($coverName === false) {
            $error = $coverError;
            $id = false;
        } else {
            $_POST['cover_name'] = $coverName;
            $id = RADIO_saveExternalMedia($_POST, $error);
            if ($id === false && $coverName !== '') {
                RADIO_deleteCover($coverName);
            }
        }
        $key = $id !== false ? 'external_saved' : (isset($LANG_RADIO[$error]) ? $error : 'external_save_failed');
        $message = COM_showMessageText($LANG_RADIO[$key], $LANG_RADIO['external_source_title']);
    }
}

$token = SEC_createToken();
$template = RADIO_adminTemplate('media-external.thtml');
$template->set_var(array(
    'source_kind_label' => htmlspecialchars($LANG_RADIO['source_kind'], ENT_QUOTES, 'UTF-8'),
    'source_kind_options' => RADIO_adminSelectOptions(array(
        'external' => $LANG_RADIO['source_external'],
        'live' => $LANG_RADIO['source_live']
    ), 'external'),
    'source_url_label' => htmlspecialchars($LANG_RADIO['source_url'], ENT_QUOTES, 'UTF-8'),
    'source_url_help' => htmlspecialchars($LANG_RADIO['source_url_help'], ENT_QUOTES, 'UTF-8'),
    'title_label' => htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8'),
    'source_provider_label' => htmlspecialchars($LANG_RADIO['source_provider'], ENT_QUOTES, 'UTF-8'),
    'source_external_id_label' => htmlspecialchars($LANG_RADIO['source_external_id'], ENT_QUOTES, 'UTF-8'),
    'source_attribution_label' => htmlspecialchars($LANG_RADIO['source_attribution'], ENT_QUOTES, 'UTF-8'),
    'source_license_label' => htmlspecialchars($LANG_RADIO['source_license'], ENT_QUOTES, 'UTF-8'),
    'description_label' => htmlspecialchars($LANG_RADIO['description'], ENT_QUOTES, 'UTF-8'),
    'type_label' => htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8'),
    'duration_label' => htmlspecialchars($LANG_RADIO['duration_seconds'], ENT_QUOTES, 'UTF-8'),
    'cover_label' => htmlspecialchars($LANG_RADIO['cover'], ENT_QUOTES, 'UTF-8'),
    'status_label' => htmlspecialchars($LANG_RADIO['status'], ENT_QUOTES, 'UTF-8'),
    'on_demand_label' => htmlspecialchars($LANG_RADIO['on_demand'], ENT_QUOTES, 'UTF-8'),
    'broadcast_label' => htmlspecialchars($LANG_RADIO['broadcast'], ENT_QUOTES, 'UTF-8'),
    'automatic_rotation_label' => htmlspecialchars($LANG_RADIO['automatic_rotation'], ENT_QUOTES, 'UTF-8'),
    'automatic_rotation_help' => htmlspecialchars($LANG_RADIO['automatic_rotation_help'], ENT_QUOTES, 'UTF-8'),
    'permissions_label' => htmlspecialchars($LANG_RADIO['permissions'], ENT_QUOTES, 'UTF-8'),
    'group_label' => htmlspecialchars($LANG_RADIO['group'], ENT_QUOTES, 'UTF-8'),
    'group_dropdown' => SEC_getGroupDropdown(RADIO_defaultGroupId(), 3),
    'permissions_html' => SEC_getPermissionsHTML(3, 2, 2, 2),
    'media_type_options' => RADIO_adminMediaTypeOptions('music', false),
    'status_options' => RADIO_adminStatusOptions('draft'),
    'csrf_name' => CSRF_TOKEN,
    'csrf_token' => htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
    'submit_label' => htmlspecialchars($LANG_RADIO['save_external'], ENT_QUOTES, 'UTF-8'),
    'cancel_url' => htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/index.php', ENT_QUOTES, 'UTF-8'),
    'cancel_label' => htmlspecialchars($LANG_RADIO['admin_cancel'], ENT_QUOTES, 'UTF-8')
));
$content = $template->finish($template->parse('output', 'page'));

$content = RADIO_adminRenderPage(
    'library',
    $LANG_RADIO['external_source_title'],
    $LANG_RADIO['admin_external_intro'],
    $LANG_RADIO['admin_external_help_title'],
    $LANG_RADIO['admin_external_help_text'],
    $content,
    $message
);

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['external_source_title'],
    'headercode' => RADIO_adminHeaderCode()
)));
