<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file cannot be used on its own.');
}

global $_RADIO_DEFAULT;
$_RADIO_DEFAULT = array(
    'enabled' => 1,
    'public_title' => 'Radio',
    'default_replay_days' => 30,
    'allow_downloads' => 1,
    'max_upload_mb' => 100,
    'fallback_enabled' => 1,
    'fallback_jingle_interval' => 4,
    'fallback_announcement_interval' => 8,
    'fallback_type_weights' => 'music=5,podcast=2,interview=2,chronicle=2',
    'fallback_min_repeat_minutes' => 120,
    'whatsnew_enabled' => 1,
    'whatsnew_interval' => 1209600,
    'whatsnew_limit' => 10,
    'stats_enabled' => 1,
    'stats_retention_days' => 90
);

function RADIO_configSortOrder()
{
    return array(
        'enabled' => 10,
        'public_title' => 20,
        'default_replay_days' => 30,
        'allow_downloads' => 40,
        'max_upload_mb' => 50,
        'fallback_enabled' => 60,
        'fallback_jingle_interval' => 70,
        'fallback_announcement_interval' => 80,
        'fallback_type_weights' => 90,
        'fallback_min_repeat_minutes' => 100,
        'whatsnew_enabled' => 110,
        'whatsnew_interval' => 120,
        'whatsnew_limit' => 130,
        'stats_enabled' => 140,
        'stats_retention_days' => 150
    );
}

function RADIO_addConfigSetting($c, $name, $default, $sort)
{
    $type = in_array($name, array('enabled', 'allow_downloads', 'fallback_enabled', 'whatsnew_enabled', 'stats_enabled'), true)
        ? 'select'
        : 'text';

    $c->add($name, $default, $type, 0, 0, 0, $sort, true, 'radio', 0);
}

function RADIO_addFullConfig($c)
{
    global $_RADIO_DEFAULT;

    $c->add('sg_main', NULL, 'subgroup', 0, 0, NULL, 0, true, 'radio', 0);
    $c->add('tab_main', NULL, 'tab', 0, 0, NULL, 0, true, 'radio', 0);
    $c->add('fs_main', NULL, 'fieldset', 0, 0, NULL, 0, true, 'radio', 0);

    foreach (RADIO_configSortOrder() as $name => $sort) {
        RADIO_addConfigSetting($c, $name, $_RADIO_DEFAULT[$name], $sort);
    }
}

function RADIO_ensureConfig()
{
    global $_RADIO_DEFAULT;

    $c = config::get_instance();

    if (!$c->group_exists('radio')) {
        RADIO_addFullConfig($c);
        return true;
    }

    $current = $c->get_config('radio');
    if (!is_array($current)) {
        $current = array();
    }

    foreach (RADIO_configSortOrder() as $name => $sort) {
        if (!array_key_exists($name, $current)) {
            RADIO_addConfigSetting($c, $name, $_RADIO_DEFAULT[$name], $sort);
        }
    }

    return true;
}

function plugin_initconfig_radio()
{
    return RADIO_ensureConfig();
}
