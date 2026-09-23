<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

function RADIO_adminTemplate($file)
{
    global $_CONF;
    $template = COM_newTemplate($_CONF['path'] . 'plugins/radio/templates/admin');
    $template->set_file('page', $file);
    return $template;
}

function RADIO_adminHeaderCode()
{
    return '<meta name="robots" content="noindex,nofollow">' . "\n";
}

function RADIO_adminConfigurationButton()
{
    global $_CONF, $LANG_RADIO;

    if (!SEC_hasRights('config.radio.tab_main')) {
        return '';
    }

    $template = RADIO_adminTemplate('configuration-button.thtml');
    $template->set_var(array(
        'configuration_url' => htmlspecialchars(
            $_CONF['site_admin_url'] . '/configuration.php',
            ENT_QUOTES,
            'UTF-8'
        ),
        'configuration_label' => htmlspecialchars(
            $LANG_RADIO['admin_configuration'],
            ENT_QUOTES,
            'UTF-8'
        )
    ));

    return $template->finish($template->parse('output', 'page'));
}

function RADIO_adminNavItem($active, $key, $url, $label, $allowed)
{
    if (!$allowed) {
        return '';
    }

    return '<a class="' . ($active === $key ? 'is-active' : '') . '" href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

function RADIO_adminNavigation($active)
{
    global $_CONF, $LANG_RADIO;

    $adminBase = $_CONF['site_admin_url'] . '/plugins/radio/';
    $template = RADIO_adminTemplate('navigation.thtml');
    $template->set_var(array(
        'nav_aria_label' => htmlspecialchars($LANG_RADIO['admin_navigation'], ENT_QUOTES, 'UTF-8'),
        'nav_library' => RADIO_adminNavItem(
            $active,
            'library',
            $adminBase . 'index.php',
            $LANG_RADIO['library'],
            SEC_hasRights('radio.admin')
        ),
        'nav_programs' => RADIO_adminNavItem(
            $active,
            'programs',
            $adminBase . 'programs.php',
            $LANG_RADIO['programs'],
            SEC_hasRights('radio.schedule')
        ),
        'nav_schedule' => RADIO_adminNavItem(
            $active,
            'schedule',
            $adminBase . 'schedule.php',
            $LANG_RADIO['schedule'],
            SEC_hasRights('radio.schedule')
        ),
        'nav_rotation' => RADIO_adminNavItem(
            $active,
            'rotation',
            $adminBase . 'rotation.php',
            $LANG_RADIO['automatic_rotation'],
            SEC_hasRights('radio.admin')
        ),
        'nav_sources' => RADIO_adminNavItem(
            $active,
            'sources',
            $adminBase . 'sources.php',
            $LANG_RADIO['feed_sources'],
            SEC_hasRights('radio.admin')
        ),
        'nav_stats' => RADIO_adminNavItem(
            $active,
            'stats',
            $adminBase . 'stats.php',
            $LANG_RADIO['statistics'],
            SEC_hasRights('radio.admin')
        )
    ));

    return $template->finish($template->parse('output', 'page'));
}

function RADIO_adminRenderPage($active, $title, $intro, $helpTitle, $helpText, $pageContent, $message)
{
    $template = RADIO_adminTemplate('page.thtml');
    $template->set_var(array(
        'page_title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        'page_intro' => htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'),
        'configuration_button' => RADIO_adminConfigurationButton(),
        'navigation' => RADIO_adminNavigation($active),
        'message' => $message,
        'help_title' => htmlspecialchars($helpTitle, ENT_QUOTES, 'UTF-8'),
        'help_text' => htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8'),
        'page_content' => $pageContent
    ));

    return $template->finish($template->parse('output', 'page'));
}

function RADIO_adminMediaTypeLabel($type)
{
    global $LANG_RADIO;
    $key = 'type_' . (string) $type;
    return isset($LANG_RADIO[$key]) ? $LANG_RADIO[$key] : (string) $type;
}

function RADIO_adminStatusLabel($status)
{
    global $LANG_RADIO;
    $key = (string) $status;
    return isset($LANG_RADIO[$key]) ? $LANG_RADIO[$key] : $key;
}

