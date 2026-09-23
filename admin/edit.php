<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio media editing without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;

$id = isset($_REQUEST['media_id']) ? (int) $_REQUEST['media_id'] : 0;
$row = RADIO_getMedia($id, false);

if ($row === false || !RADIO_hasEditAccess($row)) {
    $content = COM_showMessageText($LANG_RADIO['media_not_found'], $LANG_RADIO['admin_title']);
    COM_output(COM_createHTMLDocument($content, array(
        'pagetitle' => $LANG_RADIO['admin_title'],
        'headercode' => RADIO_adminHeaderCode()
    )));
    exit;
}

$message = '';

if (isset($_POST['save_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['admin_title']);
    } else {
        $coverError = '';
        $coverName = RADIO_saveCoverUpload(
            isset($_FILES['cover_file']) ? $_FILES['cover_file'] : array(),
            $coverError
        );

        if ($coverName !== false) {
            $_POST['cover_name'] = $coverName !== '' ? $coverName : $row['cover_name'];
        }

        $saved = $coverName !== false && RADIO_updateMedia($id, $_POST);

        if ($saved && $coverName !== '' && !empty($row['cover_name'])) {
            RADIO_deleteCover($row['cover_name']);
        }
        if (!$saved && $coverName !== '') {
            RADIO_deleteCover($coverName);
        }

        if ($saved) {
            COM_redirect(
                $_CONF['site_admin_url'] . '/plugins/radio/index.php?updated=1'
            );
        }

        $message = COM_showMessageText(
            $LANG_RADIO['media_save_failed'],
            $LANG_RADIO['admin_title']
        );
    }
}

if (isset($_POST['delete_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['admin_title']);
    } elseif (RADIO_deleteMedia($id)) {
        COM_redirect($_CONF['site_admin_url'] . '/plugins/radio/index.php');
    } else {
        $message = COM_showMessageText($LANG_RADIO['media_delete_failed'], $LANG_RADIO['admin_title']);
    }
}

$token = SEC_createToken();

$sourceDetails = '';
if (RADIO_sourceKind($row) !== 'local') {
    $sourceDetails = '<section class="radio-admin__panel"><p><strong>'
        . htmlspecialchars($LANG_RADIO['source_kind'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . htmlspecialchars(RADIO_adminSourceKindLabel($row['source_kind']), ENT_QUOTES, 'UTF-8')
        . '<br><strong>' . htmlspecialchars($LANG_RADIO['source_url'], ENT_QUOTES, 'UTF-8') . ':</strong> <code>'
        . htmlspecialchars($row['source_url'], ENT_QUOTES, 'UTF-8') . '</code></p></section>';
}

$coverPreview = '';
if (!empty($row['cover_name'])) {
    $coverPreview = '<img class="radio-admin__cover" src="'
        . htmlspecialchars(RADIO_coverUrl('media', $id), ENT_QUOTES, 'UTF-8')
        . '" alt="">';
}

$template = RADIO_adminTemplate('media-edit.thtml');
$template->set_var(array(
    'media_id' => $id,
    'media_url' => htmlspecialchars(RADIO_mediaUrl($id, false), ENT_QUOTES, 'UTF-8'),
    'cover_preview' => $coverPreview,
    'title_label' => htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8'),
    'title' => htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'),
    'author_label' => htmlspecialchars($LANG_RADIO['author'], ENT_QUOTES, 'UTF-8'),
    'author' => htmlspecialchars($row['author'], ENT_QUOTES, 'UTF-8'),
    'series_title_label' => htmlspecialchars($LANG_RADIO['series_title'], ENT_QUOTES, 'UTF-8'),
    'series_title' => htmlspecialchars($row['series_title'], ENT_QUOTES, 'UTF-8'),
    'category_label' => htmlspecialchars($LANG_RADIO['category'], ENT_QUOTES, 'UTF-8'),
    'category' => htmlspecialchars(isset($row['category']) ? $row['category'] : '', ENT_QUOTES, 'UTF-8'),
    'collection_label' => htmlspecialchars($LANG_RADIO['collection'], ENT_QUOTES, 'UTF-8'),
    'collection_name' => htmlspecialchars(isset($row['collection_name']) ? $row['collection_name'] : '', ENT_QUOTES, 'UTF-8'),
    'tags_label' => htmlspecialchars($LANG_RADIO['tags'], ENT_QUOTES, 'UTF-8'),
    'tags' => htmlspecialchars(isset($row['tags']) ? str_replace(',', ', ', $row['tags']) : '', ENT_QUOTES, 'UTF-8'),
    'tags_help' => htmlspecialchars($LANG_RADIO['tags_help'], ENT_QUOTES, 'UTF-8'),
    'season_number_label' => htmlspecialchars($LANG_RADIO['season_number'], ENT_QUOTES, 'UTF-8'),
    'season_number' => (int) $row['season_number'],
    'episode_number_label' => htmlspecialchars($LANG_RADIO['episode_number'], ENT_QUOTES, 'UTF-8'),
    'episode_number' => (int) $row['episode_number'],
    'replace_cover_label' => htmlspecialchars($LANG_RADIO['replace_cover'], ENT_QUOTES, 'UTF-8'),
    'description_label' => htmlspecialchars($LANG_RADIO['description'], ENT_QUOTES, 'UTF-8'),
    'description' => htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8'),
    'source_details' => $sourceDetails,
    'file_label' => htmlspecialchars($LANG_RADIO['file'], ENT_QUOTES, 'UTF-8'),
    'original_name' => htmlspecialchars($row['original_name'], ENT_QUOTES, 'UTF-8'),
    'mime_type' => htmlspecialchars(RADIO_playbackMime($row), ENT_QUOTES, 'UTF-8'),
    'file_size' => htmlspecialchars(RADIO_adminFormatSize($row['file_size']), ENT_QUOTES, 'UTF-8'),
    'duration_label' => htmlspecialchars($LANG_RADIO['duration_seconds'], ENT_QUOTES, 'UTF-8'),
    'duration_value' => (int) $row['duration'],
    'type_label' => htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8'),
    'media_type_options' => RADIO_adminMediaTypeOptions($row['media_type'], true),
    'status_label' => htmlspecialchars($LANG_RADIO['status'], ENT_QUOTES, 'UTF-8'),
    'status_options' => RADIO_adminStatusOptions($row['status']),
    'on_demand_checked' => !empty($row['on_demand']) ? ' checked' : '',
    'on_demand_label' => htmlspecialchars($LANG_RADIO['on_demand'], ENT_QUOTES, 'UTF-8'),
    'broadcast_checked' => !empty($row['broadcast']) ? ' checked' : '',
    'broadcast_label' => htmlspecialchars($LANG_RADIO['broadcast'], ENT_QUOTES, 'UTF-8'),
    'automatic_rotation_checked' => !array_key_exists('automatic_rotation', $row) || !empty($row['automatic_rotation']) ? ' checked' : '',
    'automatic_rotation_label' => htmlspecialchars($LANG_RADIO['automatic_rotation'], ENT_QUOTES, 'UTF-8'),
    'automatic_rotation_help' => htmlspecialchars($LANG_RADIO['automatic_rotation_help'], ENT_QUOTES, 'UTF-8'),
    'allow_download_checked' => !empty($row['allow_download']) ? ' checked' : '',
    'allow_download_label' => htmlspecialchars($LANG_RADIO['allow_download'], ENT_QUOTES, 'UTF-8'),
    'permissions_label' => htmlspecialchars($LANG_RADIO['permissions'], ENT_QUOTES, 'UTF-8'),
    'group_label' => htmlspecialchars($LANG_RADIO['group'], ENT_QUOTES, 'UTF-8'),
    'group_dropdown' => SEC_getGroupDropdown((int) $row['group_id'], 3),
    'permissions_html' => SEC_getPermissionsHTML(
        (int) $row['perm_owner'],
        (int) $row['perm_group'],
        (int) $row['perm_members'],
        (int) $row['perm_anon']
    ),
    'csrf_name' => CSRF_TOKEN,
    'csrf_token' => htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
    'save_label' => htmlspecialchars($LANG_RADIO['save'], ENT_QUOTES, 'UTF-8'),
    'back_url' => htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/index.php', ENT_QUOTES, 'UTF-8'),
    'back_label' => htmlspecialchars($LANG_RADIO['back_to_library_admin'], ENT_QUOTES, 'UTF-8'),
    'delete_label' => htmlspecialchars($LANG_RADIO['delete'], ENT_QUOTES, 'UTF-8'),
    'confirm_delete' => htmlspecialchars(json_encode($LANG_RADIO['confirm_delete']), ENT_QUOTES, 'UTF-8')
));
$content = $template->finish($template->parse('output', 'page'));

$content = RADIO_adminRenderPage(
    'library',
    $LANG_RADIO['admin_edit_media'],
    $LANG_RADIO['admin_edit_media_intro'],
    $LANG_RADIO['admin_edit_media_help_title'],
    $LANG_RADIO['admin_edit_media_help_text'],
    $content,
    $message
);

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['admin_edit_media'],
    'headercode' => RADIO_adminHeaderCode()
)));
