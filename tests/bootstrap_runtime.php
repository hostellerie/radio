<?php
$GLOBALS['_CONF'] = array(
    'language' => 'english',
    'path_data' => sys_get_temp_dir() . '/g2data/',
    'path' => dirname(__DIR__) . '/',
    'path_system' => dirname(__DIR__) . '/system/',
    'site_url' => 'https://example.invalid',
    'site_admin_url' => 'https://example.invalid/admin'
);
$_CONF =& $GLOBALS['_CONF'];
$_DB_table_prefix = 'gl_';
$_TABLES = array(
    'groups' => 'gl_groups',
    'plugins' => 'gl_plugins',
    'conf_values' => 'gl_conf_values'
);
$_RADIO_CONF = array();

require dirname(__DIR__) . '/functions.inc';

$errors = array();

if (!function_exists('plugin_autouninstall_radio')) {
    $errors[] = 'plugin_autouninstall_radio() is not discoverable after loading functions.inc.';
}
if (!function_exists('plugin_autoinstall_radio')) {
    $errors[] = 'plugin_autoinstall_radio() is not discoverable after loading functions.inc.';
}
if (!isset($LANG_RADIO) || !is_array($LANG_RADIO) || empty($LANG_RADIO['plugin_name'])) {
    $errors[] = 'LANG_RADIO was not loaded into global scope.';
}
if (!isset($LANG_confignames['radio']) || !is_array($LANG_confignames['radio'])) {
    $errors[] = 'LANG_confignames[radio] was not loaded into global scope.';
}
if (!isset($LANG_configsections['radio']) || !is_array($LANG_configsections['radio'])) {
    $errors[] = 'LANG_configsections[radio] was not loaded into global scope.';
}
if (!isset($LANG_tab['radio']) || !is_array($LANG_tab['radio'])) {
    $errors[] = 'LANG_tab[radio] was not loaded into global scope.';
}
if (!isset($LANG_fs['radio']) || !is_array($LANG_fs['radio'])) {
    $errors[] = 'LANG_fs[radio] was not loaded into global scope.';
}

$uninstall = function_exists('plugin_autouninstall_radio') ? plugin_autouninstall_radio() : array();
foreach (array(
    'radio_media',
    'radio_programs',
    'radio_program_items',
    'radio_schedule',
    'radio_events',
    'radio_sources',
    'radio_source_sync_log'
) as $table) {
    if (!isset($uninstall['tables']) || !in_array($table, $uninstall['tables'], true)) {
        $errors[] = 'Auto-uninstall is missing table ' . $table . '.';
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "Radio bootstrap runtime test failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - " . $error . "\n");
    }
    exit(1);
}

echo "Radio bootstrap runtime test passed.\n";
