<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';

if (!SEC_hasRights('radio.admin')) {
    COM_accessLog('User tried to access Radio administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;
$storage = RADIO_storageDir();
$ready = RADIO_ensureStorage();
$message = '';

if (isset($_POST['upload_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['admin_title']);
    } elseif (!SEC_hasRights('radio.upload')) {
        $message = COM_showMessageText($LANG_RADIO['access_denied'], $LANG_RADIO['admin_title']);
    } else {
        $error = '';
        $id = RADIO_saveUpload(
            isset($_FILES['audio_file']) ? $_FILES['audio_file'] : array(),
            $_POST,
            $error
        );
        if ($id !== false) {
            $message = COM_showMessageText($LANG_RADIO['upload_saved'], $LANG_RADIO['admin_title']);
        } else {
            $key = isset($LANG_RADIO[$error]) ? $error : 'upload_failed';
            $message = COM_showMessageText($LANG_RADIO[$key], $LANG_RADIO['admin_title']);
        }
    }
}

if (isset($_POST['save_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['admin_title']);
    } else {
        $id = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
        $message = COM_showMessageText(
            RADIO_updateMedia($id, $_POST) ? $LANG_RADIO['media_saved'] : $LANG_RADIO['media_save_failed'],
            $LANG_RADIO['admin_title']
        );
    }
}

if (isset($_POST['delete_media'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['admin_title']);
    } else {
        $id = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
        $message = COM_showMessageText(
            RADIO_deleteMedia($id) ? $LANG_RADIO['media_deleted'] : $LANG_RADIO['media_delete_failed'],
            $LANG_RADIO['admin_title']
        );
    }
}

$media = RADIO_getMediaList(100, false);
$token = SEC_createToken();
$configUrl = $_CONF['site_admin_url'] . '/configuration.php?conf_group=radio';

$content = COM_startBlock($LANG_RADIO['admin_title'], '', COM_getBlockTemplate('_admin_block', 'header'));
$content .= $message;
$content .= '<p>' . htmlspecialchars($LANG_RADIO['admin_intro'], ENT_QUOTES, 'UTF-8') . '</p>';
$content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['storage'], ENT_QUOTES, 'UTF-8') . ':</strong> '
    . htmlspecialchars($ready ? $LANG_RADIO['storage_ready'] : $LANG_RADIO['storage_unavailable'], ENT_QUOTES, 'UTF-8')
    . '<br><code>' . htmlspecialchars($storage, ENT_QUOTES, 'UTF-8') . '</code></p>';
$content .= '<p><a href="' . htmlspecialchars($configUrl, ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['open_configuration'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/programs.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['manage_programs'], ENT_QUOTES, 'UTF-8') . '</a></p>';

if (SEC_hasRights('radio.upload')) {
    $content .= '<h2>' . htmlspecialchars($LANG_RADIO['upload_title'], ENT_QUOTES, 'UTF-8') . '</h2>';
    $content .= '<form method="post" enctype="multipart/form-data" action="">'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['audio_file'], ENT_QUOTES, 'UTF-8')
        . '<br><input type="file" name="audio_file" accept=".mp3,.m4a,.aac,.ogg,.wav,audio/*" required></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8')
        . '<br><input type="text" name="title" maxlength="255" style="width:100%"></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['description'], ENT_QUOTES, 'UTF-8')
        . '<br><textarea name="description" rows="4" style="width:100%"></textarea></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8')
        . '<br><select name="media_type">'
        . '<option value="music">' . htmlspecialchars($LANG_RADIO['type_music'], ENT_QUOTES, 'UTF-8') . '</option>'
        . '<option value="podcast">' . htmlspecialchars($LANG_RADIO['type_podcast'], ENT_QUOTES, 'UTF-8') . '</option>'
        . '<option value="interview">' . htmlspecialchars($LANG_RADIO['type_interview'], ENT_QUOTES, 'UTF-8') . '</option>'
        . '<option value="show">' . htmlspecialchars($LANG_RADIO['type_show'], ENT_QUOTES, 'UTF-8') . '</option>'
        . '<option value="chronicle">' . htmlspecialchars($LANG_RADIO['type_chronicle'], ENT_QUOTES, 'UTF-8') . '</option>'
        . '<option value="jingle">' . htmlspecialchars($LANG_RADIO['type_jingle'], ENT_QUOTES, 'UTF-8') . '</option>'
        . '<option value="announcement">' . htmlspecialchars($LANG_RADIO['type_announcement'], ENT_QUOTES, 'UTF-8') . '</option>'
        . '<option value="promo">' . htmlspecialchars($LANG_RADIO['type_promo'], ENT_QUOTES, 'UTF-8') . '</option>'
        . '</select></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['status'], ENT_QUOTES, 'UTF-8')
        . ' <select name="status"><option value="draft">' . htmlspecialchars($LANG_RADIO['draft'], ENT_QUOTES, 'UTF-8')
        . '</option><option value="published">' . htmlspecialchars($LANG_RADIO['published'], ENT_QUOTES, 'UTF-8')
        . '</option></select></label> '
        . '<label><input type="checkbox" name="allow_download" value="1" checked> '
        . htmlspecialchars($LANG_RADIO['allow_download'], ENT_QUOTES, 'UTF-8') . '</label></p>'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" name="upload_media" value="1">' . htmlspecialchars($LANG_RADIO['upload'], ENT_QUOTES, 'UTF-8') . '</button>'
        . '</form>';
}

$content .= '<h2>' . htmlspecialchars($LANG_RADIO['library'], ENT_QUOTES, 'UTF-8') . '</h2>';
if (count($media) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['library_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<style>.radio-admin-item{border:1px solid rgba(127,127,127,.3);padding:1rem;margin:1rem 0;border-radius:.4rem}.radio-admin-item input[type=text],.radio-admin-item textarea,.radio-admin-item select{max-width:100%;box-sizing:border-box}.radio-admin-player{width:100%;max-width:700px}</style>';
    foreach ($media as $row) {
        $id = (int) $row['media_id'];
        $content .= '<div class="radio-admin-item"><form method="post" action="">'
            . '<p><audio class="radio-admin-player" controls preload="metadata" src="'
            . htmlspecialchars(RADIO_mediaUrl($id, false), ENT_QUOTES, 'UTF-8') . '"></audio></p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8')
            . '<br><input type="text" name="title" maxlength="255" style="width:100%" value="'
            . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '"></label></p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['description'], ENT_QUOTES, 'UTF-8')
            . '<br><textarea name="description" rows="3" style="width:100%">'
            . htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8') . '</textarea></label></p>'
            . '<p>' . htmlspecialchars($LANG_RADIO['file'], ENT_QUOTES, 'UTF-8') . ': <code>'
            . htmlspecialchars($row['original_name'], ENT_QUOTES, 'UTF-8') . '</code> · '
            . htmlspecialchars($row['mime_type'], ENT_QUOTES, 'UTF-8') . ' · '
            . number_format(((int) $row['file_size']) / 1048576, 2) . ' MB</p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8') . ' <select name="media_type">';

        $types = array('music','podcast','interview','show','chronicle','jingle','announcement','promo');
        foreach ($types as $type) {
            $content .= '<option value="' . $type . '"' . ($row['media_type'] === $type ? ' selected' : '') . '>'
                . htmlspecialchars($LANG_RADIO['type_' . $type], ENT_QUOTES, 'UTF-8') . '</option>';
        }

        $content .= '</select></label> <label>' . htmlspecialchars($LANG_RADIO['status'], ENT_QUOTES, 'UTF-8')
            . ' <select name="status"><option value="draft"' . ($row['status'] === 'draft' ? ' selected' : '') . '>'
            . htmlspecialchars($LANG_RADIO['draft'], ENT_QUOTES, 'UTF-8') . '</option><option value="published"'
            . ($row['status'] === 'published' ? ' selected' : '') . '>'
            . htmlspecialchars($LANG_RADIO['published'], ENT_QUOTES, 'UTF-8') . '</option></select></label> '
            . '<label><input type="checkbox" name="allow_download" value="1"' . (!empty($row['allow_download']) ? ' checked' : '') . '> '
            . htmlspecialchars($LANG_RADIO['allow_download'], ENT_QUOTES, 'UTF-8') . '</label></p>'
            . '<input type="hidden" name="media_id" value="' . $id . '">'
            . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" name="save_media" value="1">' . htmlspecialchars($LANG_RADIO['save'], ENT_QUOTES, 'UTF-8') . '</button> '
            . '<button type="submit" name="delete_media" value="1" onclick="return confirm('
            . htmlspecialchars(json_encode($LANG_RADIO['confirm_delete']), ENT_QUOTES, 'UTF-8') . ');">'
            . htmlspecialchars($LANG_RADIO['delete'], ENT_QUOTES, 'UTF-8') . '</button>'
            . '</form></div>';
    }
}

$content .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));
COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['admin_title'])));
