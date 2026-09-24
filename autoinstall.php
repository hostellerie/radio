<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}
require_once __DIR__ . '/version.php';

function plugin_autoinstall_radio($pi_name)
{
    $adminGroup = 'Radio Admin';
    return array(
        'info' => array(
            'pi_name' => 'radio',
            'pi_display_name' => 'Radio',
            'pi_version' => RADIO_PLUGIN_VERSION,
            'pi_gl_version' => RADIO_MIN_GEEKLOG_VERSION,
            'pi_homepage' => 'https://github.com/hostellerie/radio'
        ),
        'groups' => array(
            $adminGroup => 'Users in this group can administer the Radio plugin'
        ),
        'features' => array(
            'radio.admin' => 'Full access to Radio administration',
            'radio.upload' => 'Upload and manage Radio audio media',
            'radio.schedule' => 'Manage Radio programmes and schedules',
            'config.radio.tab_main' => 'Access Radio configuration'
        ),
        'mappings' => array(
            'radio.admin' => array($adminGroup),
            'radio.upload' => array($adminGroup),
            'radio.schedule' => array($adminGroup),
            'config.radio.tab_main' => array($adminGroup)
        ),
        'tables' => array(
            'radio_media',
            'radio_programs',
            'radio_program_items',
            'radio_schedule',
            'radio_broadcast_sessions',
            'radio_events',
            'radio_sources',
            'radio_source_sync_log'
        )
    );
}

function plugin_load_configuration_radio($pi_name)
{
    global $_CONF;
    require_once $_CONF['path_system'] . 'classes/config.class.php';
    require_once $_CONF['path'] . 'plugins/radio/install_defaults.php';
    return plugin_initconfig_radio();
}

function plugin_compatible_with_this_version_radio($pi_name)
{
    global $_CONF, $_DB_dbms;
    if (defined('VERSION') && version_compare(VERSION, RADIO_MIN_GEEKLOG_VERSION, '<')) {
        return false;
    }
    if (version_compare(PHP_VERSION, RADIO_MIN_PHP_VERSION, '<')) {
        return false;
    }
    $dbFile = $_CONF['path'] . 'plugins/' . $pi_name . '/sql/' . $_DB_dbms . '_install.php';
    return class_exists('config') && file_exists($dbFile);
}

function plugin_postinstall_radio($pi_name)
{
    global $_CONF;

    if (!function_exists('RADIO_ensureStorage')) {
        require_once $_CONF['path'] . 'plugins/radio/functions.inc';
    }

    return RADIO_ensureStorage();
}

function plugin_autouninstall_radio()
{
    return array(
        'tables' => array(
            'radio_media',
            'radio_site_media',
            'radio_programs',
            'radio_program_items',
            'radio_schedule',
            'radio_broadcast_sessions',
            'radio_events',
            'radio_sources',
            'radio_source_sync_log'
        ),
        'groups' => array('Radio Admin'),
        'features' => array(
            'radio.admin',
            'radio.upload',
            'radio.schedule',
            'config.radio.tab_main'
        ),
        'php_blocks' => array(),
        'vars' => array()
    );
}
