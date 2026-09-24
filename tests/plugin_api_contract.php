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
$programPage = file_get_contents($root . '/public_html/program.php');
radio_contract_require(
    strpos($programPage, '<audio') === false
        && strpos($programPage, 'RADIO_getProgramItems') === false,
    'Public programme pages must stay editorial and must not expose programme media players.'
);
radio_contract_require(
    strpos($programPage, 'RADIO_getProgramUpcomingOccurrences') !== false
        && strpos($programPage, 'RADIO_getProgramReplayOccurrences') !== false,
    'Public programme pages must expose upcoming broadcasts and available replays.'
);
$radioJs = file_get_contents($root . '/public_html/radio.js');
$radioBlockJs = file_get_contents($root . '/public_html/radio-block.js');
radio_contract_require(
    strpos($radioJs, 'endedRetryCount') !== false
        && strpos($radioBlockJs, 'endedRetryCount') !== false,
    'Radio live players must retry synchronization across end-of-track rotation boundaries.'
);
radio_contract_require(
    strpos($radioJs, 'scheduleNextProgrammeTransition') !== false
        && strpos($radioJs, 'expectedProgramId') !== false
        && strpos($radioBlockJs, 'scheduleNextProgrammeTransition') !== false
        && strpos($radioBlockJs, 'expectedProgramId') !== false,
    'Active Radio listeners must hand off scheduled programmes from the beginning.'
);
$uploadAdmin = file_get_contents($root . '/admin/upload.php');
radio_contract_require(
    strpos($uploadAdmin, "ini_get('upload_max_filesize')") !== false
        && strpos($uploadAdmin, "ini_get('post_max_size')") !== false
        && strpos($uploadAdmin, "ini_get('max_file_uploads')") !== false,
    'Radio batch uploader must display the effective PHP upload limits.'
);
$editAdmin = file_get_contents($root . '/admin/edit.php');
$indexAdmin = file_get_contents($root . '/admin/index.php');
radio_contract_require(
    strpos($editAdmin, "/plugins/radio/index.php?updated=1") !== false
        && strpos($indexAdmin, 'isset($_GET[\'updated\'])') !== false,
    'Successful Radio media saves must redirect back to the media library with confirmation.'
);
$adminUi = file_get_contents($root . '/admin/admin-ui.inc.php');
$mediaTable = file_get_contents($root . '/templates/admin/media-list-table.thtml');
radio_contract_require(
    strpos($adminUi, 'return $LANG_RADIO[\'source_local\'];') !== false
        && strpos($adminUi, 'function RADIO_adminSortHeader') !== false
        && strpos($mediaTable, '{modified_header}') !== false,
    'Radio media library must identify local files correctly and expose sortable administrative columns.'
);
$mediaRow = file_get_contents($root . '/templates/admin/media-list-row.thtml');
radio_contract_require(
    strpos($adminUi, 'function RADIO_adminDisplayName') !== false
        && strpos($adminUi, 'rawurldecode') !== false
        && strpos($mediaRow, 'radio-admin__ellipsis') !== false
        && strpos($mediaRow, 'radio-admin__nowrap') !== false,
    'Radio media library must decode imported filenames and keep administrative columns compact.'
);
radio_contract_require(
    strpos($functions, 'function RADIO_validateExternalAudioSource') !== false
        && strpos($functions, 'function RADIO_externalAudioProbeRequest') !== false
        && strpos($functions, 'external_url_not_audio') !== false
        && strpos($functions, 'external_url_no_range') !== false
        && strpos($functions, 'RADIO_validateExternalAudioSource(') !== false,
    'Radio remote sources must be probed and validated before they are saved.'
);
$radioBlockCss = file_get_contents($root . '/public_html/radio-block.css');
radio_contract_require(
    strpos($radioBlockCss, '.radio-block__title') !== false
        && strpos($radioBlockCss, 'overflow-wrap: anywhere') !== false
        && strpos($radioBlockCss, 'word-break: break-word') !== false
        && strpos($radioBlockCss, 'max-width: 100%') !== false
        && strpos($radioBlockCss, 'min-width: 0') !== false,
    'Radio block must stay responsive with long unbroken titles in narrow theme sidebars.'
);
$playerPhp = file_get_contents($root . '/public_html/player.php');
$playerJs = file_get_contents($root . '/public_html/radio-player.js');
$publicIndex = file_get_contents($root . '/public_html/index.php');
radio_contract_require(
    strpos($playerPhp, 'data-radio-player') !== false
        && strpos($playerJs, "body.set('source', 'persistent')") !== false
        && strpos($playerJs, 'data-autoplay') !== false
        && strpos($publicIndex, 'data-radio-persistent-player') !== false
        && strpos($functions, "'persistent'") !== false
        && strpos($functions, '/radio/player.php?autoplay=1') !== false,
    'Radio must expose a persistent detached player that follows live state across site navigation.'
);
radio_contract_require(
    strpos($functions, 'function RADIO_cleanImportedTitle') !== false
        && strpos($functions, 'rawurldecode') !== false
        && strpos($functions, 'official video') !== false
        && strpos($functions, '[A-Za-z0-9_-]{11}') !== false
        && strpos($functions, 'RADIO_normalizeImportedMediaTitle') !== false,
    'Radio must clean auto-generated imported track titles without rewriting manual editorial titles.'
);
$publicPlayer = file_get_contents($root . '/public_html/radio.js');
$blockPlayer = file_get_contents($root . '/public_html/radio-block.js');
$persistentPlayer = file_get_contents($root . '/public_html/radio-player.js');
radio_contract_require(
    strpos($publicPlayer, 'fromEnded && nextId !== endedMediaId && nextProgram === programId') !== false
        && strpos($blockPlayer, 'fromEnded && nextMediaId !== endedMediaId && nextProgramId === programId') !== false
        && strpos($persistentPlayer, 'fromEnded && nextMediaId !== endedMediaId && nextProgramId === programId') !== false,
    'Radio players must start the next item at zero after a natural end within the same broadcast context.'
);

