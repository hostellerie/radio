<?php
$root = dirname(__DIR__);
$functions = file_get_contents($root . '/functions.inc');
$autoinstall = file_get_contents($root . '/autoinstall.php');
$english = file_get_contents($root . '/language/english.php');
$french = file_get_contents($root . '/language/french.php');

$errors = array();

function radio_contract_require($condition, $message)
{
    global $errors;
    if (!$condition) {
        $errors[] = $message;
    }
}

radio_contract_require(
    preg_match('/function\s+plugin_getadminoption_radio\s*\(\s*\)/', $functions) === 1,
    'Missing plugin_getadminoption_radio().'
);
radio_contract_require(
    strpos($functions, "'url' => \$_CONF['site_admin_url'] . '/plugins/radio/index.php'") === false,
    'plugin_getadminoption_radio() must not use the old associative url/text/count return format.'
);
radio_contract_require(
    preg_match(
        '/return\s+array\s*\(\s*\$label\s*,\s*\$_CONF\[\'site_admin_url\'\]\s*\.\s*\'\/plugins\/radio\/index\.php\'\s*,\s*0\s*\)/s',
        $functions
    ) === 1,
    'plugin_getadminoption_radio() must return array(label, url, count).'
);
radio_contract_require(
    preg_match(
        '/function\s+plugin_idtourl_radio\s*\(\s*\$sub_type\s*=\s*\'\'\s*,\s*\$item_id\s*=\s*null\s*\)/',
        $functions
    ) === 1,
    'plugin_idtourl_radio() must support both one-argument and subtype-aware two-argument calls.'
);
radio_contract_require(
    preg_match('/function\s+plugin_chkVersion_radio\s*\(\s*\)/', $functions) === 1,
    'Missing plugin_chkVersion_radio().'
);
radio_contract_require(
    preg_match('/function\s+plugin_wsEnabled_radio\s*\(\s*\)/', $functions) === 1,
    'Missing plugin_wsEnabled_radio().'
);
radio_contract_require(
    preg_match('/function\s+plugin_getcapabilities_radio\s*\(\s*\)/', $functions) === 1,
    'Missing plugin_getcapabilities_radio().'
);
radio_contract_require(
    preg_match('/function\s+RADIO_singleItemInfoResult\s*\(/', $functions) === 1,
    'Radio must map single Item Info responses to Geeklog positional values.'
);
radio_contract_require(
    strpos($functions, 'return RADIO_singleItemInfoResult(') !== false,
    'Single Radio Item Info responses must use Geeklog positional mapping.'
);
radio_contract_require(
    preg_match('/function\s+plugin_getheadercode_radio\s*\(\s*\)/', $functions) === 1,
    'Missing plugin_getheadercode_radio().'
);
radio_contract_require(
    strpos($functions, "RADIO_PLUGIN_VERSION") !== false
        && strpos($functions, "filemtime") !== false
        && strpos($functions, "radio-admin.css") !== false,
    'Radio admin CSS must be versioned with plugin version and file modification time.'
);
radio_contract_require(
    preg_match('/function\s+plugin_autoinstall_radio\s*\(\s*\$pi_name\s*\)/', $autoinstall) === 1,
    'Missing plugin_autoinstall_radio($pi_name).'
);
radio_contract_require(
    preg_match('/function\s+plugin_autouninstall_radio\s*\(\s*\)/', $autoinstall) === 1,
    'Missing plugin_autouninstall_radio().'
);
radio_contract_require(
    strpos($functions, "require_once __DIR__ . '/autoinstall.php';") !== false,
    'functions.inc must load autoinstall.php so Geeklog can discover plugin_autouninstall_radio() for disabled plugins.'
);
radio_contract_require(
    strpos($autoinstall, "require_once __DIR__ . '/functions.inc';") === false,
    'autoinstall.php must not create a circular functions.inc dependency.'
);
radio_contract_require(
    strpos($functions, "\$radioLanguageFile = __DIR__ . '/language/' . \$radioLanguage . '.php';") !== false,
    'functions.inc must bootstrap the Radio language file.'
);
radio_contract_require(
    strpos($functions, "language/english.php") !== false,
    'Radio language bootstrap must provide an English fallback.'
);
radio_contract_require(
    strpos($functions, 'global $_CONF, $_TABLES, $_DB_table_prefix, $_RADIO_CONF, $LANG_RADIO;') !== false,
    'functions.inc must declare Geeklog globals before language bootstrap.'
);
foreach (array('english' => $english, 'french' => $french) as $languageName => $languageFile) {
    radio_contract_require(
        strpos($languageFile, 'global $LANG_RADIO, $LANG_configsections, $LANG_confignames, $LANG_configsubgroups, $LANG_tab, $LANG_fs, $LANG_configselects;') !== false,
        'Radio language files must declare Geeklog configuration language arrays as globals: ' . $languageName
    );
    radio_contract_require(
        strpos($languageFile, "\$LANG_confignames['radio']") !== false,
        'Radio language file must define LANG_confignames[radio]: ' . $languageName
    );
}

foreach (array(
    'radio_media',
    'radio_programs',
    'radio_program_items',
    'radio_schedule',
    'radio_events',
    'radio_sources',
    'radio_source_sync_log'
) as $table) {
    radio_contract_require(
        strpos($autoinstall, "'" . $table . "'") !== false,
        'Autoinstall/uninstall contract is missing table ' . $table . '.'
    );
}

if (!empty($errors)) {
    fwrite(STDERR, "Radio Plugin API contract check failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - " . $error . "\n");
    }
    exit(1);
}

echo "Radio Plugin API contract check passed.\n";

radio_contract_require(
    preg_match('/function\s+RADIO_detectAudioDuration\s*\(/', $functions) === 1,
    'Radio must provide server-side audio duration detection.'
);
radio_contract_require(
    strpos($functions, 'RADIO_detectM4aDuration') !== false
        && strpos($functions, 'RADIO_detectMp3Duration') !== false,
    'Radio duration detection must cover M4A and MP3.'
);
radio_contract_require(
    substr_count($functions, 'RADIO_detectAudioDuration(') >= 3,
    'Radio must use duration detection for new and existing local media.'
);
