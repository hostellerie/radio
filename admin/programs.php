<?php
require_once dirname(__FILE__) . '/../../../lib-common.php';
require_once dirname(__FILE__) . '/../../auth.inc.php';
require_once __DIR__ . '/admin-ui.inc.php';

if (!SEC_hasRights('radio.schedule')) {
    COM_accessLog('User tried to access Radio programme administration without permission.');
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

global $LANG_RADIO, $_CONF;
$message = '';
$selectedId = isset($_REQUEST['program_id']) ? (int) $_REQUEST['program_id'] : 0;

if (isset($_POST['save_program'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['admin_title']);
    } else {
        $existingProgram = $selectedId > 0 ? RADIO_getProgram($selectedId, false) : false;
        $coverError = '';
        $coverName = RADIO_saveCoverUpload(isset($_FILES['cover_file']) ? $_FILES['cover_file'] : array(), $coverError);
        if ($coverName !== false) {
            $_POST['cover_name'] = $coverName !== '' ? $coverName : ($existingProgram ? $existingProgram['cover_name'] : '');
            $saved = RADIO_saveProgram($selectedId, $_POST);
            if ($saved !== false && $coverName !== '' && $existingProgram && !empty($existingProgram['cover_name'])) {
                RADIO_deleteCover($existingProgram['cover_name']);
            }
            if ($saved === false && $coverName !== '') {
                RADIO_deleteCover($coverName);
            }
        } else {
            $saved = false;
        }
        if ($saved !== false) {
            $selectedId = (int) $saved;
            $message = COM_showMessageText($LANG_RADIO['program_saved'], $LANG_RADIO['programs']);
        } else {
            $message = COM_showMessageText($LANG_RADIO['program_save_failed'], $LANG_RADIO['programs']);
        }
    }
}

if (isset($_POST['delete_program'])) {
    if (!SEC_checkToken()) {
        $message = COM_showMessageText($LANG_RADIO['invalid_token'], $LANG_RADIO['admin_title']);
    } else {
        $ok = RADIO_deleteProgram($selectedId);
        $selectedId = 0;
        $message = COM_showMessageText($ok ? $LANG_RADIO['program_deleted'] : $LANG_RADIO['program_delete_failed'], $LANG_RADIO['programs']);
    }
}

$programs = array_values(array_filter(RADIO_getPrograms(100, false), function ($row) {
    return RADIO_hasReadAccess($row) || RADIO_hasEditAccess($row);
}));
$selected = $selectedId > 0 ? RADIO_getProgram($selectedId, false) : false;
if ($selected !== false && !RADIO_hasReadAccess($selected) && !RADIO_hasEditAccess($selected)) {
    $selected = false;
    $selectedId = 0;
}
$token = SEC_createToken();

$content = '';

$content .= '<div class="radio-programs-layout">';
$content .= '<aside class="radio-programs-list"><h2>' . htmlspecialchars($LANG_RADIO['program_list'], ENT_QUOTES, 'UTF-8') . '</h2>';
$content .= '<p><a href="?program_id=0">' . htmlspecialchars($LANG_RADIO['new_program'], ENT_QUOTES, 'UTF-8') . '</a></p>';
if (count($programs) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['program_list_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ul>';
    foreach ($programs as $program) {
        $content .= '<li><a href="?program_id=' . (int) $program['program_id'] . '">'
            . htmlspecialchars($program['title'], ENT_QUOTES, 'UTF-8') . '</a> <small>('
            . htmlspecialchars(RADIO_adminStatusLabel($program['status']), ENT_QUOTES, 'UTF-8') . ')</small></li>';
    }
    $content .= '</ul>';
}
$content .= '</aside>';

$content .= '<section class="radio-programs-editor"><h2>' . htmlspecialchars($selected ? $LANG_RADIO['edit_program'] : $LANG_RADIO['new_program'], ENT_QUOTES, 'UTF-8') . '</h2>'
    ;

if ($selected) {
    $content .= '<div class="radio-admin__toolbar">'
        . '<a class="radio-admin__button radio-admin__button--secondary" href="'
        . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/studio.php?program_id=' . $selectedId, ENT_QUOTES, 'UTF-8')
        . '">▶ ' . htmlspecialchars($LANG_RADIO['open_studio'], ENT_QUOTES, 'UTF-8') . '</a>'
        . '</div>';
}

$content .= '<form method="post" enctype="multipart/form-data" action="" class="radio-programs-form">'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8')
    . '<br><input type="text" name="program_title" maxlength="255" required value="'
    . htmlspecialchars($selected ? $selected['title'] : '', ENT_QUOTES, 'UTF-8') . '"></label></p>'
    . ($selected && !empty($selected['cover_name']) ? '<p><img class="radio-programs-cover" src="' . htmlspecialchars(RADIO_coverUrl('program',$selectedId), ENT_QUOTES, 'UTF-8') . '" alt=""></p>' : '')
    . '<p><label>' . htmlspecialchars($LANG_RADIO['host'], ENT_QUOTES, 'UTF-8') . '<br><input type="text" name="program_host" maxlength="255" value="' . htmlspecialchars($selected ? $selected['host'] : '', ENT_QUOTES, 'UTF-8') . '"></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['cover'], ENT_QUOTES, 'UTF-8') . '<br><input type="file" name="cover_file" accept=".jpg,.jpeg,.png,.webp,image/*"></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['description'], ENT_QUOTES, 'UTF-8')
    . '<br><textarea name="program_description" rows="5">'
    . htmlspecialchars($selected ? $selected['description'] : '', ENT_QUOTES, 'UTF-8') . '</textarea></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['status'], ENT_QUOTES, 'UTF-8')
    . ' <select name="program_status"><option value="draft"' . (!$selected || $selected['status'] === 'draft' ? ' selected' : '') . '>'
    . htmlspecialchars($LANG_RADIO['draft'], ENT_QUOTES, 'UTF-8') . '</option><option value="published"'
    . ($selected && $selected['status'] === 'published' ? ' selected' : '') . '>'
    . htmlspecialchars($LANG_RADIO['published'], ENT_QUOTES, 'UTF-8') . '</option></select></label></p>'
    . '<fieldset><legend>' . htmlspecialchars($LANG_RADIO['permissions'], ENT_QUOTES, 'UTF-8') . '</legend>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['group'], ENT_QUOTES, 'UTF-8') . ' ' . SEC_getGroupDropdown($selected ? (int)$selected['group_id'] : RADIO_defaultGroupId(), 3) . '</label></p>'
    . SEC_getPermissionsHTML($selected ? (int)$selected['perm_owner'] : 3, $selected ? (int)$selected['perm_group'] : 2, $selected ? (int)$selected['perm_members'] : 2, $selected ? (int)$selected['perm_anon'] : 2)
    . '</fieldset>'
    . '<input type="hidden" name="program_id" value="' . (int) $selectedId . '">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<button type="submit" name="save_program" value="1">' . htmlspecialchars($LANG_RADIO['save'], ENT_QUOTES, 'UTF-8') . '</button>';

if ($selected) {
    $content .= ' <button type="submit" name="delete_program" value="1" onclick="return confirm('
        . htmlspecialchars(json_encode($LANG_RADIO['confirm_program_delete']), ENT_QUOTES, 'UTF-8') . ');">'
        . htmlspecialchars($LANG_RADIO['delete'], ENT_QUOTES, 'UTF-8') . '</button>';
}
$content .= '</form>';

$content .= '</section></div>';
$content = RADIO_adminRenderPage(
    'programs',
    $LANG_RADIO['programs'],
    $LANG_RADIO['admin_programs_metadata_intro'],
    $LANG_RADIO['admin_programs_help_title'],
    $LANG_RADIO['admin_programs_help_text'],
    $content,
    $message
);
COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['programs'],
    'headercode' => RADIO_adminHeaderCode()
)));
