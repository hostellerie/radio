<?php
$root = dirname(__DIR__);
$functions = file_get_contents($root . '/functions.inc');
$autoinstall = file_get_contents($root . '/autoinstall.php');
$english = file_get_contents($root . '/language/english.php');
$french = file_get_contents($root . '/language/french.php');
$defaults = file_get_contents($root . '/install_defaults.php');
$publicIndex = file_get_contents($root . '/public_html/index.php');
$nowEndpoint = file_get_contents($root . '/public_html/now.php');
$publicJs = file_get_contents($root . '/public_html/radio.js');
$publicCss = file_get_contents($root . '/public_html/radio.css');
$adminJs = file_get_contents($root . '/admin/radio-admin.js');

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
    preg_match('/function\s+plugin_upgrade_radio\s*\(\s*\)/', $functions) === 1
        && file_exists($root . '/install_updates.php'),
    'Radio must provide an explicit upgrade path.'
);
radio_contract_require(
    strpos(file_get_contents($root . '/sql/mysql_install.php'), 'on_demand tinyint(1)') !== false
        && strpos(file_get_contents($root . '/sql/mysql_install.php'), 'broadcast tinyint(1)') !== false,
    'Radio media schema must expose on_demand and broadcast availability.'
);
radio_contract_require(
    strpos($functions, 'RADIO_isOnDemandAvailable') !== false
        && strpos($functions, 'RADIO_isBroadcastAvailable') !== false,
    'Radio must expose independent media availability helpers.'
);
radio_contract_require(
    strpos($functions, 'RADIO_mediaAvailabilitySchemaReady') !== false
        && strpos($functions, 'RADIO_mediaAvailabilitySql') !== false,
    'Radio 0.3.0 must remain readable before the availability schema upgrade is applied.'
);
radio_contract_require(
    strpos($functions, "AND on_demand=1") === false
        && strpos($functions, "AND broadcast=1") === false,
    'Radio public/runtime reads must not query 0.3.0 availability columns unconditionally before upgrade.'
);
radio_contract_require(
    strpos($functions, '/radio/live.php') === false,
    'Removed Radio live.php must not be referenced by runtime code.'
);
radio_contract_require(
    strpos($functions, 'function RADIO_isDatabaseCurrent') !== false
        && strpos($functions, 'function plugin_collectSitemapItems_radio') !== false
        && strpos($functions, 'if (!RADIO_isDatabaseCurrent())') !== false,
    'Radio external integrations must stay inactive until the database upgrade is complete.'
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

radio_contract_require(
    strpos($defaults, "'on_demand_enabled' => 1") !== false,
    'Radio must provide an enabled-by-default on_demand_enabled configuration.'
);
radio_contract_require(
    strpos($publicIndex, 'radio-home-audio') !== false
        && strpos($publicIndex, 'radio-home-wave') !== false,
    'Radio public index must expose the native home live player and waveform.'
);
radio_contract_require(
    strpos($publicIndex, '<progress') === false
        && strpos($publicIndex, 'radio-on-air-progress') === false,
    'Radio public home must use a single native audio timeline.'
);
radio_contract_require(
    strpos($publicJs, '15000') !== false
        && strpos($publicJs, 'data.current_media') !== false,
    'Radio public index player must resynchronize with now.php every 15 seconds.'
);
radio_contract_require(
    strpos($publicJs, 'AudioContext') !== false
        && strpos($publicJs, 'createAnalyser') !== false
        && strpos($publicJs, 'getByteTimeDomainData') !== false
        && strpos($publicJs, 'visualGain') !== false,
    'Radio public waveform must use normalized Web Audio time-domain analysis where available.'
);
radio_contract_require(
    strpos($publicIndex, 'radio-home-wave') !== false,
    'Radio waveform canvas must be present on the public home player.'
);
radio_contract_require(
    strpos($nowEndpoint, "'source_kind'") !== false,
    'Radio now endpoint must expose source_kind for safe waveform handling.'
);
radio_contract_require(
    strpos($functions, 'RADIO_publicStylesheetLink') !== false
        && strpos($functions, 'RADIO_publicScriptTag') !== false
        && strpos($functions, 'RADIO_adminScriptTag') !== false
        && strpos($functions, 'RADIO_assetVersion') !== false,
    'Radio must load versioned public CSS/JS and admin JavaScript assets.'
);
radio_contract_require(
    strpos($functions, 'radio.css') !== false
        && strpos($functions, 'radio.js') !== false
        && strpos($functions, 'radio-admin.js') !== false,
    'Radio versioned asset callbacks must reference the packaged asset files.'
);
radio_contract_require(
    strpos($publicJs, 'function initHomePlayer') !== false
        && strpos($adminJs, 'radio-upload-file') !== false
        && strlen($publicCss) > 100,
    'Radio CSS/JS asset files must contain the expected public/admin behavior.'
);

radio_contract_require(
    preg_match('/function\\s+plugin_getmenuitems_radio\\s*\\(/', $functions) === 1,
    'Radio must expose a public Geeklog plugin-menu callback.'
);
radio_contract_require(
    preg_match('/function\\s+plugin_getBlocks_radio\\s*\\(/', $functions) === 1
        && preg_match('/function\\s+plugin_getBlocksConfig_radio\\s*\\(/', $functions) === 1,
    'Radio must expose Geeklog dynamic block callbacks.'
);
radio_contract_require(
    strpos($functions, 'RADIO_renderBlock') !== false
        && strpos($functions, 'radio_now_playing') !== false
        && strpos($functions, 'RADIO_blockStylesheetLink') !== false,
    'Radio dynamic now-playing block and versioned block stylesheet must be present.'
);
radio_contract_require(
    strpos($defaults, "'block_enabled' => 0") !== false
        && strpos($defaults, "'block_isleft' => 0") !== false
        && strpos($defaults, "'block_order' => 50") !== false,
    'Radio dynamic block configuration defaults must be present.'
);
radio_contract_require(
    file_exists($root . '/public_html/radio-block.css'),
    'Radio dynamic block stylesheet must be packaged.'
);
if (!empty($errors)) {
    fwrite(STDERR, "Radio Plugin API contract check failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - " . $error . "\n");
    }
    exit(1);
}

echo "Radio Plugin API contract check passed.\n";

radio_contract_require(
    strpos(file_get_contents($root . '/public_html/schedule.php'), 'radio-schedule-grid') !== false
        && strpos(file_get_contents($root . '/public_html/schedule.php'), 'radio-day-card') !== false,
    'Radio public schedule must use the responsive card layout.'
);
radio_contract_require(
    strpos($publicCss, '.radio-week-nav') !== false
        && strpos($publicCss, '.radio-schedule-grid') !== false
        && strpos($publicCss, '.radio-day-card--today') !== false,
    'Radio public stylesheet must include modern schedule navigation and day-card styles.'
);