$defaults = file_get_contents($root . '/install_defaults.php');
$nowEndpoint = file_get_contents($root . '/public_html/now.php');
radio_contract_require(
    strpos($defaults, "'transition_mode' => 'gapless'") !== false
        && strpos($defaults, "'crossfade_seconds' => 2") !== false
        && strpos($functions, 'function RADIO_transitionOverlap') !== false
        && strpos($functions, "\$currentType === 'music' && \$nextType === 'music'") !== false
        && strpos($functions, "\$currentType === 'jingle' && \$nextType === 'music'") !== false
        && strpos($functions, "round(\$seconds / 2)") !== false
        && strpos($nowEndpoint, "'next_media'") !== false
        && strpos($nowEndpoint, "'transition_seconds'") !== false,
    'Radio must expose configurable gapless/crossfade transitions with full music-to-music overlap and shorter jingle-to-music overlap.'
);

$versionFile = file_get_contents($root . '/version.php');
$updatesFile = file_get_contents($root . '/install_updates.php');
radio_contract_require(
    strpos($versionFile, "RADIO_PLUGIN_VERSION', '0.5.1") !== false
        && strpos($updatesFile, "'0.3.0' => array(") !== false
        && strpos($updatesFile, "'next' => '0.3.1'") !== false
        && strpos($updatesFile, 'radio_update_0_3_0_to_0_3_1') !== false
        && strpos($updatesFile, "'0.3.1' => array(") !== false
        && strpos($updatesFile, "'next' => '0.3.2'") !== false
        && strpos($updatesFile, 'radio_update_0_3_1_to_0_3_2') !== false
        && strpos($updatesFile, "'0.3.2' => array(") !== false
        && strpos($updatesFile, "'next' => '0.4.0'") !== false
        && strpos($updatesFile, 'radio_update_0_3_2_to_0_4_0') !== false
        && strpos($updatesFile, "'0.4.0' => array(") !== false
        && strpos($updatesFile, "'next' => '0.5.0'") !== false
        && strpos($updatesFile, 'radio_update_0_4_0_to_0_5_0') !== false
        && strpos($updatesFile, "'0.5.0' => array(") !== false
        && strpos($updatesFile, "'next' => '0.5.1'") !== false
        && strpos($updatesFile, 'radio_update_0_5_0_to_0_5_1') !== false
        && strpos($functions, 'RADIO_ensureConfig()') !== false,
    'Radio 0.5.1 must preserve the existing upgrade chain and add shared-media metadata migration.'
);

