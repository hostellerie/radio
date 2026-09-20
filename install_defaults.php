<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file cannot be used on its own.');
}
global $_RADIO_DEFAULT;
$_RADIO_DEFAULT = array(
    'enabled' => 1,
    'public_title' => 'Radio',
    'default_replay_days' => 30,
    'allow_downloads' => 1
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
    }
    return true;
}
