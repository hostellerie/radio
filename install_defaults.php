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
    'fallback_min_repeat_minutes' => 120
);

function plugin_initconfig_radio()
{
    global $_RADIO_DEFAULT;
    $c = config::get_instance();
    if (!$c->group_exists('radio')) {
        $c->add('sg_main', NULL, 'subgroup', 0, 0, NULL, 0, true, 'radio', 0);
        $c->add('tab_main', NULL, 'tab', 0, 0, NULL, 0, true, 'radio', 0);
        $c->add('fs_main', NULL, 'fieldset', 0, 0, NULL, 0, true, 'radio', 0);
        $c->add('enabled', $_RADIO_DEFAULT['enabled'], 'select', 0, 0, 0, 10, true, 'radio', 0);
        $c->add('public_title', $_RADIO_DEFAULT['public_title'], 'text', 0, 0, 0, 20, true, 'radio', 0);
        $c->add('default_replay_days', $_RADIO_DEFAULT['default_replay_days'], 'text', 0, 0, 0, 30, true, 'radio', 0);
        $c->add('allow_downloads', $_RADIO_DEFAULT['allow_downloads'], 'select', 0, 0, 0, 40, true, 'radio', 0);
        $c->add('max_upload_mb', $_RADIO_DEFAULT['max_upload_mb'], 'text', 0, 0, 0, 50, true, 'radio', 0);
        $c->add('fallback_enabled', $_RADIO_DEFAULT['fallback_enabled'], 'select', 0, 0, 0, 60, true, 'radio', 0);
        $c->add('fallback_jingle_interval', $_RADIO_DEFAULT['fallback_jingle_interval'], 'text', 0, 0, 0, 70, true, 'radio', 0);
        $c->add('fallback_announcement_interval', $_RADIO_DEFAULT['fallback_announcement_interval'], 'text', 0, 0, 0, 80, true, 'radio', 0);
        $c->add('fallback_type_weights', $_RADIO_DEFAULT['fallback_type_weights'], 'text', 0, 0, 0, 90, true, 'radio', 0);
        $c->add('fallback_min_repeat_minutes', $_RADIO_DEFAULT['fallback_min_repeat_minutes'], 'text', 0, 0, 0, 100, true, 'radio', 0);
    }
    return true;
}