$mysqlInstall = file_get_contents($root . '/sql/mysql_install.php');
$mediaEditTemplate = file_get_contents($root . '/templates/admin/media-edit.thtml');
$mediaUploadTemplate = file_get_contents($root . '/templates/admin/media-upload.thtml');
radio_contract_require(
    strpos($mysqlInstall, 'automatic_rotation tinyint(1) unsigned NOT NULL default \'1\'') !== false
        && strpos($functions, "RADIO_mediaAvailabilitySql('automatic_rotation', '')") !== false
        && strpos($functions, "['automatic_rotation']") !== false
        && strpos($functions, "\$reasons[] = 'automatic_rotation'") !== false
        && strpos($mediaEditTemplate, 'name="automatic_rotation"') !== false
        && strpos($mediaUploadTemplate, 'name="automatic_rotation"') !== false,
    'Radio 0.3.2 must let broadcast media opt out of automatic rotation while remaining usable in programmes.'
);

$mediaFilterTemplate = file_get_contents($root . '/templates/admin/media-filters.thtml');
radio_contract_require(
    strpos($mysqlInstall, 'category varchar(128)') !== false
        && strpos($mysqlInstall, 'collection_name varchar(255)') !== false
        && strpos($mysqlInstall, 'tags text') !== false
        && strpos($functions, 'function RADIO_normalizeTags') !== false
        && strpos($functions, 'function RADIO_mediaClassificationOptions') !== false
        && strpos($functions, 'function RADIO_mediaClassificationSchemaReady') !== false
        && strpos($functions, "FIND_IN_SET('") !== false
        && strpos($mediaEditTemplate, 'name="category"') !== false
        && strpos($mediaEditTemplate, 'name="collection_name"') !== false
        && strpos($mediaEditTemplate, 'name="tags"') !== false
        && strpos($mediaFilterTemplate, 'name="category"') !== false
        && strpos($mediaFilterTemplate, 'name="collection"') !== false
        && strpos($mediaFilterTemplate, 'name="tag"') !== false,
    'Radio 0.4.0 must provide category, collection and tag classification with combined media-library filters.'
);

$programAdmin = file_get_contents($root . '/admin/programs.php');
$rotationAdmin = file_get_contents($root . '/admin/rotation.php');
$studioPage = file_get_contents($root . '/admin/studio.php');
$studioApi = file_get_contents($root . '/admin/studio-api.php');
$studioJs = file_get_contents($root . '/admin/radio-studio.js');
$legacyPreview = file_get_contents($root . '/admin/preview.php');

radio_contract_require(
    strpos($programAdmin, 'RADIO_adminRenderProgramMediaPicker') === false
        && strpos($programAdmin, 'RADIO_addProgramItem') === false
        && strpos($programAdmin, 'RADIO_removeProgramItem') === false
        && strpos($programAdmin, 'RADIO_moveProgramItem') === false
        && strpos($programAdmin, '/plugins/radio/studio.php?program_id=') !== false,
    'Radio programme administration must stay metadata-only and link to the Studio for playlist editing.'
);

