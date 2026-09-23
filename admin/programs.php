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

if (isset($_POST['add_program_item']) && SEC_checkToken()) {
    RADIO_addProgramItem($selectedId, isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0);
}
if (isset($_POST['remove_program_item']) && SEC_checkToken()) {
    RADIO_removeProgramItem(isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0, $selectedId);
}
if (isset($_POST['move_up']) && SEC_checkToken()) {
    RADIO_moveProgramItem(isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0, $selectedId, 'up');
}
if (isset($_POST['move_down']) && SEC_checkToken()) {
    RADIO_moveProgramItem(isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0, $selectedId, 'down');
}

$programs = array_values(array_filter(RADIO_getPrograms(100, false), function ($row) {
    return RADIO_hasReadAccess($row) || RADIO_hasEditAccess($row);
}));
$selected = $selectedId > 0 ? RADIO_getProgram($selectedId, false) : false;
if ($selected !== false && !RADIO_hasReadAccess($selected) && !RADIO_hasEditAccess($selected)) {
    $selected = false;
    $selectedId = 0;
}
$items = $selected ? RADIO_getProgramItems($selectedId) : array();

$pickerFilters = array();
foreach (array('picker_q','picker_type','picker_category','picker_collection','picker_tag') as $key) {
    $pickerFilters[$key] = isset($_GET[$key]) ? trim((string) $_GET[$key]) : '';
}

$pickerQuery = array(
    'q' => $pickerFilters['picker_q'],
    'type' => $pickerFilters['picker_type'],
    'category' => $pickerFilters['picker_category'],
    'collection' => $pickerFilters['picker_collection'],
    'tag' => $pickerFilters['picker_tag'],
    'status' => 'published',
    'broadcast' => '1'
);

$classificationOptions = RADIO_mediaClassificationOptions();
$media = $selected
    ? array_values(array_filter(
        RADIO_getMediaList(100, false, 'title', 'asc', $pickerQuery),
        function ($row) {
            return RADIO_hasReadAccess($row) && RADIO_isBroadcastAvailable($row);
        }
    ))
    : array();

$token = SEC_createToken();

$content = '';

$content .= '<div style="display:grid;grid-template-columns:minmax(220px,30%) 1fr;gap:1.5rem;align-items:start">';
$content .= '<div><h2>' . htmlspecialchars($LANG_RADIO['program_list'], ENT_QUOTES, 'UTF-8') . '</h2>';
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
$content .= '</div>';

$content .= '<div><h2>' . htmlspecialchars($selected ? $LANG_RADIO['edit_program'] : $LANG_RADIO['new_program'], ENT_QUOTES, 'UTF-8') . '</h2>'
    . '<form method="post" enctype="multipart/form-data" action="">'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['title'], ENT_QUOTES, 'UTF-8')
    . '<br><input type="text" name="program_title" maxlength="255" required style="width:100%" value="'
    . htmlspecialchars($selected ? $selected['title'] : '', ENT_QUOTES, 'UTF-8') . '"></label></p>'
    . ($selected && !empty($selected['cover_name']) ? '<p><img src="' . htmlspecialchars(RADIO_coverUrl('program',$selectedId), ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:220px;max-height:220px"></p>' : '')
    . '<p><label>' . htmlspecialchars($LANG_RADIO['host'], ENT_QUOTES, 'UTF-8') . '<br><input type="text" name="program_host" maxlength="255" style="width:100%" value="' . htmlspecialchars($selected ? $selected['host'] : '', ENT_QUOTES, 'UTF-8') . '"></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['cover'], ENT_QUOTES, 'UTF-8') . '<br><input type="file" name="cover_file" accept=".jpg,.jpeg,.png,.webp,image/*"></label></p>'
    . '<p><label>' . htmlspecialchars($LANG_RADIO['description'], ENT_QUOTES, 'UTF-8')
    . '<br><textarea name="program_description" rows="5" style="width:100%">'
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

if ($selected) {
    $programDuration = RADIO_programDuration($selectedId);
    $content .= '<h2>' . htmlspecialchars($LANG_RADIO['program_items'], ENT_QUOTES, 'UTF-8') . '</h2>';
    $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['program_duration'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . gmdate('H:i:s', $programDuration) . '</p>';
    if (count($items) === 0) {
        $content .= '<p>' . htmlspecialchars($LANG_RADIO['program_items_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
    } else {
        $content .= '<ol>';
        foreach ($items as $item) {
            $content .= '<li style="margin:.5rem 0"><strong>' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong> '
                . '<small>(' . htmlspecialchars(RADIO_adminMediaTypeLabel($item['media_type']), ENT_QUOTES, 'UTF-8') . ')</small>'
                . '<form method="post" action="" style="display:inline;margin-left:.5rem">'
                . '<input type="hidden" name="program_id" value="' . (int) $selectedId . '">'
                . '<input type="hidden" name="item_id" value="' . (int) $item['item_id'] . '">'
                . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" name="move_up" value="1">↑</button> '
                . '<button type="submit" name="move_down" value="1">↓</button> '
                . '<button type="submit" name="remove_program_item" value="1">' . htmlspecialchars($LANG_RADIO['remove'], ENT_QUOTES, 'UTF-8') . '</button>'
                . '</form></li>';
        }
        $content .= '</ol>';
    }

    $content .= RADIO_adminRenderProgramMediaPicker(
        $selectedId,
        $media,
        $pickerFilters,
        $classificationOptions,
        $token
    );
}

}

$content .= '</div></div>';
$content = RADIO_adminRenderPage(
    'programs',
    $LANG_RADIO['programs'],
    $LANG_RADIO['admin_programs_intro'],
    $LANG_RADIO['admin_programs_help_title'],
    $LANG_RADIO['admin_programs_help_text'],
    $content,
    $message
);
COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['programs'],
    'headercode' => RADIO_adminHeaderCode()
)));
