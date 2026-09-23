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

function RADIO_adminUploadFiles()
{
    $files = array();
    if (!isset($_FILES['audio_files']) || !is_array($_FILES['audio_files'])) {
        return $files;
    }

    $names = isset($_FILES['audio_files']['name']) ? $_FILES['audio_files']['name'] : array();
    if (!is_array($names)) {
        return $files;
    }

    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        $files[] = array(
            'name' => isset($_FILES['audio_files']['name'][$i]) ? $_FILES['audio_files']['name'][$i] : '',
            'type' => isset($_FILES['audio_files']['type'][$i]) ? $_FILES['audio_files']['type'][$i] : '',
            'tmp_name' => isset($_FILES['audio_files']['tmp_name'][$i]) ? $_FILES['audio_files']['tmp_name'][$i] : '',
            'error' => isset($_FILES['audio_files']['error'][$i]) ? $_FILES['audio_files']['error'][$i] : UPLOAD_ERR_NO_FILE,
            'size' => isset($_FILES['audio_files']['size'][$i]) ? $_FILES['audio_files']['size'][$i] : 0
        );
    }

    return $files;
}

if (isset($_POST['upload_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['upload_title']);
    } else {
        $files = RADIO_adminUploadFiles();
        $successCount = 0;
        $failures = array();

        if (count($files) === 1) {
            $error = '';
            $coverError = '';
            $coverName = RADIO_saveCoverUpload(isset($_FILES['cover_file']) ? $_FILES['cover_file'] : array(), $coverError);
            if ($coverName === false) {
                $error = $coverError;
                $id = false;
            } else {
                $_POST['cover_name'] = $coverName;
                $id = RADIO_saveUpload($files[0], $_POST, $error);
                if ($id === false && $coverName !== '') {
                    RADIO_deleteCover($coverName);
                }
            }

            if ($id !== false) {
                $successCount = 1;
            } else {
                $key = isset($LANG_RADIO[$error]) ? $error : 'upload_failed';
                $failures[] = basename($files[0]['name']) . ': ' . $LANG_RADIO[$key];
            }
        } else {
            foreach ($files as $file) {
                $error = '';
                $metadata = $_POST;
                $metadata['title'] = '';
                $metadata['description'] = '';
                $metadata['season_number'] = 0;
                $metadata['episode_number'] = 0;
                $metadata['duration'] = 0;
                $metadata['cover_name'] = '';

                $id = RADIO_saveUpload($file, $metadata, $error);
                if ($id !== false) {
                    $successCount++;
                } else {
                    $key = isset($LANG_RADIO[$error]) ? $error : 'upload_failed';
                    $failures[] = basename($file['name']) . ': ' . $LANG_RADIO[$key];
                }
            }
        }

        if ($successCount > 0 && count($failures) === 0) {
            $message = COM_showMessageText(
                sprintf($LANG_RADIO['batch_upload_success'], $successCount),
                $LANG_RADIO['upload_title']
            );
        } elseif ($successCount > 0) {
            $message = COM_showMessageText(
                sprintf($LANG_RADIO['batch_upload_partial'], $successCount, count($failures))
                    . '<br><small>' . htmlspecialchars(implode(' · ', $failures), ENT_QUOTES, 'UTF-8') . '</small>',
                $LANG_RADIO['upload_title']
            );
        } elseif (count($failures) > 0) {
            $message = COM_showMessageText(
                $LANG_RADIO['batch_upload_failed']
                    . '<br><small>' . htmlspecialchars(implode(' · ', $failures), ENT_QUOTES, 'UTF-8') . '</small>',
                $LANG_RADIO['upload_title']
            );
        } else {
            $message = COM_showMessageText($LANG_RADIO['batch_upload_none'], $LANG_RADIO['upload_title']);
        }
    }
}

function RADIO_adminPhpUploadLimits()
{
    return array(
        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
        'post_max_size' => (string) ini_get('post_max_size'),
        'max_file_uploads' => (string) ini_get('max_file_uploads')
    );
}

$phpUploadLimits = RADIO_adminPhpUploadLimits();

$token = SEC_createToken();
$template = RADIO_adminTemplate('media-upload.thtml');
$template->set_var(array(
    'audio_file_label' => htmlspecialchars($LANG_RADIO['audio_file'], ENT_QUOTES, 'UTF-8'),
    'batch_drop_title' => htmlspecialchars($LANG_RADIO['batch_drop_title'], ENT_QUOTES, 'UTF-8'),
    'batch_drop_text' => htmlspecialchars($LANG_RADIO['batch_drop_text'], ENT_QUOTES, 'UTF-8'),
    'batch_drop_label' => htmlspecialchars($LANG_RADIO['batch_drop_label'], ENT_QUOTES, 'UTF-8'),
    'batch_remove_label' => htmlspecialchars($LANG_RADIO['batch_remove'], ENT_QUOTES, 'UTF-8'),
    'batch_server_limit' => htmlspecialchars(
        sprintf(
            $LANG_RADIO['batch_server_limit'],
            $phpUploadLimits['upload_max_filesize'],
            $phpUploadLimits['post_max_size'],
            $phpUploadLimits['max_file_uploads']
        ),
        ENT_QUOTES,
        'UTF-8'
    ),
    'batch_metadata_note' => htmlspecialchars($LANG_RADIO['batch_metadata_note'], ENT_QUOTES, 'UTF-8'),
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
    'on_demand_label' => htmlspecialchars($LANG_RADIO['on_demand'], ENT_QUOTES, 'UTF-8'),
    'broadcast_label' => htmlspecialchars($LANG_RADIO['broadcast'], ENT_QUOTES, 'UTF-8'),
    'automatic_rotation_label' => htmlspecialchars($LANG_RADIO['automatic_rotation'], ENT_QUOTES, 'UTF-8'),
    'automatic_rotation_help' => htmlspecialchars($LANG_RADIO['automatic_rotation_help'], ENT_QUOTES, 'UTF-8'),
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