radio_contract_require(
    strpos($studioPage, "SEC_hasRights('radio.schedule')") !== false
        && strpos($studioPage, 'RADIO_getProgramItems') !== false
        && strpos($studioPage, 'RADIO_isBroadcastAvailable') !== false
        && strpos($studioPage, 'data-radio-replay-player') !== false
        && strpos($studioPage, 'data-radio-replay-chapter') !== false
        && strpos($studioPage, 'data-radio-studio-queue') !== false
        && strpos($studioPage, 'data-radio-studio-search') !== false
        && strpos($studioPage, "RADIO_mediaUrl((int) \$item['media_id'], false)") !== false,
    'Radio Studio must provide private continuous chaptered preview plus playlist search and queue editing.'
);

radio_contract_require(
    strpos($functions, '<script id="radio-public-js" defer src="') !== false
        && strpos($studioPage, '<script defer src="') !== false,
    'Radio public and Studio scripts must be deferred so player controls are bound after the DOM exists.'
);

radio_contract_require(
    strpos($studioApi, "if (\$studioAction === 'add')") !== false
        && strpos($studioApi, "\$afterItemId = \$position === 'next' ? \$currentItemId : 0") !== false
        && strpos($studioApi, 'RADIO_addProgramItem($programId, $mediaId, $afterItemId)') !== false
        && strpos($studioApi, "if (\$studioAction === 'remove')") !== false
        && strpos($studioApi, "if (\$studioAction === 'move_up' || \$studioAction === 'move_down')") !== false
        && strpos($studioApi, 'SEC_checkToken()') !== false
        && strpos($studioApi, "'csrf_token'") !== false
        && strpos($studioJs, "data.error === 'invalid_token'") !== false
        && strpos($studioJs, "body.set('studio_action', 'add')") !== false
        && strpos($studioJs, "mutateItem('remove'") !== false
        && strpos($studioJs, "mutateItem('move_up'") !== false
        && strpos($studioJs, "mutateItem('move_down'") !== false
        && strpos($studioJs, 'renderQueue(data.items || [])') !== false,
    'Radio Studio add, remove and reorder actions must persist through one CSRF-protected Studio API and immediately refresh the saved queue.'
);

radio_contract_require(
    strpos($functions, 'function RADIO_normalizeProgramItemOrder') !== false
        && strpos($functions, 'function RADIO_addProgramItem($programId, $mediaId, $afterItemId = 0)') !== false
        && strpos($studioApi, "if (\$action === 'state')") !== false
        && strpos($studioApi, "if (\$action === 'search')") !== false
        && strpos($studioJs, "body.set('current_item_id'") !== false
        && strpos($studioJs, "addMedia(item.media_id, 'next')") !== false
        && strpos($studioJs, "addMedia(item.media_id, 'end')") !== false
        && strpos($studioJs, "window.setInterval(syncState, 3000)") !== false
        && strpos($studioPage, 'data-radio-replay-dynamic=') !== false
        && strpos($studioPage, 'data-radio-replay-track="0"') !== false
        && strpos($publicPlayer, "root.addEventListener('radio:playlist-update'") !== false
        && strpos($publicPlayer, 'data-radio-current-item-id') !== false,
    'Radio Studio must keep live playlist state synchronized without reloading the current audio.'
);

radio_contract_require(
    strpos($legacyPreview, '/plugins/radio/studio.php') !== false
        && strpos($legacyPreview, "header('Location: ' . \$url, true, 302)") !== false,
    'Legacy preview.php URLs must redirect to the canonical Studio page.'
);

radio_contract_require(
    strpos($publicPlayer, 'queuePreload = new Audio()') !== false
        && strpos($publicPlayer, 'queueReserve = new Audio()') !== false
        && strpos($publicPlayer, "radio:buffer-status") !== false
        && strpos($studioPage, 'data-radio-studio-buffer') !== false
        && strpos($studioJs, "player.addEventListener('radio:buffer-status'") !== false,
    'Radio Studio must prebuffer N+1/N+2 and expose buffer readiness.'
);