function RADIO_adminSourceKindLabel($kind)
{
    global $LANG_RADIO;

    if ($kind === 'live') {
        return $LANG_RADIO['source_live'];
    }
    if ($kind === 'external') {
        return $LANG_RADIO['source_external'];
    }
    return $LANG_RADIO['source_local'];
}

function RADIO_adminSyncModeLabel($mode)
{
    global $LANG_RADIO;
    $key = $mode === 'drafts' ? 'feed_sync_drafts' : 'feed_sync_preview';
    return $LANG_RADIO[$key];
}

function RADIO_adminSelectOptions($items, $selected)
{
    $html = '';
    foreach ($items as $value => $label) {
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'
            . ((string) $value === (string) $selected ? ' selected' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function RADIO_adminMediaTypeOptions($selected, $includeSpecial)
{
    global $LANG_RADIO;
    $types = array('music','podcast','interview','show','chronicle');
    if ($includeSpecial) {
        $types = array_merge($types, array('jingle','announcement','promo'));
    }
    $items = array();
    foreach ($types as $type) {
        $items[$type] = $LANG_RADIO['type_' . $type];
    }
    return RADIO_adminSelectOptions($items, $selected);
}

function RADIO_adminStatusOptions($selected)
{
    global $LANG_RADIO;
    return RADIO_adminSelectOptions(array(
        'draft' => $LANG_RADIO['draft'],
        'published' => $LANG_RADIO['published']
    ), $selected);
}

function RADIO_adminQuickActions()
{
    global $_CONF, $LANG_RADIO;
    if (!SEC_hasRights('radio.upload')) {
        return '';
    }
    $template = RADIO_adminTemplate('quick-actions.thtml');
    $template->set_var(array(
        'quick_actions_title' => htmlspecialchars($LANG_RADIO['admin_quick_actions'], ENT_QUOTES, 'UTF-8'),
        'upload_url' => htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/upload.php', ENT_QUOTES, 'UTF-8'),
        'upload_label' => htmlspecialchars($LANG_RADIO['admin_add_audio'], ENT_QUOTES, 'UTF-8'),
        'external_url' => htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/external.php', ENT_QUOTES, 'UTF-8'),
        'external_label' => htmlspecialchars($LANG_RADIO['admin_add_external'], ENT_QUOTES, 'UTF-8'),
    ));
    return $template->finish($template->parse('output', 'page'));
}

function RADIO_adminFormatDuration($seconds)
{
    $seconds = max(0, (int) $seconds);
    $hours = (int) floor($seconds / 3600);
    $minutes = (int) floor(($seconds % 3600) / 60);
    $remaining = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining);
}

function RADIO_adminFormatSize($bytes)
{
    global $LANG_RADIO;
    $mb = max(0, (int) $bytes) / 1048576;
    return number_format($mb, 2) . ' ' . $LANG_RADIO['admin_mb_short'];
}

function RADIO_adminDisplayName($value)
{
    $value = (string) $value;
    $decoded = rawurldecode($value);
    return $decoded !== '' ? $decoded : $value;
}

function RADIO_adminAvailabilityHtml($row)
{
    global $LANG_RADIO;

    $items = array();
    if (!empty($row['on_demand'])) {
        $items[] = '<span class="radio-admin__badge">'
            . htmlspecialchars($LANG_RADIO['on_demand'], ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
    if (!empty($row['broadcast'])) {
        $items[] = '<span class="radio-admin__badge">'
            . htmlspecialchars($LANG_RADIO['broadcast'], ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
    if (!array_key_exists('automatic_rotation', $row) || !empty($row['automatic_rotation'])) {
        $items[] = '<span class="radio-admin__badge">'
            . htmlspecialchars($LANG_RADIO['automatic_rotation'], ENT_QUOTES, 'UTF-8')
            . '</span>';
    }

    return empty($items) ? '<span class="radio-admin__muted">—</span>' : implode('', $items);
}

function RADIO_adminFilterSelectOptions($items, $selected, $allLabel)
{
    $html = '<option value="">' . htmlspecialchars($allLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    $keys = array_keys($items);
    $isList = count($keys) === 0 || $keys === range(0, count($keys) - 1);

    foreach ($items as $value => $label) {
        if ($isList) {
            $value = $label;
        }
        $html .= '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"'
            . ((string) $value === (string) $selected ? ' selected' : '') . '>'
            . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function RADIO_adminRenderMediaFilters($filters, $options, $sort, $direction)
{
    global $_CONF, $LANG_RADIO;

    $filters = is_array($filters) ? $filters : array();
    $options = is_array($options) ? $options : array();

    $typeItems = array();
    foreach (array('music','podcast','interview','show','chronicle','jingle','announcement','promo') as $type) {
        $typeItems[$type] = $LANG_RADIO['type_' . $type];
    }

    $statusItems = array(
        'draft' => $LANG_RADIO['draft'],
        'published' => $LANG_RADIO['published']
    );

    $sourceItems = array(
        'local' => $LANG_RADIO['source_local'],
        'external' => $LANG_RADIO['source_external'],
        'live' => $LANG_RADIO['source_live']
    );

    $flagItems = array(
        '1' => $LANG_RADIO['yes'],
        '0' => $LANG_RADIO['no']
    );

    $template = RADIO_adminTemplate('media-filters.thtml');
    $template->set_var(array(
        'search_label' => htmlspecialchars($LANG_RADIO['filter_search'], ENT_QUOTES, 'UTF-8'),
        'search_placeholder' => htmlspecialchars($LANG_RADIO['filter_search_placeholder'], ENT_QUOTES, 'UTF-8'),
        'search_value' => htmlspecialchars(isset($filters['q']) ? $filters['q'] : '', ENT_QUOTES, 'UTF-8'),
        'type_label' => htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8'),
        'type_options' => RADIO_adminFilterSelectOptions($typeItems, isset($filters['type']) ? $filters['type'] : '', $LANG_RADIO['filter_all']),
        'category_label' => htmlspecialchars($LANG_RADIO['category'], ENT_QUOTES, 'UTF-8'),
        'category_options' => RADIO_adminFilterSelectOptions(isset($options['categories']) ? $options['categories'] : array(), isset($filters['category']) ? $filters['category'] : '', $LANG_RADIO['filter_all']),
        'collection_label' => htmlspecialchars($LANG_RADIO['collection'], ENT_QUOTES, 'UTF-8'),
        'collection_options' => RADIO_adminFilterSelectOptions(isset($options['collections']) ? $options['collections'] : array(), isset($filters['collection']) ? $filters['collection'] : '', $LANG_RADIO['filter_all']),
        'tag_label' => htmlspecialchars($LANG_RADIO['tags'], ENT_QUOTES, 'UTF-8'),
        'tag_options' => RADIO_adminFilterSelectOptions(isset($options['tags']) ? $options['tags'] : array(), isset($filters['tag']) ? $filters['tag'] : '', $LANG_RADIO['filter_all']),
        'status_label' => htmlspecialchars($LANG_RADIO['status'], ENT_QUOTES, 'UTF-8'),
        'status_options' => RADIO_adminFilterSelectOptions($statusItems, isset($filters['status']) ? $filters['status'] : '', $LANG_RADIO['filter_all']),
        'source_label' => htmlspecialchars($LANG_RADIO['source_kind'], ENT_QUOTES, 'UTF-8'),
        'source_options' => RADIO_adminFilterSelectOptions($sourceItems, isset($filters['source']) ? $filters['source'] : '', $LANG_RADIO['filter_all']),
        'on_demand_label' => htmlspecialchars($LANG_RADIO['on_demand'], ENT_QUOTES, 'UTF-8'),
        'on_demand_options' => RADIO_adminFilterSelectOptions($flagItems, isset($filters['on_demand']) ? $filters['on_demand'] : '', $LANG_RADIO['filter_all']),
        'broadcast_label' => htmlspecialchars($LANG_RADIO['broadcast'], ENT_QUOTES, 'UTF-8'),
        'broadcast_options' => RADIO_adminFilterSelectOptions($flagItems, isset($filters['broadcast']) ? $filters['broadcast'] : '', $LANG_RADIO['filter_all']),
        'automatic_rotation_label' => htmlspecialchars($LANG_RADIO['automatic_rotation'], ENT_QUOTES, 'UTF-8'),
        'automatic_rotation_options' => RADIO_adminFilterSelectOptions($flagItems, isset($filters['automatic_rotation']) ? $filters['automatic_rotation'] : '', $LANG_RADIO['filter_all']),
        'sort' => htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'),
        'direction' => htmlspecialchars($direction, ENT_QUOTES, 'UTF-8'),
        'filter_label' => htmlspecialchars($LANG_RADIO['filter_apply'], ENT_QUOTES, 'UTF-8'),
        'reset_label' => htmlspecialchars($LANG_RADIO['filter_reset'], ENT_QUOTES, 'UTF-8'),
        'reset_url' => htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/index.php', ENT_QUOTES, 'UTF-8')
    ));

    return $template->finish($template->parse('output', 'page'));
}

function RADIO_adminClassificationHtml($row)
{
    global $LANG_RADIO;

    $parts = array();
    if (!empty($row['category'])) {
        $parts[] = '<span class="radio-admin__badge">'
            . htmlspecialchars($LANG_RADIO['category'] . ': ' . $row['category'], ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
    if (!empty($row['collection_name'])) {
        $parts[] = '<span class="radio-admin__badge">'
            . htmlspecialchars($LANG_RADIO['collection'] . ': ' . $row['collection_name'], ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
    if (!empty($row['tags'])) {
        foreach (explode(',', (string) $row['tags']) as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $parts[] = '<span class="radio-admin__badge radio-admin__badge--muted">'
                . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8')
                . '</span>';
        }
    }

    return implode('', $parts);
}

function RADIO_adminRenderProgramMediaPicker($programId, $media, $filters, $options, $token)
{
    global $_CONF, $LANG_RADIO;

    $programId = (int) $programId;
    $filters = is_array($filters) ? $filters : array();
    $options = is_array($options) ? $options : array();

    $typeItems = array();
    foreach (array('music','podcast','interview','show','chronicle','jingle','announcement','promo') as $type) {
        $typeItems[$type] = $LANG_RADIO['type_' . $type];
    }

    $query = array('program_id' => $programId);
    foreach (array('picker_q','picker_type','picker_category','picker_collection','picker_tag') as $key) {
        if (isset($filters[$key]) && (string) $filters[$key] !== '') {
            $query[$key] = $filters[$key];
        }
    }
    $postUrl = $_CONF['site_admin_url'] . '/plugins/radio/programs.php?'
        . http_build_query($query, '', '&');

    $rows = '';
    if (count($media) === 0) {
        $rows = '<p class="radio-admin__muted">'
            . htmlspecialchars($LANG_RADIO['program_media_search_empty'], ENT_QUOTES, 'UTF-8')
            . '</p>';
    } else {
        foreach ($media as $row) {
            $result = RADIO_adminTemplate('program-media-result.thtml');
            $meta = array();
            if (!empty($row['author'])) {
                $meta[] = $row['author'];
            }
            $meta[] = RADIO_adminMediaTypeLabel($row['media_type']);
            if (!empty($row['duration'])) {
                $meta[] = gmdate('H:i:s', (int) $row['duration']);
            }

            $result->set_var(array(
                'title' => htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'),
                'meta' => htmlspecialchars(implode(' · ', $meta), ENT_QUOTES, 'UTF-8'),
                'classification' => RADIO_adminClassificationHtml($row),
                'post_url' => htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8'),
                'program_id' => $programId,
                'media_id' => (int) $row['media_id'],
                'csrf_name' => CSRF_TOKEN,
                'csrf_token' => htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
                'add_title' => htmlspecialchars(
                    sprintf($LANG_RADIO['program_media_add_title'], $row['title']),
                    ENT_QUOTES,
                    'UTF-8'
                )
            ));
            $rows .= $result->finish($result->parse('output', 'page'));
        }
    }

    $picker = RADIO_adminTemplate('program-media-picker.thtml');
    $picker->set_var(array(
        'picker_title' => htmlspecialchars($LANG_RADIO['program_media_search_title'], ENT_QUOTES, 'UTF-8'),
        'picker_help' => htmlspecialchars($LANG_RADIO['program_media_search_help'], ENT_QUOTES, 'UTF-8'),
        'result_count' => sprintf(
            htmlspecialchars($LANG_RADIO['program_media_search_count'], ENT_QUOTES, 'UTF-8'),
            count($media)
        ),
        'program_id' => $programId,
        'search_label' => htmlspecialchars($LANG_RADIO['filter_search'], ENT_QUOTES, 'UTF-8'),
        'search_placeholder' => htmlspecialchars($LANG_RADIO['program_media_search_placeholder'], ENT_QUOTES, 'UTF-8'),
        'search_value' => htmlspecialchars(isset($filters['picker_q']) ? $filters['picker_q'] : '', ENT_QUOTES, 'UTF-8'),
        'type_label' => htmlspecialchars($LANG_RADIO['type'], ENT_QUOTES, 'UTF-8'),
        'type_options' => RADIO_adminFilterSelectOptions(
            $typeItems,
            isset($filters['picker_type']) ? $filters['picker_type'] : '',
            $LANG_RADIO['filter_all']
        ),
        'category_label' => htmlspecialchars($LANG_RADIO['category'], ENT_QUOTES, 'UTF-8'),
        'category_options' => RADIO_adminFilterSelectOptions(
            isset($options['categories']) ? $options['categories'] : array(),
            isset($filters['picker_category']) ? $filters['picker_category'] : '',
            $LANG_RADIO['filter_all']
        ),
        'collection_label' => htmlspecialchars($LANG_RADIO['collection'], ENT_QUOTES, 'UTF-8'),
        'collection_options' => RADIO_adminFilterSelectOptions(
            isset($options['collections']) ? $options['collections'] : array(),
            isset($filters['picker_collection']) ? $filters['picker_collection'] : '',
            $LANG_RADIO['filter_all']
        ),
        'tag_label' => htmlspecialchars($LANG_RADIO['tags'], ENT_QUOTES, 'UTF-8'),
        'tag_options' => RADIO_adminFilterSelectOptions(
            isset($options['tags']) ? $options['tags'] : array(),
            isset($filters['picker_tag']) ? $filters['picker_tag'] : '',
            $LANG_RADIO['filter_all']
        ),
        'search_button' => htmlspecialchars($LANG_RADIO['program_media_search_button'], ENT_QUOTES, 'UTF-8'),
        'reset_button' => htmlspecialchars($LANG_RADIO['filter_reset'], ENT_QUOTES, 'UTF-8'),
        'reset_url' => htmlspecialchars(
            $_CONF['site_admin_url'] . '/plugins/radio/programs.php?program_id=' . $programId,
            ENT_QUOTES,
            'UTF-8'
        ),
        'media_results' => $rows
    ));

    return $picker->finish($picker->parse('output', 'page'));
}

function RADIO_adminSortHeader($key, $label, $sort, $direction, $filters = array())
{
    global $_CONF;

    $nextDirection = ($sort === $key && $direction === 'asc') ? 'desc' : 'asc';
    $indicator = '';
    if ($sort === $key) {
        $indicator = $direction === 'asc' ? ' ↑' : ' ↓';
    }

    $params = array(
        'sort' => $key,
        'direction' => $nextDirection
    );
    foreach (array('q','type','category','collection','tag','status','source','on_demand','broadcast','automatic_rotation') as $filterKey) {
        if (isset($filters[$filterKey]) && (string) $filters[$filterKey] !== '') {
            $params[$filterKey] = $filters[$filterKey];
        }
    }

    $url = $_CONF['site_admin_url'] . '/plugins/radio/index.php?' . http_build_query($params, '', '&');

    return '<a class="radio-admin__sort" href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label . $indicator, ENT_QUOTES, 'UTF-8')
        . '</a>';
}

function RADIO_adminRenderMediaList($media, $sort = 'modified', $direction = 'desc', $filters = array())
{
    global $_CONF, $LANG_RADIO;

    $wrapper = RADIO_adminTemplate('media-list.thtml');
    $wrapper->set_var('library_title', htmlspecialchars($LANG_RADIO['library'], ENT_QUOTES, 'UTF-8'));

    if (empty($media)) {
        $wrapper->set_var('library_content', '<p>' . htmlspecialchars($LANG_RADIO['library_empty'], ENT_QUOTES, 'UTF-8') . '</p>');
        return $wrapper->finish($wrapper->parse('output', 'page'));
    }

    $rows = '';
    foreach ($media as $row) {
        $rowTemplate = RADIO_adminTemplate('media-list-row.thtml');
        $rowTemplate->set_var(array(
            'title' => htmlspecialchars(RADIO_adminDisplayName($row['title']), ENT_QUOTES, 'UTF-8'),
            'title_full' => htmlspecialchars(RADIO_adminDisplayName($row['title']), ENT_QUOTES, 'UTF-8'),
            'filename' => htmlspecialchars(RADIO_adminDisplayName($row['original_name']), ENT_QUOTES, 'UTF-8'),
            'filename_full' => htmlspecialchars(RADIO_adminDisplayName($row['original_name']), ENT_QUOTES, 'UTF-8'),
            'classification' => RADIO_adminClassificationHtml($row),
            'media_type' => htmlspecialchars(RADIO_adminMediaTypeLabel($row['media_type']), ENT_QUOTES, 'UTF-8'),
            'status' => htmlspecialchars(RADIO_adminStatusLabel($row['status']), ENT_QUOTES, 'UTF-8'),
            'availability' => RADIO_adminAvailabilityHtml($row),
            'source' => htmlspecialchars(RADIO_adminSourceKindLabel(RADIO_sourceKind($row)), ENT_QUOTES, 'UTF-8'),
            'duration' => htmlspecialchars(RADIO_adminFormatDuration($row['duration']), ENT_QUOTES, 'UTF-8'),
            'size' => htmlspecialchars(RADIO_adminFormatSize($row['file_size']), ENT_QUOTES, 'UTF-8'),
            'modified' => htmlspecialchars(
                !empty($row['modified']) ? date('Y-m-d H:i', strtotime($row['modified'])) : '—',
                ENT_QUOTES,
                'UTF-8'
            ),
            'edit_url' => htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/edit.php?media_id=' . (int) $row['media_id'], ENT_QUOTES, 'UTF-8'),
            'edit_label' => htmlspecialchars($LANG_RADIO['admin_edit'], ENT_QUOTES, 'UTF-8')
        ));
        $rows .= $rowTemplate->finish($rowTemplate->parse('output', 'page'));
    }

    $table = RADIO_adminTemplate('media-list-table.thtml');
    $table->set_var(array(
        'title_header' => RADIO_adminSortHeader('title', $LANG_RADIO['title'], $sort, $direction, $filters),
        'type_header' => RADIO_adminSortHeader('type', $LANG_RADIO['type'], $sort, $direction, $filters),
        'status_header' => RADIO_adminSortHeader('status', $LANG_RADIO['status'], $sort, $direction, $filters),
        'availability_header' => RADIO_adminSortHeader('availability', $LANG_RADIO['availability'], $sort, $direction, $filters),
        'source_header' => RADIO_adminSortHeader('source', $LANG_RADIO['source_kind'], $sort, $direction, $filters),
        'duration_header' => RADIO_adminSortHeader('duration', $LANG_RADIO['admin_duration'], $sort, $direction, $filters),
        'size_header' => RADIO_adminSortHeader('size', $LANG_RADIO['admin_size'], $sort, $direction, $filters),
        'modified_header' => RADIO_adminSortHeader('modified', $LANG_RADIO['admin_modified'], $sort, $direction, $filters),
        'actions_label' => htmlspecialchars($LANG_RADIO['admin_actions'], ENT_QUOTES, 'UTF-8'),
        'media_rows' => $rows
    ));

    $wrapper->set_var('library_content', $table->finish($table->parse('output', 'page')));
    return $wrapper->finish($wrapper->parse('output', 'page'));
}
