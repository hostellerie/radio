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
        $coverError = '';
        $coverName = RADIO_saveCoverUpload(isset($_FILES['cover_file']) ? $_FILES['cover_file'] : array(), $coverError);
        if ($coverName === false) {
            $error = $coverError;
            $id = false;
        } else {
            $_POST['cover_name'] = $coverName;
            $id = RADIO_saveUpload(isset($_FILES['audio_file']) ? $_FILES['audio_file'] : array(), $_POST, $error);
            if ($id === false && $coverName !== '') RADIO_deleteCover($coverName);
        }
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
        $existing = RADIO_getMedia($id, false);
        $coverError = '';
        $coverName = RADIO_saveCoverUpload(isset($_FILES['cover_file']) ? $_FILES['cover_file'] : array(), $coverError);
        if ($coverName !== false) {
            $_POST['cover_name'] = $coverName !== '' ? $coverName : ($existing ? $existing['cover_name'] : '');
        }
        $savedMedia = $coverName !== false && RADIO_updateMedia($id, $_POST);
        if ($savedMedia && $coverName !== '' && $existing && !empty($existing['cover_name'])) {
            RADIO_deleteCover($existing['cover_name']);
        }
        if (!$savedMedia && $coverName !== '') {
            RADIO_deleteCover($coverName);
        }
        $message = COM_showMessageText(
            $savedMedia ? $LANG_RADIO['media_saved'] : $LANG_RADIO['media_save_failed'],
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
    . htmlspecialchars($LANG_RADIO['manage_programs'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/schedule.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['manage_schedule'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/rotation.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['automatic_rotation'], ENT_QUOTES, 'UTF-8') . '</a></p>';

if (SEC_hasRights('radio.upload')) {
    $content .= '<h2>' . htmlspecialchars($LANG_RADIO['upload_title'], ENT_QUOTES, 'UTF-8') . '</h2>';
    $content .= '<form method="post" enctype="multipart/form-data" action="">'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['audio_file'], ENT_QUOTES, 'UTF-8')
        . '<br><input type="file" id="radio-upload-file" name="audio_file" accept=".mp3,.m4a,.aac,.ogg,.wav,audio/*" required></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8')
        . '<br><input type="text" name="title" maxlength="255" style="width:100%"></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['author'], ENT_QUOTES, 'UTF-8') . '<br><input type="text" name="author" maxlength="255" style="width:100%"></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['series_title'], ENT_QUOTES, 'UTF-8') . '<br><input type="text" name="series_title" maxlength="255" style="width:100%"></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['season_number'], ENT_QUOTES, 'UTF-8') . ' <input type="number" name="season_number" min="0" value="0" style="width:6rem"></label> '
        . '<label>' . htmlspecialchars($LANG_RADIO['episode_number'], ENT_QUOTES, 'UTF-8') . ' <input type="number" name="episode_number" min="0" value="0" style="width:6rem"></label></p>'
        . '<p><label>' . htmlspecialchars($LANG_RADIO['cover'], ENT_QUOTES, 'UTF-8') . '<br><input type="file" name="cover_file" accept=".jpg,.jpeg,.png,.webp,image/*"></label></p>'
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
        . '<p><label>' . htmlspecialchars($LANG_RADIO['duration_seconds'], ENT_QUOTES, 'UTF-8')
        . ' <input type="number" id="radio-upload-duration" name="duration" min="0" step="1" value="0" style="width:8rem"></label></p>'
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
        $content .= '<div class="radio-admin-item"><form method="post" enctype="multipart/form-data" action="">'
            . '<p><audio class="radio-admin-player radio-duration-source" data-duration-target="radio-duration-' . $id . '" controls preload="metadata" src="'
            . htmlspecialchars(RADIO_mediaUrl($id, false), ENT_QUOTES, 'UTF-8') . '"></audio></p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8')
            . '<br><input type="text" name="title" maxlength="255" style="width:100%" value="'
            . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '"></label></p>'
            . (!empty($row['cover_name']) ? '<p><img src="' . htmlspecialchars(RADIO_coverUrl('media',$id), ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:180px;max-height:180px"></p>' : '')
            . '<p><label>' . htmlspecialchars($LANG_RADIO['author'], ENT_QUOTES, 'UTF-8') . '<br><input type="text" name="author" maxlength="255" style="width:100%" value="' . htmlspecialchars($row['author'], ENT_QUOTES, 'UTF-8') . '"></label></p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['series_title'], ENT_QUOTES, 'UTF-8') . '<br><input type="text" name="series_title" maxlength="255" style="width:100%" value="' . htmlspecialchars($row['series_title'], ENT_QUOTES, 'UTF-8') . '"></label></p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['season_number'], ENT_QUOTES, 'UTF-8') . ' <input type="number" name="season_number" min="0" value="' . (int)$row['season_number'] . '" style="width:6rem"></label> '
            . '<label>' . htmlspecialchars($LANG_RADIO['episode_number'], ENT_QUOTES, 'UTF-8') . ' <input type="number" name="episode_number" min="0" value="' . (int)$row['episode_number'] . '" style="width:6rem"></label></p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['replace_cover'], ENT_QUOTES, 'UTF-8') . '<br><input type="file" name="cover_file" accept=".jpg,.jpeg,.png,.webp,image/*"></label></p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['description'], ENT_QUOTES, 'UTF-8')
            . '<br><textarea name="description" rows="3" style="width:100%">'
            . htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8') . '</textarea></label></p>'
            . '<p>' . htmlspecialchars($LANG_RADIO['file'], ENT_QUOTES, 'UTF-8') . ': <code>'
            . htmlspecialchars($row['original_name'], ENT_QUOTES, 'UTF-8') . '</code> · '
            . htmlspecialchars($row['mime_type'], ENT_QUOTES, 'UTF-8') . ' · '
            . number_format(((int) $row['file_size']) / 1048576, 2) . ' MB</p>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['duration_seconds'], ENT_QUOTES, 'UTF-8')
            . ' <input type="number" id="radio-duration-' . $id . '" name="duration" min="0" step="1" value="' . (int) $row['duration'] . '" style="width:8rem"></label></p>'
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
            . '<fieldset><legend>' . htmlspecialchars($LANG_RADIO['permissions'], ENT_QUOTES, 'UTF-8') . '</legend>'
            . '<p><label>' . htmlspecialchars($LANG_RADIO['group'], ENT_QUOTES, 'UTF-8') . ' ' . SEC_getGroupDropdown((int)$row['group_id'], 3) . '</label></p>'
            . SEC_getPermissionsHTML((int)$row['perm_owner'], (int)$row['perm_group'], (int)$row['perm_members'], (int)$row['perm_anon'])
            . '</fieldset>'
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
$durationJs = '<script>(function(){'
    . 'var file=document.getElementById("radio-upload-file");var target=document.getElementById("radio-upload-duration");'
    . 'if(file&&target){file.addEventListener("change",function(){if(!file.files||!file.files[0])return;'
    . 'var a=document.createElement("audio");var u=URL.createObjectURL(file.files[0]);a.preload="metadata";a.src=u;'
    . 'a.addEventListener("loadedmetadata",function(){if(isFinite(a.duration)&&a.duration>0){target.value=Math.round(a.duration);}URL.revokeObjectURL(u);});});}'
    . 'var sources=document.querySelectorAll(".radio-duration-source");'
    . 'for(var i=0;i<sources.length;i++){(function(a){a.addEventListener("loadedmetadata",function(){'
    . 'var id=a.getAttribute("data-duration-target");var input=document.getElementById(id);'
    . 'if(input&&parseInt(input.value,10)<=0&&isFinite(a.duration)&&a.duration>0){input.value=Math.round(a.duration);}'
    . '});})(sources[i]);}'
    . '})();</script>';
COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['admin_title'],
    'footercode' => $durationJs
)));