radio_contract_require(
    strpos($functions, 'function RADIO_rebuildRotationNow') !== false
        && strpos($rotationAdmin, "name=\"rebuild_rotation\"") !== false
        && strpos($rotationAdmin, 'SEC_checkToken()') !== false
        && strpos($rotationAdmin, 'RADIO_rebuildRotationNow(time())') !== false,
    'Radio rotation administration must provide an explicit CSRF-protected immediate rebuild control.'
);

radio_contract_require(
    strpos($functions, 'function RADIO_rotationSnapshotSignature') !== false
        && strpos($functions, 'function RADIO_loadRotationSnapshot') !== false
        && strpos($functions, 'function RADIO_saveRotationSnapshot') !== false
        && strpos($functions, 'function RADIO_rotationCycleState') !== false
        && strpos($functions, 'function RADIO_rotationSnapshotItem') !== false
        && strpos($functions, 'function RADIO_transitionOverlapFor') !== false
        && strpos($functions, "'cycle_start'") !== false
        && strpos($functions, "'cycle_duration'") !== false
        && strpos($functions, "'transition_mode'") !== false
        && strpos($functions, "'crossfade_seconds'") !== false
        && strpos($functions, "'items' => \$items") !== false
        && strpos($functions, 'SELECT media_id,status') !== false
        && strpos($functions, 'RADIO_buildFreshRotationSequence') !== false,
    'Radio automatic rotation must freeze the active cycle and only let deletion or leaving published state affect it before the next cycle.'
);
$replayPage = file_get_contents($root . '/public_html/replay.php');
radio_contract_require(
    strpos($replayPage, 'data-radio-replay-player') !== false
        && strpos($replayPage, 'data-radio-replay-items') !== false
        && strpos($replayPage, 'data-radio-replay-progress') !== false
        && strpos($replayPage, 'data-radio-replay-chapter') !== false
        && strpos($publicPlayer, 'function initReplayPlayers') !== false
        && strpos($publicPlayer, "audio.addEventListener('ended'") !== false
        && strpos($publicPlayer, 'seekGlobal') !== false,
    'Radio replay must play a programme continuously with global progress and chapter navigation.'
);

radio_contract_require(
    strpos($functions, "if (count(\$jingles) > 0)") !== false
        && strpos($functions, "\$sequence[] = \$jingles[0]") !== false
        && strpos($functions, "\$jingleIndex = 1") !== false,
    'Every new Radio fallback rotation cycle must start with a jingle when one is available.'
);

radio_contract_require(
    strpos($functions, "RADIO_buildFreshRotationSequence(\$date, \$cycleStart)") !== false
        && strpos($functions, "microtime(true)") !== false
        && strpos($functions, "\$currentType === 'jingle' && \$nextType === 'music'") !== false
        && strpos($functions, "round(\$seconds / 2)") !== false
        && strpos($functions, "'next_next_media'") !== false
        && strpos($nowEndpoint, "'next_next_media'") !== false,
    'Each protected Radio rotation cycle must receive a fresh order, jingle-to-music must use a shorter crossfade, and live state must expose N+2.'
);

radio_contract_require(
    strpos($publicPlayer, "var reserve = new Audio()") !== false
        && strpos($blockPlayer, "var reserve = new Audio()") !== false
        && strpos($persistentPlayer, "var reserve = new Audio()") !== false
        && strpos($publicPlayer, 'bufferedAhead(standby)') !== false
        && strpos($blockPlayer, 'maybePrefetchReserve') !== false
        && strpos($persistentPlayer, 'next_next_media') !== false
        && strpos($publicPlayer, "mode === 'crossfade'") !== false
        && strpos($publicPlayer, "mode === 'gapless' || mode === 'crossfade'") !== false,
    'Radio live players must use adaptive N+1/N+2 buffering and fall back cleanly when a crossfade cannot start safely.'
);

