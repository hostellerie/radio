<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

$GLOBALS['RADIO_UPDATES'] = array(
    '0.2.5' => array(
        'next' => '0.3.0',
        'callback' => 'radio_update_0_2_5_to_0_3_0'
    ),
    '0.3.0' => array(
        'next' => '0.3.1',
        'callback' => 'radio_update_0_3_0_to_0_3_1'
    ),
    '0.3.1' => array(
        'next' => '0.3.2',
        'callback' => 'radio_update_0_3_1_to_0_3_2'
    ),
    '0.3.2' => array(
        'next' => '0.4.0',
        'callback' => 'radio_update_0_3_2_to_0_4_0'
    )
);

function radio_column_exists($table, $column)
{
    $result = DB_query(
        "SHOW COLUMNS FROM " . $table . " LIKE '" . DB_escapeString($column) . "'"
    );
    return !DB_error() && DB_numRows($result) > 0;
}

function radio_update_0_2_5_to_0_3_0()
{
    global $_TABLES;

    $table = $_TABLES['radio_media'];

    if (!radio_column_exists($table, 'on_demand')) {
        DB_query(
            "ALTER TABLE " . $table
            . " ADD on_demand tinyint(1) unsigned NOT NULL default '1' AFTER status"
        );
        if (DB_error()) {
            return false;
        }
    }

    if (!radio_column_exists($table, 'broadcast')) {
        DB_query(
            "ALTER TABLE " . $table
            . " ADD broadcast tinyint(1) unsigned NOT NULL default '1' AFTER on_demand"
        );
        if (DB_error()) {
            return false;
        }
    }

    return radio_column_exists($table, 'on_demand')
        && radio_column_exists($table, 'broadcast');
}

function radio_update_0_3_0_to_0_3_1()
{
    // No schema migration is required. plugin_upgrade_radio() reconciles
    // the existing Geeklog configuration through RADIO_ensureConfig().
    return true;
}

function radio_update_0_3_1_to_0_3_2()
{
    global $_TABLES;

    $table = $_TABLES['radio_media'];
    if (!radio_column_exists($table, 'automatic_rotation')) {
        DB_query(
            "ALTER TABLE " . $table
            . " ADD automatic_rotation tinyint(1) unsigned NOT NULL default '1' AFTER broadcast"
        );
        if (DB_error()) {
            return false;
        }
    }

    return radio_column_exists($table, 'automatic_rotation');
}

function radio_update_0_3_2_to_0_4_0()
{
    global $_TABLES;

    $table = $_TABLES['radio_media'];

    if (!radio_column_exists($table, 'category')) {
        DB_query(
            "ALTER TABLE " . $table
            . " ADD category varchar(128) NOT NULL default '' AFTER series_title"
        );
        if (DB_error()) {
            return false;
        }
    }

    if (!radio_column_exists($table, 'collection_name')) {
        DB_query(
            "ALTER TABLE " . $table
            . " ADD collection_name varchar(255) NOT NULL default '' AFTER category"
        );
        if (DB_error()) {
            return false;
        }
    }

    if (!radio_column_exists($table, 'tags')) {
        DB_query(
            "ALTER TABLE " . $table
            . " ADD tags text AFTER collection_name"
        );
        if (DB_error()) {
            return false;
        }
    }

    return radio_column_exists($table, 'category')
        && radio_column_exists($table, 'collection_name')
        && radio_column_exists($table, 'tags');
}

function radio_apply_updates($installedVersion, $targetVersion)
{
    $updates = isset($GLOBALS['RADIO_UPDATES']) && is_array($GLOBALS['RADIO_UPDATES'])
        ? $GLOBALS['RADIO_UPDATES']
        : array();

    if ($installedVersion === $targetVersion) {
        return true;
    }
    if ($installedVersion === '' || version_compare($installedVersion, $targetVersion, '>')) {
        return false;
    }

    $currentVersion = $installedVersion;
    $visited = array();

    while ($currentVersion !== $targetVersion) {
        if (isset($visited[$currentVersion]) || !isset($updates[$currentVersion])) {
            return false;
        }
        $visited[$currentVersion] = true;

        $step = $updates[$currentVersion];
        if (!isset($step['next']) || version_compare($step['next'], $currentVersion, '<=')
            || version_compare($step['next'], $targetVersion, '>')) {
            return false;
        }

        if (!empty($step['callback'])) {
            if (!function_exists($step['callback'])
                || call_user_func($step['callback']) !== true) {
                return false;
            }
        }

        $currentVersion = $step['next'];
    }

    return true;
}
