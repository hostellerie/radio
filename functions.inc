<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}
require_once __DIR__ . '/version.php';

global $_TABLES, $_DB_table_prefix, $_RADIO_CONF;
$_TABLES['radio_media'] = $_DB_table_prefix . 'radio_media';
$_TABLES['radio_programs'] = $_DB_table_prefix . 'radio_programs';
$_TABLES['radio_program_items'] = $_DB_table_prefix . 'radio_program_items';
$_TABLES['radio_schedule'] = $_DB_table_prefix . 'radio_schedule';

if (!isset($_RADIO_CONF) || !is_array($_RADIO_CONF)) {
    $_RADIO_CONF = array();
    if (class_exists('config')) {
        $radioConfig = config::get_instance();
        if ($radioConfig->group_exists('radio')) {
            $_RADIO_CONF = $radioConfig->get_config('radio');
        }
    }
}

function RADIO_storageDir()
{
    global $_CONF;
    $base = isset($_CONF['path_data']) ? rtrim($_CONF['path_data'], "/\\") : '';
    if ($base === '') {
        return '';
    }
    return dirname($base) . DIRECTORY_SEPARATOR . basename($base) . '-radio' . DIRECTORY_SEPARATOR;
}

function RADIO_ensureStorage()
{
    $dir = RADIO_storageDir();
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        COM_errorLog('Radio: cannot create persistent storage directory ' . $dir, 1);
        return false;
    }
    return is_writable($dir);
}

function plugin_getcapabilities_radio()
{
    return array(
        'schema' => 1,
        'roles' => array('content', 'service'),
        'capabilities' => array(
            'content.read',
            'content.collection',
            'content.url.resolve',
            'content.lifecycle',
            'dashboard.summary',
            'radio.media.read',
            'radio.program.read',
            'radio.schedule.read',
            'radio.now-playing.read'
        )
    );
}

function plugin_getiteminfo_radio($id, $what, $uid = 0, $options = array())
{
    global $_TABLES, $_CONF;
    $fields = array_map('trim', explode(',', $what));
    if ($id === '*') {
        $limit = isset($options['limit']) ? max(1, min(100, (int) $options['limit'])) : 20;
        $result = DB_query("SELECT media_id,title,description,media_type,created,modified,hits FROM {$_TABLES['radio_media']} WHERE status='published' ORDER BY modified DESC LIMIT " . $limit);
        $items = array();
        while ($row = DB_fetchArray($result)) {
            $items[] = RADIO_itemInfoFromRow($row, $fields);
        }
        return $items;
    }

    $id = (int) $id;
    if ($id < 1) {
        return array();
    }
    $result = DB_query("SELECT media_id,title,description,media_type,created,modified,hits FROM {$_TABLES['radio_media']} WHERE media_id=" . $id . " AND status='published'");
    if (DB_numRows($result) < 1) {
        return array();
    }
    return RADIO_itemInfoFromRow(DB_fetchArray($result), $fields);
}

function RADIO_itemInfoFromRow($row, $fields)
{
    global $_CONF;
    $all = array(
        'id' => (int) $row['media_id'],
        'type' => 'radio',
        'subtype' => $row['media_type'],
        'title' => $row['title'],
        'url' => $_CONF['site_url'] . '/radio/index.php?id=' . (int) $row['media_id'],
        'description' => $row['description'],
        'excerpt' => $row['description'],
        'date-created' => $row['created'],
        'date-modified' => $row['modified'],
        'hits' => (int) $row['hits']
    );
    if (empty($fields) || in_array('*', $fields)) {
        return $all;
    }
    $out = array();
    foreach ($fields as $field) {
        if (array_key_exists($field, $all)) {
            $out[$field] = $all[$field];
        }
    }
    return $out;
}

function plugin_idtourl_radio($sub_type, $item_id)
{
    global $_CONF;
    $item_id = (int) $item_id;
    return $item_id > 0 ? $_CONF['site_url'] . '/radio/index.php?id=' . $item_id : '';
}

function plugin_getadminoption_radio()
{
    global $_CONF;
    if (!SEC_hasRights('radio.admin')) {
        return false;
    }
    return array(
        'url' => $_CONF['site_admin_url'] . '/plugins/radio/index.php',
        'text' => 'Radio',
        'count' => 0
    );
}