radio_contract_require(
    strpos($publicPlayer, 'queuePreload = new Audio()') !== false
        && strpos($publicPlayer, 'queueReserve = new Audio()') !== false
        && strpos($publicPlayer, "radio:buffer-status") !== false
        && strpos($studioPage, 'data-radio-studio-buffer') !== false
        && strpos($studioJs, "player.addEventListener('radio:buffer-status'") !== false,
    'Radio programme preview and Studio must prebuffer N+1/N+2 and expose buffer readiness.'
);

radio_contract_require(
    strpos($publicPlayer, 'function createTransitionManager') !== false
        && strpos($blockPlayer, 'function createTransitionManager') !== false
        && strpos($persistentPlayer, 'function createTransitionManager') !== false
        && strpos($publicPlayer, "mode !== 'crossfade'") !== false
        && strpos($blockPlayer, "mode === 'gapless'") !== false
        && strpos($persistentPlayer, "standby.preload = 'auto'") !== false,
    'All Radio live players must preload and apply the configured transition mode.'
);
radio_contract_require(
    strpos($radioJs, 'syncSequence') === false
        && strpos($radioJs, 'intentVersion') === false,
    'Radio home player must not reference undeclared live-page synchronization state.'
);
radio_contract_require(
    strpos($functions, 'function RADIO_isDatabaseCurrent') !== false
        && strpos($functions, 'function plugin_collectSitemapItems_radio') !== false
        && strpos($functions, 'if (!RADIO_isDatabaseCurrent())') !== false,
    'Radio external integrations must stay inactive until the database upgrade is complete.'
);
$searchStart = strpos($functions, 'function plugin_dopluginsearch_radio');
$searchEnd = strpos($functions, 'function plugin_whatsnewsupported_radio', $searchStart);
$searchBlock = ($searchStart !== false && $searchEnd !== false)
    ? substr($functions, $searchStart, $searchEnd - $searchStart)
    : '';
radio_contract_require(
    $searchBlock !== ''
        && strpos($searchBlock, 'UNION ALL') === false
        && substr_count($searchBlock, 'new SearchCriteria') >= 2,
    'Radio search must use separate simple SearchCriteria queries for media and programmes.'
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

$id3Support = file_get_contents($root . '/lib/id3.inc.php');
$mediaEditAdmin = file_get_contents($root . '/admin/edit.php');

radio_contract_require(
    strpos($functions, 'function RADIO_libraryMode') !== false
        && strpos($functions, 'function RADIO_sharedMediaEnabled') !== false
        && strpos($functions, 'function RADIO_libraryInfo') !== false
        && strpos($functions, 'function RADIO_syncSharedMediaLibrary') !== false
        && strpos($functions, 'function RADIO_prepareSharedMediaUpdate') !== false
        && strpos($functions, 'function RADIO_writeMediaMetadataToFile') !== false
        && strpos($functions, 'function RADIO_syncMediaMetadataFromFile') !== false
        && strpos($defaults, "'library_mode' => 'local'") !== false
        && strpos($defaults, "'shared_storage_path' => ''") !== false
        && strpos($defaults, "'shared_media_sync_interval' => 300") !== false
        && strpos($mysqlInstall, 'metadata_mtime bigint(20) unsigned') !== false
        && strpos($mysqlInstall, 'shared_hidden tinyint(1) unsigned') !== false
        && strpos($id3Support, 'function RADIO_id3ReadTag') !== false
        && strpos($id3Support, 'function RADIO_id3WriteMetadata') !== false
        && strpos($mediaEditAdmin, "name=\"sync_metadata_from_file\"") !== false
        && strpos($mediaEditAdmin, "name=\"write_metadata_to_file\"") !== false,
    'Radio 0.5.1 shared-media mode must remain opt-in, keep site databases independent and synchronize common MP3 metadata through ID3 tags.'
);

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
