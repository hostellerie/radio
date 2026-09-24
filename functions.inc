<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}
global $_CONF, $_TABLES, $_DB_table_prefix, $_RADIO_CONF, $LANG_RADIO;
require_once __DIR__ . '/version.php';

$radioLanguage = isset($_CONF['language']) ? basename((string) $_CONF['language']) : 'english';
$radioLanguageFile = __DIR__ . '/language/' . $radioLanguage . '.php';
if (!is_file($radioLanguageFile)) {
    if (strpos($radioLanguage, 'french') === 0 && is_file(__DIR__ . '/language/french.php')) {
        $radioLanguageFile = __DIR__ . '/language/french.php';
    } else {
        $radioLanguageFile = __DIR__ . '/language/english.php';
    }
}
require_once $radioLanguageFile;

require_once __DIR__ . '/autoinstall.php';
require_once __DIR__ . '/lib/feed.inc.php';
require_once __DIR__ . '/lib/id3.inc.php';
require_once __DIR__ . '/services.inc.php';

$_TABLES['radio_media_local'] = $_DB_table_prefix . 'radio_media';
$_TABLES['radio_media'] = $_TABLES['radio_media_local'];
$_TABLES['radio_programs'] = $_DB_table_prefix . 'radio_programs';
$_TABLES['radio_program_items'] = $_DB_table_prefix . 'radio_program_items';
$_TABLES['radio_schedule'] = $_DB_table_prefix . 'radio_schedule';
$_TABLES['radio_events'] = $_DB_table_prefix . 'radio_events';
$_TABLES['radio_sources'] = $_DB_table_prefix . 'radio_sources';

function RADIO_isEnabled()
{
    global $_RADIO_CONF;
    return !isset($_RADIO_CONF['enabled']) || !empty($_RADIO_CONF['enabled']);
}

function RADIO_publicAccessAllowed()
{
    return RADIO_isEnabled()
        || SEC_hasRights('radio.admin,radio.upload,radio.schedule', 'OR');
}

function RADIO_requirePublicAccess($redirectToSite = false)
{
    global $_CONF;

    if (RADIO_publicAccessAllowed()) {
        return;
    }

    header('Cache-Control: no-store, max-age=0');

    if ($redirectToSite) {
        COM_redirect(rtrim($_CONF['site_url'], '/') . '/');
    }

    if (function_exists('http_response_code')) {
        http_response_code(403);
    } else {
        header('HTTP/1.1 403 Forbidden');
    }
    exit;
}

$_TABLES['radio_source_sync_log'] = $_DB_table_prefix . 'radio_source_sync_log';

if (!isset($_RADIO_CONF) || !is_array($_RADIO_CONF)) {
    $_RADIO_CONF = array();
    if (class_exists('config')) {
        $radioConfig = config::get_instance();
        if ($radioConfig->group_exists('radio')) {
            $_RADIO_CONF = $radioConfig->get_config('radio');
        }
    }
}

function RADIO_libraryMode()
{
    global $_RADIO_CONF;

    $mode = isset($_RADIO_CONF['library_mode'])
        ? trim((string) $_RADIO_CONF['library_mode'])
        : 'local';

    // 0.5.0 development aliases are intentionally folded into the simpler
    // 0.5.1 shared-media model. No cross-database credentials are required.
    if ($mode === 'shared_storage' || $mode === 'shared_library') {
        $mode = 'shared_media';
    }

    return in_array($mode, array('local','shared_media'), true) ? $mode : 'local';
}

function RADIO_sharedMediaEnabled()
{
    return RADIO_libraryMode() === 'shared_media';
}

function RADIO_sharedLibraryEnabled()
{
    // Compatibility shim for 0.5.0 development code. Shared catalogues were
    // replaced by shared files + embedded metadata in 0.5.1.
    return false;
}

function RADIO_libraryInfo()
{
    global $_TABLES;

    return array(
        'mode' => RADIO_libraryMode(),
        'shared_media' => RADIO_sharedMediaEnabled(),
        'media_table' => isset($_TABLES['radio_media_local']) ? $_TABLES['radio_media_local'] : '',
        'storage_path' => RADIO_storageDir(),
        'embedded_metadata' => 'mp3-id3v2'
    );
}



function RADIO_pathEndsWith($path, $suffix)
{
    $path = (string) $path;
    $suffix = (string) $suffix;

    if ($suffix === '') {
        return true;
    }

    if (strlen($suffix) > strlen($path)) {
        return false;
    }

    return substr($path, -strlen($suffix)) === $suffix;
}

function plugin_getfootercode_radio()
{
    $path = RADIO_requestPath();
    $footer = '';

    if (RADIO_isEnabled()) {
        if (RADIO_isPublicHtmlPage($path)) {
            $footer .= RADIO_publicScriptTag();
        }

        if (!empty($GLOBALS['_RADIO_NEEDS_BLOCK_JS'])) {
            $footer .= RADIO_blockScriptTag();
        }
    }

    if (RADIO_pathEndsWith($path, '/admin/plugins/radio/upload.php')
        || RADIO_pathEndsWith($path, '/admin/plugins/radio/edit.php')) {
        $footer .= RADIO_adminScriptTag();
    }

    return $footer;
}

function RADIO_statsEnabled()
{
    global $_RADIO_CONF;
    return !isset($_RADIO_CONF['stats_enabled']) || !empty($_RADIO_CONF['stats_enabled']);
}

function RADIO_statsRetentionDays()
{
    global $_RADIO_CONF;
    return isset($_RADIO_CONF['stats_retention_days'])
        ? max(1, min(3650, (int) $_RADIO_CONF['stats_retention_days']))
        : 90;
}

function RADIO_ensureStatsTable()
{
    global $_TABLES;

    $table = $_TABLES['radio_events'];
    $result = DB_query("SHOW TABLES LIKE '" . DB_escapeString($table) . "'");
    if (DB_numRows($result) > 0) {
        return true;
    }

    DB_query("CREATE TABLE " . $table . " (
      event_id bigint(20) unsigned NOT NULL auto_increment,
      media_id int(10) unsigned NOT NULL default '0',
      program_id int(10) unsigned NOT NULL default '0',
      event_type varchar(24) NOT NULL default 'play',
      source varchar(24) NOT NULL default 'catalogue',
      seconds_listened int(10) unsigned NOT NULL default '0',
      created datetime NOT NULL,
      PRIMARY KEY (event_id),
      KEY created (created),
      KEY media_event (media_id,event_type),
      KEY source_event (source,event_type)
    ) ENGINE=MyISAM");

    return !DB_error();
}

function RADIO_purgeOldStats()
{
    global $_TABLES;

    if (!RADIO_ensureStatsTable()) {
        return false;
    }

    $cutoff = date('Y-m-d H:i:s', strtotime('-' . RADIO_statsRetentionDays() . ' days'));
    DB_query(
        "DELETE FROM {$_TABLES['radio_events']} WHERE created<'"
        . DB_escapeString($cutoff) . "'"
    );
    return !DB_error();
}

function RADIO_recordStatEvent($mediaId, $programId, $eventType, $source, $seconds)
{
    global $_TABLES;

    if (!RADIO_statsEnabled() || !RADIO_ensureStatsTable()) {
        return false;
    }

    $allowedEvents = array('play','listen','download');
    $allowedSources = array('catalogue','program','replay','live','live-home','persistent','podcast','download');
    $eventType = in_array($eventType, $allowedEvents, true) ? $eventType : 'play';
    $source = in_array($source, $allowedSources, true) ? $source : 'catalogue';

    $mediaId = max(0, (int) $mediaId);
    $programId = max(0, (int) $programId);
    $seconds = max(0, min(86400, (int) $seconds));

    if ($mediaId < 1) {
        return false;
    }

    $media = RADIO_getMedia($mediaId, true);
    if ($media === false) {
        return false;
    }

    DB_query(
        "INSERT INTO {$_TABLES['radio_events']} "
        . "(media_id,program_id,event_type,source,seconds_listened,created) VALUES ("
        . $mediaId . ","
        . $programId . ","
        . "'" . DB_escapeString($eventType) . "',"
        . "'" . DB_escapeString($source) . "',"
        . $seconds . ","
        . "'" . DB_escapeString(date('Y-m-d H:i:s')) . "')"
    );

    if (!DB_error()) {
        RADIO_purgeOldStats();
        return true;
    }
    return false;
}

function RADIO_getStatsSummary($days)
{
    global $_TABLES;

    $days = max(1, min(RADIO_statsRetentionDays(), (int) $days));
    if (!RADIO_statsEnabled() || !RADIO_ensureStatsTable()) {
        return array(
            'days' => $days,
            'plays' => 0,
            'downloads' => 0,
            'listen_seconds' => 0,
            'top_media' => array()
        );
    }

    $since = DB_escapeString(date('Y-m-d H:i:s', strtotime('-' . $days . ' days')));

    $result = DB_query(
        "SELECT "
        . "SUM(event_type='play') AS plays,"
        . "SUM(event_type='download') AS downloads,"
        . "SUM(CASE WHEN event_type='listen' THEN seconds_listened ELSE 0 END) AS listen_seconds "
        . "FROM {$_TABLES['radio_events']} WHERE created>='" . $since . "'"
    );
    $row = DB_fetchArray($result);

    $top = array();
    $topResult = DB_query(
        "SELECT e.media_id,m.title,COUNT(*) AS plays "
        . "FROM {$_TABLES['radio_events']} e "
        . "INNER JOIN {$_TABLES['radio_media']} m ON m.media_id=e.media_id "
        . "WHERE e.created>='" . $since . "' AND e.event_type='play' "
        . "GROUP BY e.media_id,m.title ORDER BY plays DESC,e.media_id DESC LIMIT 10"
    );
    while ($item = DB_fetchArray($topResult)) {
        $media = RADIO_getMedia((int) $item['media_id'], true);
        if ($media === false) {
            continue;
        }
        $top[] = array(
            'media_id' => RADIO_externalId('media', (int) $item['media_id']),
            'title' => $item['title'],
            'plays' => (int) $item['plays'],
            'url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $item['media_id']))
        );
    }

    return array(
        'days' => $days,
        'plays' => isset($row['plays']) ? (int) $row['plays'] : 0,
        'downloads' => isset($row['downloads']) ? (int) $row['downloads'] : 0,
        'listen_seconds' => isset($row['listen_seconds']) ? (int) $row['listen_seconds'] : 0,
        'top_media' => $top
    );
}

function RADIO_defaultGroupId()
{
    global $_TABLES;
    $id = (int) DB_getItem($_TABLES['groups'], 'grp_id', "grp_name='Radio Admin'");
    return $id > 0 ? $id : 1;
}

function RADIO_permissionValues($data, $existing)
{
    $owner = isset($data['perm_owner']) ? $data['perm_owner'] : (isset($existing['perm_owner']) ? $existing['perm_owner'] : 3);
    $group = isset($data['perm_group']) ? $data['perm_group'] : (isset($existing['perm_group']) ? $existing['perm_group'] : 2);
    $members = isset($data['perm_members']) ? $data['perm_members'] : (isset($existing['perm_members']) ? $existing['perm_members'] : 2);
    $anon = isset($data['perm_anon']) ? $data['perm_anon'] : (isset($existing['perm_anon']) ? $existing['perm_anon'] : 2);

    if (is_array($owner) || is_array($group) || is_array($members) || is_array($anon)) {
        list($owner, $group, $members, $anon) = SEC_getPermissionValues($owner, $group, $members, $anon);
    }

    return array(
        max(0, min(3, (int) $owner)),
        max(0, min(3, (int) $group)),
        max(0, min(3, (int) $members)),
        max(0, min(3, (int) $anon))
    );
}

function RADIO_hasReadAccess($row)
{
    if (!is_array($row)) {
        return false;
    }
    return SEC_hasAccess(
        isset($row['owner_id']) ? (int) $row['owner_id'] : 0,
        isset($row['group_id']) ? (int) $row['group_id'] : 0,
        isset($row['perm_owner']) ? (int) $row['perm_owner'] : 0,
        isset($row['perm_group']) ? (int) $row['perm_group'] : 0,
        isset($row['perm_members']) ? (int) $row['perm_members'] : 0,
        isset($row['perm_anon']) ? (int) $row['perm_anon'] : 0
    ) >= 2;
}

function RADIO_hasEditAccess($row)
{
    if (SEC_hasRights('radio.admin')) {
        return true;
    }
    return is_array($row) && SEC_hasAccess(
        (int) $row['owner_id'],
        (int) $row['group_id'],
        (int) $row['perm_owner'],
        (int) $row['perm_group'],
        (int) $row['perm_members'],
        (int) $row['perm_anon']
    ) >= 3;
}

function RADIO_permissionSql($alias, $uid)
{
    $alias = trim((string) $alias);
    if ($alias === '' || $alias === 'radio_media' || $alias === 'radio_programs' || $alias === 'radio_sources') {
        return COM_getPermSQL('AND', (int) $uid, 2);
    }
    return COM_getPermSQL('AND', (int) $uid, 2, $alias);
}

function RADIO_externalId($type, $id)
{
    $type = $type === 'program' ? 'program' : 'media';
    return $type . ':' . (int) $id;
}

function RADIO_parseExternalId($id)
{
    if (is_string($id) && preg_match('/^(media|program):(\\d+)$/', $id, $m)) {
        return array('type' => $m[1], 'id' => (int) $m[2]);
    }
    if (is_numeric($id)) {
        return array('type' => 'media', 'id' => (int) $id);
    }
    return false;
}

function RADIO_storageDir()
{
    global $_CONF, $_RADIO_CONF;

    if (RADIO_sharedMediaEnabled()) {
        $shared = isset($_RADIO_CONF['shared_storage_path'])
            ? rtrim(trim((string) $_RADIO_CONF['shared_storage_path']), "/\\")
            : '';
        if ($shared !== '' && strpos($shared, "\0") === false) {
            return $shared . DIRECTORY_SEPARATOR;
        }
    }

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

function RADIO_coverDir()
{
    $base = RADIO_storageDir();
    return $base === '' ? '' : $base . 'covers' . DIRECTORY_SEPARATOR;
}

function RADIO_ensureCoverStorage()
{
    if (!RADIO_ensureStorage()) {
        return false;
    }
    $dir = RADIO_coverDir();
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        COM_errorLog('Radio: cannot create cover storage directory ' . $dir, 1);
        return false;
    }
    return is_writable($dir);
}

function RADIO_allowedImages()
{
    return array('jpg'=>array('image/jpeg'),'jpeg'=>array('image/jpeg'),'png'=>array('image/png'),'webp'=>array('image/webp'));
}

function RADIO_saveCoverUpload($file, &$error)
{
    $error = '';
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        $error = 'cover_upload_error';
        return false;
    }
    if (!isset($file['size']) || (int)$file['size'] < 1 || (int)$file['size'] > 5242880) {
        $error = 'cover_upload_size';
        return false;
    }
    $name = isset($file['name']) ? basename((string)$file['name']) : '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = RADIO_allowedImages();
    if (!isset($allowed[$ext])) {
        $error = 'cover_upload_type';
        return false;
    }
    $tmp = isset($file['tmp_name']) ? $file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $error = 'cover_upload_error';
        return false;
    }
    $mime = RADIO_detectMime($tmp);
    if ($mime === '' || !in_array($mime, $allowed[$ext], true)) {
        $error = 'cover_upload_type';
        return false;
    }
    $validImage = false;
    if (function_exists('getimagesize')) {
        $imageInfo = @getimagesize($tmp);
        $validImage = is_array($imageInfo);
    }
    if (!$validImage && $ext === 'webp') {
        $handle = @fopen($tmp, 'rb');
        $head = $handle ? fread($handle, 12) : '';
        if ($handle) fclose($handle);
        $validImage = is_string($head) && strlen($head) >= 12
            && substr($head, 0, 4) === 'RIFF'
            && substr($head, 8, 4) === 'WEBP';
    }
    if (!$validImage) {
        $error = 'cover_upload_type';
        return false;
    }
    if (!RADIO_ensureCoverStorage()) {
        $error = 'storage_unavailable';
        return false;
    }
    $storageName = gmdate('YmdHis') . '-' . sha1(uniqid('', true) . mt_rand()) . '.' . $ext;
    if (!@move_uploaded_file($tmp, RADIO_coverDir() . $storageName)) {
        $error = 'cover_upload_move';
        return false;
    }
    return $storageName;
}

function RADIO_deleteCover($name)
{
    $name = basename((string)$name);
    if ($name === '') return true;
    $path = RADIO_coverDir() . $name;
    return !is_file($path) || @unlink($path);
}

function RADIO_coverUrl($type, $id)
{
    global $_CONF;
    $type = $type === 'program' ? 'program' : 'media';
    return $_CONF['site_url'] . '/radio/cover.php?type=' . $type . '&id=' . (int)$id;
}

function RADIO_sendCover($type, $id)
{
    $row = $type === 'program' ? RADIO_getProgram((int)$id, true) : RADIO_getMedia((int)$id, true);
    if ($row === false || empty($row['cover_name'])) return false;
    $path = RADIO_coverDir() . basename($row['cover_name']);
    if (!is_file($path) || !is_readable($path)) return false;
    $mime = RADIO_detectMime($path);
    if ($mime === '' || strpos($mime, 'image/') !== 0) $mime = 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}


function RADIO_externalUrlValidation($url, &$error)
{
    $error = '';
    $url = trim((string) $url);
    if ($url === '' || strlen($url) > 2048) {
        $error = 'external_url_invalid';
        return false;
    }

    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass'])) {
        $error = 'external_url_invalid';
        return false;
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        $error = 'external_url_scheme';
        return false;
    }

    $host = strtolower(rtrim($parts['host'], '.'));
    if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local') {
        $error = 'external_url_private';
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $error = 'external_url_private';
            return false;
        }
    } else {
        $addresses = @gethostbynamel($host);
        if (!is_array($addresses) || count($addresses) === 0) {
            $error = 'external_url_unresolved';
            return false;
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $error = 'external_url_private';
                return false;
            }
        }
    }

    if (isset($parts['port'])) {
        $port = (int) $parts['port'];
        if ($port < 1 || $port > 65535) {
            $error = 'external_url_invalid';
            return false;
        }
    }

    return $url;
}

function RADIO_externalAudioMimeAccepted($contentType, $url)
{
    $contentType = strtolower(trim((string) $contentType));
    if (($semicolon = strpos($contentType, ';')) !== false) {
        $contentType = trim(substr($contentType, 0, $semicolon));
    }

    if (strpos($contentType, 'audio/') === 0 || $contentType === 'application/ogg') {
        return true;
    }

    if ($contentType === 'application/octet-stream') {
        $path = parse_url((string) $url, PHP_URL_PATH);
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        return isset(RADIO_allowedAudio()[$extension]);
    }

    return false;
}

function RADIO_externalAudioProbeRequest($url, $range, &$result)
{
    $result = array(
        'status' => 0,
        'content_type' => '',
        'location' => '',
        'accept_ranges' => '',
        'content_range' => ''
    );

    if (!function_exists('curl_init')) {
        return false;
    }

    $headers = array();
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Geeklog Radio/0.3');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$headers) {
        $length = strlen($line);
        $line = trim($line);
        if ($line === '' || strpos($line, ':') === false) {
            return $length;
        }
        list($name, $value) = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
        return $length;
    });
    if ($range !== '') {
        curl_setopt($ch, CURLOPT_RANGE, $range);
    } else {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    $body = curl_exec($ch);
    if ($body === false) {
        curl_close($ch);
        return false;
    }

    $result['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result['content_type'] = isset($headers['content-type'])
        ? $headers['content-type']
        : (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $result['location'] = isset($headers['location']) ? $headers['location'] : '';
    $result['accept_ranges'] = isset($headers['accept-ranges']) ? strtolower($headers['accept-ranges']) : '';
    $result['content_range'] = isset($headers['content-range']) ? strtolower($headers['content-range']) : '';
    curl_close($ch);

    return true;
}

function RADIO_externalRedirectUrl($baseUrl, $location)
{
    $location = trim((string) $location);
    if ($location === '') {
        return false;
    }
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }
    if (substr($location, 0, 2) === '//') {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        return $scheme . ':' . $location;
    }

    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    $host = parse_url($baseUrl, PHP_URL_HOST);
    $port = parse_url($baseUrl, PHP_URL_PORT);
    if (!$scheme || !$host) {
        return false;
    }
    $authority = $scheme . '://' . $host . ($port ? ':' . (int) $port : '');
    if (substr($location, 0, 1) === '/') {
        return $authority . $location;
    }

    $path = parse_url($baseUrl, PHP_URL_PATH);
    $dir = rtrim(str_replace('\\', '/', dirname($path ? $path : '/')), '/');
    return $authority . ($dir !== '' ? $dir : '') . '/' . $location;
}

function RADIO_validateExternalAudioSource($url, $sourceKind, $requireRange, &$error)
{
    $error = '';
    if (!function_exists('curl_init')) {
        $error = 'http_transport_unavailable';
        return false;
    }

    $current = $url;
    for ($redirects = 0; $redirects <= 5; $redirects++) {
        $validated = RADIO_externalUrlValidation($current, $error);
        if ($validated === false) {
            return false;
        }

        $probe = array();
        if (!RADIO_externalAudioProbeRequest($validated, '', $probe)) {
            $error = 'external_url_unreachable';
            return false;
        }

        if ($probe['status'] >= 300 && $probe['status'] < 400 && $probe['location'] !== '') {
            if ($redirects === 5) {
                $error = 'too_many_redirects';
                return false;
            }
            $next = RADIO_externalRedirectUrl($validated, $probe['location']);
            if ($next === false) {
                $error = 'invalid_redirect';
                return false;
            }
            $current = $next;
            continue;
        }

        if ($probe['status'] < 200 || $probe['status'] >= 300) {
            $error = 'external_url_http_error';
            return false;
        }

        if (!RADIO_externalAudioMimeAccepted($probe['content_type'], $validated)) {
            // Some audio servers do not implement HEAD correctly. Retry with a one-byte range GET.
            $rangeProbe = array();
            if (!RADIO_externalAudioProbeRequest($validated, '0-0', $rangeProbe)
                || ($rangeProbe['status'] !== 200 && $rangeProbe['status'] !== 206)
                || !RADIO_externalAudioMimeAccepted($rangeProbe['content_type'], $validated)) {
                $error = 'external_url_not_audio';
                return false;
            }
            $probe = $rangeProbe;
        }

        if ($sourceKind !== 'live' && $requireRange) {
            $rangeProbe = array();
            if (!RADIO_externalAudioProbeRequest($validated, '0-0', $rangeProbe)
                || $rangeProbe['status'] !== 206
                || strpos($rangeProbe['content_range'], 'bytes ') !== 0) {
                $error = 'external_url_no_range';
                return false;
            }
        }

        return $validated;
    }

    $error = 'too_many_redirects';
    return false;
}

function RADIO_sourceKind($row)
{
    if (!is_array($row) || empty($row['source_kind'])) {
        return 'local';
    }
    return in_array($row['source_kind'], array('local','external','live'), true)
        ? $row['source_kind']
        : 'local';
}

function RADIO_saveExternalMedia($metadata, &$error)
{
    global $_TABLES, $_USER;

    $error = '';
    $sourceKind = isset($metadata['source_kind']) ? (string) $metadata['source_kind'] : 'external';
    if (!in_array($sourceKind, array('external','live'), true)) {
        $sourceKind = 'external';
    }

    $sourceUrl = RADIO_externalUrlValidation(
        isset($metadata['source_url']) ? $metadata['source_url'] : '',
        $error
    );
    if ($sourceUrl === false) {
        return false;
    }

    $requireRange = $sourceKind !== 'live'
        && (!isset($metadata['broadcast']) || !empty($metadata['broadcast']));
    $sourceUrl = RADIO_validateExternalAudioSource(
        $sourceUrl,
        $sourceKind,
        $requireRange,
        $error
    );
    if ($sourceUrl === false) {
        return false;
    }

    $title = trim(isset($metadata['title']) ? (string) $metadata['title'] : '');
    if ($title === '') {
        $error = 'external_title_required';
        return false;
    }

    $mediaType = isset($metadata['media_type']) ? $metadata['media_type'] : ($sourceKind === 'live' ? 'show' : 'music');
    $allowedTypes = array('music','podcast','interview','show','chronicle','jingle','announcement','promo');
    if (!in_array($mediaType, $allowedTypes, true)) {
        $mediaType = $sourceKind === 'live' ? 'show' : 'music';
    }

    $category = RADIO_normalizeClassificationValue(isset($metadata['category']) ? $metadata['category'] : '', 128);
    $collectionName = RADIO_normalizeClassificationValue(isset($metadata['collection_name']) ? $metadata['collection_name'] : '', 255);
    $tags = RADIO_normalizeTags(isset($metadata['tags']) ? $metadata['tags'] : '');
    $status = isset($metadata['status']) && $metadata['status'] === 'published' ? 'published' : 'draft';
    $onDemand = isset($metadata['on_demand']) ? (!empty($metadata['on_demand']) ? 1 : 0) : 1;
    $broadcast = isset($metadata['broadcast']) ? (!empty($metadata['broadcast']) ? 1 : 0) : 1;
    $automaticRotation = isset($metadata['automatic_rotation']) ? (!empty($metadata['automatic_rotation']) ? 1 : 0) : 1;
    $duration = $sourceKind === 'live' ? 0 : max(0, (int) (isset($metadata['duration']) ? $metadata['duration'] : 0));
    $owner = isset($_USER['uid']) ? (int) $_USER['uid'] : 2;
    $groupId = isset($metadata['group_id']) ? max(1, (int) $metadata['group_id']) : RADIO_defaultGroupId();
    list($permOwner, $permGroup, $permMembers, $permAnon) = RADIO_permissionValues($metadata, array());
    $now = date('Y-m-d H:i:s');

    DB_query(
        "INSERT INTO {$_TABLES['radio_media']} "
        . "(title,description,media_type,author,series_title,category,collection_name,tags,season_number,episode_number,cover_name,"
        . "source_kind,source_url,source_provider,source_external_id,source_attribution,source_license,"
        . "storage_name,original_name,mime_type,duration,file_size,status,on_demand,broadcast,automatic_rotation,allow_download,hits,owner_id,"
        . "group_id,perm_owner,perm_group,perm_members,perm_anon,created,modified) VALUES ("
        . "'" . DB_escapeString(substr($title,0,255)) . "',"
        . "'" . DB_escapeString(isset($metadata['description']) ? trim((string)$metadata['description']) : '') . "',"
        . "'" . DB_escapeString($mediaType) . "',"
        . "'" . DB_escapeString(substr(trim(isset($metadata['author']) ? (string)$metadata['author'] : ''),0,255)) . "',"
        . "'" . DB_escapeString(substr(trim(isset($metadata['series_title']) ? (string)$metadata['series_title'] : ''),0,255)) . "',"
        . "'" . DB_escapeString($category) . "',"
        . "'" . DB_escapeString($collectionName) . "',"
        . "'" . DB_escapeString($tags) . "',"
        . max(0,(int)(isset($metadata['season_number']) ? $metadata['season_number'] : 0)) . ","
        . max(0,(int)(isset($metadata['episode_number']) ? $metadata['episode_number'] : 0)) . ","
        . "'" . DB_escapeString(isset($metadata['cover_name']) ? basename((string)$metadata['cover_name']) : '') . "',"
        . "'" . DB_escapeString($sourceKind) . "',"
        . "'" . DB_escapeString($sourceUrl) . "',"
        . "'" . DB_escapeString(substr(trim(isset($metadata['source_provider']) ? (string)$metadata['source_provider'] : ''),0,255)) . "',"
        . "'" . DB_escapeString(substr(trim(isset($metadata['source_external_id']) ? (string)$metadata['source_external_id'] : ''),0,255)) . "',"
        . "'" . DB_escapeString(isset($metadata['source_attribution']) ? trim((string)$metadata['source_attribution']) : '') . "',"
        . "'" . DB_escapeString(substr(trim(isset($metadata['source_license']) ? (string)$metadata['source_license'] : ''),0,255)) . "',"
        . "'','',''," . $duration . ",0,"
        . "'" . DB_escapeString($status) . "'," . $onDemand . "," . $broadcast . "," . $automaticRotation . ",0,0,"
        . $owner . "," . $groupId . "," . $permOwner . "," . $permGroup . "," . $permMembers . "," . $permAnon . ","
        . "'" . DB_escapeString($now) . "','" . DB_escapeString($now) . "')"
    );

    if (DB_error()) {
        $error = 'database_error';
        return false;
    }

    $id = (int) DB_insertId();

    if (RADIO_sharedMediaEnabled() && $validated['extension'] === 'mp3') {
        $sharedRow = array(
            'media_id' => $id,
            'storage_name' => $storageName,
            'original_name' => $validated['original_name'],
            'source_kind' => 'local',
            'title' => stripslashes($title),
            'description' => stripslashes($description),
            'author' => stripslashes($author),
            'series_title' => stripslashes($seriesTitle),
            'category' => stripslashes($category),
            'collection_name' => stripslashes($collectionName),
            'tags' => stripslashes($tags),
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
            'media_type' => $mediaType
        );
        $tagError = '';
        if (RADIO_writeMediaMetadataToFile($sharedRow, $sharedRow, $tagError)) {
            clearstatcache(true, $destination);
            DB_query(
                "UPDATE {$_TABLES['radio_media']} SET metadata_mtime="
                . max(0, (int) @filemtime($destination))
                . " WHERE media_id=" . $id
            );
        } else {
            COM_errorLog('Radio: MP3 saved but shared ID3 metadata could not be written: ' . $tagError, 1);
        }
    }

    if (function_exists('PLG_itemSaved')) {
        PLG_itemSaved(RADIO_externalId('media', $id), 'radio');
    }
    return $id;
}

function RADIO_allowedAudio()
{
    return array(
        'mp3' => array('audio/mpeg', 'audio/mp3'),
        'm4a' => array('audio/mp4', 'audio/x-m4a', 'video/mp4'),
        'aac' => array('audio/aac', 'audio/x-aac'),
        'ogg' => array('audio/ogg', 'application/ogg'),
        'wav' => array('audio/wav', 'audio/x-wav', 'audio/wave')
    );
}

function RADIO_detectMime($path)
{
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return strtolower(trim($mime));
            }
        }
    }
    return '';
}

function RADIO_audioSignatureMatches($path, $extension)
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }
    $head = fread($handle, 16);
    fclose($handle);
    if (!is_string($head) || strlen($head) < 4) {
        return false;
    }

    if ($extension === 'mp3') {
        return substr($head, 0, 3) === 'ID3'
            || (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0);
    }
    if ($extension === 'm4a') {
        return strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp';
    }
    if ($extension === 'aac') {
        return strlen($head) >= 2
            && ord($head[0]) === 0xFF
            && (ord($head[1]) & 0xF6) === 0xF0;
    }
    if ($extension === 'ogg') {
        return substr($head, 0, 4) === 'OggS';
    }
    if ($extension === 'wav') {
        return strlen($head) >= 12
            && substr($head, 0, 4) === 'RIFF'
            && substr($head, 8, 4) === 'WAVE';
    }
    return false;
}

function RADIO_validateUpload($file, &$error)
{
    global $_RADIO_CONF;

    $error = '';
    if (!is_array($file) || !isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        $error = 'upload_error';
        return false;
    }

    $maxMb = isset($_RADIO_CONF['max_upload_mb']) ? max(1, (int) $_RADIO_CONF['max_upload_mb']) : 100;
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size < 1 || $size > ($maxMb * 1024 * 1024)) {
        $error = 'upload_size';
        return false;
    }

    $name = isset($file['name']) ? basename((string) $file['name']) : '';
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = RADIO_allowedAudio();
    if ($extension === '' || !isset($allowed[$extension])) {
        $error = 'upload_type';
        return false;
    }

    $tmp = isset($file['tmp_name']) ? $file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $error = 'upload_error';
        return false;
    }

    $mime = RADIO_detectMime($tmp);
    if ($mime === '' || !in_array($mime, $allowed[$extension], true)
        || !RADIO_audioSignatureMatches($tmp, $extension)) {
        $error = 'upload_type';
        return false;
    }

    return array(
        'extension' => $extension,
        'mime' => $mime,
        'size' => $size,
        'original_name' => $name
    );
}


function RADIO_readUint32($data)
{
    if (!is_string($data) || strlen($data) !== 4) {
        return 0;
    }
    $value = unpack('Nvalue', $data);
    return isset($value['value']) ? (int) $value['value'] : 0;
}

function RADIO_detectM4aDuration($path)
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return 0;
    }

    $size = @filesize($path);
    if (!$size || $size < 16) {
        fclose($handle);
        return 0;
    }

    $stack = array(array(0, (int) $size));
    while (!empty($stack)) {
        $range = array_pop($stack);
        $offset = (int) $range[0];
        $limit = (int) $range[1];

        while (($offset + 8) <= $limit) {
            if (fseek($handle, $offset) !== 0) {
                break;
            }

            $header = fread($handle, 8);
            if (!is_string($header) || strlen($header) !== 8) {
                break;
            }

            $atomSize = RADIO_readUint32(substr($header, 0, 4));
            $atomType = substr($header, 4, 4);
            $headerSize = 8;

            if ($atomSize === 1) {
                $extended = fread($handle, 8);
                if (!is_string($extended) || strlen($extended) !== 8) {
                    break;
                }
                $parts = unpack('Nhigh/Nlow', $extended);
                if (!isset($parts['high'], $parts['low']) || (int) $parts['high'] !== 0) {
                    break;
                }
                $atomSize = (int) $parts['low'];
                $headerSize = 16;
            } elseif ($atomSize === 0) {
                $atomSize = $limit - $offset;
            }

            if ($atomSize < $headerSize || ($offset + $atomSize) > $limit) {
                break;
            }

            if ($atomType === 'moov') {
                $stack[] = array(
                    $offset + $headerSize,
                    $offset + $atomSize
                );
            } elseif ($atomType === 'mvhd') {
                if (fseek($handle, $offset + $headerSize) !== 0) {
                    break;
                }
                $versionAndFlags = fread($handle, 4);
                if (!is_string($versionAndFlags) || strlen($versionAndFlags) !== 4) {
                    break;
                }
                $version = ord($versionAndFlags[0]);

                if ($version === 0) {
                    $payload = fread($handle, 16);
                    if (!is_string($payload) || strlen($payload) !== 16) {
                        break;
                    }
                    $timescale = RADIO_readUint32(substr($payload, 8, 4));
                    $duration = RADIO_readUint32(substr($payload, 12, 4));
                    fclose($handle);

                    if ($timescale > 0 && $duration > 0) {
                        return max(1, (int) round($duration / $timescale));
                    }
                    return 0;
                }

                // Version 1 uses 64-bit fields. Fail closed rather than
                // depending on platform-specific 64-bit unpack behaviour.
                fclose($handle);
                return 0;
            }

            $offset += $atomSize;
        }
    }

    fclose($handle);
    return 0;
}

function RADIO_mp3BitrateKbps($versionBits, $layerBits, $bitrateIndex)
{
    if ($bitrateIndex < 1 || $bitrateIndex > 14 || $layerBits !== 1) {
        return 0;
    }

    // Layer III tables. versionBits 3 = MPEG1, 2/0 = MPEG2/2.5.
    $mpeg1 = array(0,32,40,48,56,64,80,96,112,128,160,192,224,256,320);
    $mpeg2 = array(0,8,16,24,32,40,48,56,64,80,96,112,128,144,160);

    return $versionBits === 3
        ? (int) $mpeg1[$bitrateIndex]
        : (int) $mpeg2[$bitrateIndex];
}

function RADIO_detectMp3Duration($path)
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return 0;
    }

    $size = @filesize($path);
    if (!$size || $size < 4) {
        fclose($handle);
        return 0;
    }

    $start = 0;
    $head = fread($handle, 10);
    if (is_string($head) && strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
        $tagSize = ((ord($head[6]) & 0x7f) << 21)
            | ((ord($head[7]) & 0x7f) << 14)
            | ((ord($head[8]) & 0x7f) << 7)
            | (ord($head[9]) & 0x7f);
        $start = 10 + $tagSize;
    }

    if (fseek($handle, $start) !== 0) {
        fclose($handle);
        return 0;
    }

    $scanLength = min(131072, max(0, $size - $start));
    $data = fread($handle, $scanLength);
    fclose($handle);

    if (!is_string($data) || strlen($data) < 4) {
        return 0;
    }

    $length = strlen($data);
    for ($i = 0; $i <= ($length - 4); $i++) {
        $b1 = ord($data[$i]);
        $b2 = ord($data[$i + 1]);
        $b3 = ord($data[$i + 2]);

        if ($b1 !== 0xff || ($b2 & 0xe0) !== 0xe0) {
            continue;
        }

        $versionBits = ($b2 >> 3) & 0x03;
        $layerBits = ($b2 >> 1) & 0x03;
        $bitrateIndex = ($b3 >> 4) & 0x0f;

        if ($versionBits === 1 || $layerBits !== 1) {
            continue;
        }

        $kbps = RADIO_mp3BitrateKbps($versionBits, $layerBits, $bitrateIndex);
        if ($kbps < 1) {
            continue;
        }

        $audioBytes = max(0, $size - $start);
        $seconds = (int) round(($audioBytes * 8) / ($kbps * 1000));
        return max(1, $seconds);
    }

    return 0;
}

function RADIO_detectAudioDuration($path, $extension)
{
    $extension = strtolower((string) $extension);

    if ($extension === 'm4a' || $extension === 'mp4') {
        return RADIO_detectM4aDuration($path);
    }

    if ($extension === 'mp3') {
        return RADIO_detectMp3Duration($path);
    }

    return 0;
}

function RADIO_generateStorageName($extension)
{
    $entropy = uniqid('', true) . mt_rand() . microtime(true);
    return gmdate('YmdHis') . '-' . sha1($entropy) . '.' . $extension;
}

function RADIO_saveUpload($file, $metadata, &$error)
{
    global $_TABLES, $_USER;

    $validated = RADIO_validateUpload($file, $error);
    if ($validated === false) {
        return false;
    }
    if (!RADIO_ensureStorage()) {
        $error = 'storage_unavailable';
        return false;
    }

    $storageName = RADIO_generateStorageName($validated['extension']);
    $destination = RADIO_storageDir() . $storageName;
    if (!@move_uploaded_file($file['tmp_name'], $destination)) {
        $error = 'upload_move';
        return false;
    }
    @chmod($destination, 0644);

    $title = isset($metadata['title']) ? trim((string) $metadata['title']) : '';
    if ($title === '') {
        $title = RADIO_cleanImportedTitle($validated['original_name']);
    }
    $title = DB_escapeString(substr($title, 0, 255));
    $description = DB_escapeString(isset($metadata['description']) ? trim((string) $metadata['description']) : '');
    $author = DB_escapeString(substr(trim(isset($metadata['author']) ? (string)$metadata['author'] : ''),0,255));
    $seriesTitle = DB_escapeString(substr(trim(isset($metadata['series_title']) ? (string)$metadata['series_title'] : ''),0,255));
    $category = DB_escapeString(RADIO_normalizeClassificationValue(isset($metadata['category']) ? $metadata['category'] : '', 128));
    $collectionName = DB_escapeString(RADIO_normalizeClassificationValue(isset($metadata['collection_name']) ? $metadata['collection_name'] : '', 255));
    $tags = DB_escapeString(RADIO_normalizeTags(isset($metadata['tags']) ? $metadata['tags'] : ''));
    $seasonNumber = isset($metadata['season_number']) ? max(0,(int)$metadata['season_number']) : 0;
    $episodeNumber = isset($metadata['episode_number']) ? max(0,(int)$metadata['episode_number']) : 0;
    $coverName = isset($metadata['cover_name']) ? basename((string)$metadata['cover_name']) : '';
    $mediaType = isset($metadata['media_type']) ? $metadata['media_type'] : 'music';
    $allowedTypes = array('music', 'podcast', 'interview', 'show', 'chronicle', 'jingle', 'announcement', 'promo');
    if (!in_array($mediaType, $allowedTypes, true)) {
        $mediaType = 'music';
    }
    $status = isset($metadata['status']) && $metadata['status'] === 'published' ? 'published' : 'draft';
    $onDemand = isset($metadata['on_demand']) ? (!empty($metadata['on_demand']) ? 1 : 0) : 1;
    $broadcast = isset($metadata['broadcast']) ? (!empty($metadata['broadcast']) ? 1 : 0) : 1;
    $automaticRotation = isset($metadata['automatic_rotation']) ? (!empty($metadata['automatic_rotation']) ? 1 : 0) : 1;
    $allowDownload = !empty($metadata['allow_download']) ? 1 : 0;
    $duration = isset($metadata['duration']) ? max(0, (int) $metadata['duration']) : 0;
    if ($duration < 1) {
        $duration = RADIO_detectAudioDuration($destination, $validated['extension']);
    }
    $owner = isset($_USER['uid']) ? (int) $_USER['uid'] : 2;
    $groupId = isset($metadata['group_id']) ? max(1, (int) $metadata['group_id']) : RADIO_defaultGroupId();
    list($permOwner, $permGroup, $permMembers, $permAnon) = RADIO_permissionValues($metadata, array());
    $now = date('Y-m-d H:i:s');

    $sql = "INSERT INTO {$_TABLES['radio_media']} "
        . "(title,description,media_type,author,series_title,category,collection_name,tags,season_number,episode_number,cover_name,storage_name,original_name,mime_type,duration,file_size,metadata_mtime,status,on_demand,broadcast,automatic_rotation,allow_download,hits,owner_id,group_id,perm_owner,perm_group,perm_members,perm_anon,created,modified) VALUES ("
        . "'" . $title . "',"
        . "'" . $description . "',"
        . "'" . DB_escapeString($mediaType) . "',"
        . "'" . $author . "','" . $seriesTitle . "','" . $category . "','" . $collectionName . "','" . $tags . "',"
        . $seasonNumber . "," . $episodeNumber . ",'" . DB_escapeString($coverName) . "',"
        . "'" . DB_escapeString($storageName) . "',"
        . "'" . DB_escapeString($validated['original_name']) . "',"
        . "'" . DB_escapeString($validated['mime']) . "',"
        . $duration . ","
        . (int) $validated['size'] . ",0,"
        . "'" . DB_escapeString($status) . "',"
        . $onDemand . ","
        . $broadcast . ","
        . $automaticRotation . ","
        . $allowDownload . ",0,"
        . $owner . "," . $groupId . "," . $permOwner . "," . $permGroup . "," . $permMembers . "," . $permAnon . ","
        . "'" . DB_escapeString($now) . "',"
        . "'" . DB_escapeString($now) . "')";

    DB_query($sql);
    if (DB_error()) {
        @unlink($destination);
        $error = 'database_error';
        return false;
    }

    $id = (int) DB_insertId();
    if (function_exists('PLG_itemSaved')) {
        PLG_itemSaved(RADIO_externalId('media', $id), 'radio');
    }
    return $id;
}

function RADIO_isDatabaseCurrent()
{
    global $_TABLES;

    if (!isset($_TABLES['plugins'])) {
        return false;
    }

    $installedVersion = DB_getItem(
        $_TABLES['plugins'],
        'pi_version',
        "pi_name = 'radio'"
    );

    return $installedVersion !== ''
        && version_compare($installedVersion, RADIO_PLUGIN_VERSION, '>=');
}

function RADIO_mediaAvailabilityFieldReady($field)
{
    static $ready = array();

    if (!in_array($field, array('on_demand', 'broadcast', 'automatic_rotation'), true)) {
        return false;
    }
    if (array_key_exists($field, $ready)) {
        return $ready[$field];
    }

    global $_TABLES;

    $result = DB_query(
        "SHOW COLUMNS FROM {$_TABLES['radio_media']} LIKE '"
        . DB_escapeString($field) . "'"
    );
    $ready[$field] = DB_numRows($result) > 0;
    return $ready[$field];
}

function RADIO_mediaAvailabilitySchemaReady()
{
    return RADIO_mediaAvailabilityFieldReady('on_demand')
        && RADIO_mediaAvailabilityFieldReady('broadcast')
        && RADIO_mediaAvailabilityFieldReady('automatic_rotation');
}

function RADIO_mediaAvailabilitySql($field, $alias)
{
    if (!RADIO_mediaAvailabilityFieldReady($field)) {
        return '';
    }

    $prefix = trim((string) $alias);
    if ($prefix !== '') {
        $prefix .= '.';
    }

    return ' AND ' . $prefix . $field . '=1';
}

function RADIO_isOnDemandAvailable($row)
{
    return is_array($row)
        && (!RADIO_sharedMediaEnabled() || empty($row['shared_hidden']))
        && isset($row['status'])
        && $row['status'] === 'published'
        && (!array_key_exists('on_demand', $row) || !empty($row['on_demand']));
}

function RADIO_isBroadcastAvailable($row)
{
    return is_array($row)
        && (!RADIO_sharedMediaEnabled() || empty($row['shared_hidden']))
        && isset($row['status'])
        && $row['status'] === 'published'
        && (!array_key_exists('broadcast', $row) || !empty($row['broadcast']));
}

function RADIO_isAutomaticRotationAvailable($row)
{
    return RADIO_isBroadcastAvailable($row)
        && (!array_key_exists('automatic_rotation', $row) || !empty($row['automatic_rotation']));
}

function RADIO_cleanImportedTitle($value)
{
    $decoded = rawurldecode((string) $value);
    $title = pathinfo($decoded, PATHINFO_FILENAME);

    // YouTube-style downloads commonly append an 11-character video ID.
    if (preg_match('/(?:_|-)([A-Za-z0-9_-]{11})$/', $title)) {
        $title = preg_replace('/(?:_|-)[A-Za-z0-9_-]{11}$/', '', $title);
    }

    // Treat filename separators as spaces after platform IDs have been removed.
    $title = str_replace(array('_', "	"), array(' ', ' '), $title);
    $title = preg_replace('/\s+/', ' ', $title);

    // Remove common platform/video labels only when they are trailing metadata.
    $noise = array(
        'official video',
        'official music video',
        'official audio',
        'lyric video',
        'lyrics',
        'audio',
        'video',
        'visualizer',
        'visualiser'
    );
    $noisePattern = implode('|', array_map(function ($item) {
        return preg_quote($item, '/');
    }, $noise));
    $title = preg_replace(
        '/\s*[\(\[]\s*(?:' . $noisePattern . ')\s*[\)\]]\s*$/i',
        '',
        $title
    );

    $title = trim(preg_replace('/\s+/', ' ', $title));
    return $title;
}

function RADIO_normalizeImportedMediaTitle($row)
{
    if (!is_array($row) || !isset($row['title'])) {
        return $row;
    }

    $title = trim((string) $row['title']);
    $original = isset($row['original_name']) ? (string) $row['original_name'] : '';
    if ($title === '' || $original === '') {
        return $row;
    }

    $originalStem = pathinfo($original, PATHINFO_FILENAME);
    $decodedStem = pathinfo(rawurldecode($original), PATHINFO_FILENAME);

    if ($title === $originalStem || $title === $decodedStem) {
        $clean = RADIO_cleanImportedTitle($original);
        if ($clean !== '') {
            $row['title'] = $clean;
        }
    }

    return $row;
}

function RADIO_siteMediaState($mediaId)
{
    global $_TABLES;
    if (!RADIO_sharedLibraryEnabled()) {
        return false;
    }

    $result = DB_query(
        "SELECT * FROM {$_TABLES['radio_site_media']} WHERE media_id=" . (int) $mediaId
    );
    return DB_numRows($result) > 0 ? DB_fetchArray($result) : false;
}

function RADIO_mergeSiteMediaState($row)
{
    if (!is_array($row)) {
        return $row;
    }

    $row['site_enabled'] = 1;
    if (!RADIO_sharedLibraryEnabled()) {
        return $row;
    }

    $state = RADIO_siteMediaState((int) $row['media_id']);
    if ($state === false) {
        return $row;
    }

    $row['site_enabled'] = !empty($state['enabled']) ? 1 : 0;
    foreach (array(
        'status','on_demand','broadcast','automatic_rotation','allow_download',
        'owner_id','group_id','perm_owner','perm_group','perm_members','perm_anon'
    ) as $field) {
        if (array_key_exists($field, $state)) {
            $row[$field] = $state[$field];
        }
    }
    return $row;
}

function RADIO_saveSiteMediaState($mediaId, $data, $existing)
{
    global $_TABLES, $_USER;

    if (!RADIO_sharedLibraryEnabled()) {
        return true;
    }

    $owner = isset($_USER['uid']) ? (int) $_USER['uid'] : 2;
    $status = isset($data['status']) && $data['status'] === 'published'
        ? 'published'
        : (isset($existing['status']) ? $existing['status'] : 'draft');
    $onDemand = isset($data['on_demand']) ? (!empty($data['on_demand']) ? 1 : 0) : (!empty($existing['on_demand']) ? 1 : 0);
    $broadcast = isset($data['broadcast']) ? (!empty($data['broadcast']) ? 1 : 0) : (!empty($existing['broadcast']) ? 1 : 0);
    $automaticRotation = isset($data['automatic_rotation']) ? (!empty($data['automatic_rotation']) ? 1 : 0) : (!empty($existing['automatic_rotation']) ? 1 : 0);
    $allowDownload = isset($data['allow_download']) ? (!empty($data['allow_download']) ? 1 : 0) : (!empty($existing['allow_download']) ? 1 : 0);
    $enabled = isset($data['site_enabled']) ? (!empty($data['site_enabled']) ? 1 : 0) : 1;
    $groupId = isset($data['group_id']) ? max(1, (int) $data['group_id']) : (isset($existing['group_id']) ? (int) $existing['group_id'] : RADIO_defaultGroupId());
    list($permOwner, $permGroup, $permMembers, $permAnon) = RADIO_permissionValues($data, is_array($existing) ? $existing : array());
    $now = date('Y-m-d H:i:s');

    DB_query(
        "REPLACE INTO {$_TABLES['radio_site_media']} "
        . "(media_id,enabled,status,on_demand,broadcast,automatic_rotation,allow_download,owner_id,group_id,perm_owner,perm_group,perm_members,perm_anon,modified) VALUES ("
        . (int) $mediaId . "," . $enabled . ",'" . DB_escapeString($status) . "',"
        . $onDemand . "," . $broadcast . "," . $automaticRotation . "," . $allowDownload . ","
        . $owner . "," . $groupId . "," . $permOwner . "," . $permGroup . "," . $permMembers . "," . $permAnon . ",'"
        . DB_escapeString($now) . "')"
    );

    return !DB_error();
}

function RADIO_metadataMtimeSchemaReady()
{
    static $ready = null;
    global $_TABLES;

    if ($ready !== null) {
        return $ready;
    }

    $table = isset($_TABLES['radio_media_local'])
        ? $_TABLES['radio_media_local']
        : $_TABLES['radio_media'];
    $result = DB_query(
        "SHOW COLUMNS FROM " . $table . " LIKE 'metadata_mtime'"
    );
    $ready = !DB_error() && DB_numRows($result) > 0;
    return $ready;
}

function RADIO_sharedMediaSyncInterval()
{
    global $_RADIO_CONF;
    return isset($_RADIO_CONF['shared_media_sync_interval'])
        ? max(30, min(86400, (int) $_RADIO_CONF['shared_media_sync_interval']))
        : 300;
}

function RADIO_sharedMediaSyncMarkerPath()
{
    global $_CONF;
    $base = isset($_CONF['path_data']) ? rtrim((string) $_CONF['path_data'], "/\\") : '';
    return $base === '' ? '' : $base . DIRECTORY_SEPARATOR . 'radio-shared-media-sync.marker';
}

function RADIO_sharedMediaMetadataFields()
{
    return array(
        'title',
        'author',
        'series_title',
        'category',
        'collection_name',
        'tags',
        'description',
        'media_type',
        'season_number',
        'episode_number'
    );
}

function RADIO_sharedMediaNormalizeMetadata($metadata, $fallback)
{
    $metadata = is_array($metadata) ? $metadata : array();
    $fallback = is_array($fallback) ? $fallback : array();
    $result = array();

    foreach (RADIO_sharedMediaMetadataFields() as $field) {
        $value = array_key_exists($field, $metadata)
            ? $metadata[$field]
            : (array_key_exists($field, $fallback) ? $fallback[$field] : '');

        if ($field === 'season_number' || $field === 'episode_number') {
            $value = max(0, (int) $value);
        } elseif ($field === 'category') {
            $value = RADIO_normalizeClassificationValue($value, 128);
        } elseif ($field === 'collection_name') {
            $value = RADIO_normalizeClassificationValue($value, 255);
        } elseif ($field === 'tags') {
            $value = RADIO_normalizeTags($value);
        } elseif ($field === 'media_type') {
            $allowed = array('music','podcast','interview','show','chronicle','jingle','announcement','promo');
            $value = in_array((string) $value, $allowed, true) ? (string) $value : 'music';
        } else {
            $value = trim((string) $value);
        }

        $result[$field] = $value;
    }

    $result['original_name'] = array_key_exists('original_name', $metadata)
        ? basename((string) $metadata['original_name'])
        : (isset($fallback['original_name']) ? basename((string) $fallback['original_name']) : '');

    return $result;
}

function RADIO_embeddedMetadataSupported($row)
{
    if (!RADIO_sharedMediaEnabled() || !is_array($row)
        || RADIO_sourceKind($row) !== 'local' || empty($row['storage_name'])) {
        return false;
    }

    return strtolower(pathinfo((string) $row['storage_name'], PATHINFO_EXTENSION)) === 'mp3';
}

function RADIO_readMediaMetadataFromFile($row)
{
    if (!RADIO_embeddedMetadataSupported($row)) {
        return array();
    }

    $path = RADIO_storageDir() . basename($row['storage_name']);
    if (!is_file($path) || !is_readable($path)) {
        return array();
    }

    $tag = RADIO_id3ReadTag($path);
    return isset($tag['metadata']) && is_array($tag['metadata'])
        ? $tag['metadata']
        : array();
}

function RADIO_writeMediaMetadataToFile($row, $metadata, &$error)
{
    $error = '';
    if (!RADIO_embeddedMetadataSupported($row)) {
        $error = 'metadata_format_unsupported';
        return false;
    }

    $path = RADIO_storageDir() . basename($row['storage_name']);
    $normalized = RADIO_sharedMediaNormalizeMetadata($metadata, $row);
    if ($normalized['title'] === '') {
        $normalized['title'] = isset($row['title']) ? (string) $row['title'] : '';
    }
    if ($normalized['original_name'] === '') {
        $normalized['original_name'] = isset($row['original_name'])
            ? (string) $row['original_name']
            : basename((string) $row['storage_name']);
    }

    return RADIO_id3WriteMetadata($path, $normalized, $error);
}

function RADIO_syncMediaMetadataFromFile($row, $force)
{
    global $_TABLES;

    if (!RADIO_embeddedMetadataSupported($row) || !RADIO_metadataMtimeSchemaReady()) {
        return false;
    }

    $path = RADIO_storageDir() . basename($row['storage_name']);
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    clearstatcache(true, $path);
    $mtime = (int) @filemtime($path);
    $knownMtime = isset($row['metadata_mtime']) ? (int) $row['metadata_mtime'] : 0;
    if (!$force && $mtime > 0 && $knownMtime >= $mtime) {
        return false;
    }

    $embedded = RADIO_readMediaMetadataFromFile($row);
    $sets = array();

    foreach (RADIO_sharedMediaMetadataFields() as $field) {
        if (!array_key_exists($field, $embedded)) {
            continue;
        }
        $normalized = RADIO_sharedMediaNormalizeMetadata(
            array($field => $embedded[$field]),
            $row
        );
        $value = $normalized[$field];

        if ($field === 'season_number' || $field === 'episode_number') {
            $sets[] = $field . '=' . (int) $value;
        } else {
            $sets[] = $field . "='" . DB_escapeString((string) $value) . "'";
        }
    }

    if (!empty($embedded['original_name'])) {
        $sets[] = "original_name='"
            . DB_escapeString(basename((string) $embedded['original_name'])) . "'";
    }

    $sets[] = 'metadata_mtime=' . max(0, $mtime);
    if (count($sets) === 1 && $mtime < 1) {
        return false;
    }

    $table = isset($_TABLES['radio_media_local'])
        ? $_TABLES['radio_media_local']
        : $_TABLES['radio_media'];
    DB_query(
        "UPDATE " . $table . " SET " . implode(',', $sets)
        . " WHERE media_id=" . (int) $row['media_id']
    );

    return !DB_error();
}

function RADIO_prepareSharedMediaUpdate($row, $metadata, &$error)
{
    $error = '';
    if (!RADIO_embeddedMetadataSupported($row)) {
        return $metadata;
    }

    $submitted = RADIO_sharedMediaNormalizeMetadata($metadata, $row);
    $before = RADIO_sharedMediaNormalizeMetadata($row, $row);
    $changed = array();

    foreach (RADIO_sharedMediaMetadataFields() as $field) {
        if ((string) $submitted[$field] !== (string) $before[$field]) {
            $changed[$field] = $submitted[$field];
        }
    }

    if (count($changed) === 0) {
        return $metadata;
    }

    // Re-read the file immediately before writing. Only fields changed by the
    // current form overwrite embedded metadata, preventing a stale site cache
    // from reverting edits made by another site.
    $current = RADIO_readMediaMetadataFromFile($row);
    $merged = RADIO_sharedMediaNormalizeMetadata($current, $row);
    foreach ($changed as $field => $value) {
        $merged[$field] = $value;
    }
    $merged['original_name'] = isset($row['original_name'])
        ? (string) $row['original_name']
        : basename((string) $row['storage_name']);

    if (!RADIO_writeMediaMetadataToFile($row, $merged, $error)) {
        return false;
    }

    foreach (RADIO_sharedMediaMetadataFields() as $field) {
        $metadata[$field] = $merged[$field];
    }

    $path = RADIO_storageDir() . basename($row['storage_name']);
    clearstatcache(true, $path);
    $metadata['_metadata_mtime'] = (int) @filemtime($path);
    return $metadata;
}

function RADIO_registerSharedMediaFile($storageName)
{
    global $_TABLES, $_USER;

    if (!RADIO_sharedMediaEnabled() || !RADIO_metadataMtimeSchemaReady()) {
        return false;
    }

    $storageName = basename((string) $storageName);
    $extension = strtolower(pathinfo($storageName, PATHINFO_EXTENSION));
    if (!isset(RADIO_allowedAudio()[$extension])) {
        return false;
    }

    $table = isset($_TABLES['radio_media_local'])
        ? $_TABLES['radio_media_local']
        : $_TABLES['radio_media'];
    $exists = DB_getItem(
        $table,
        'media_id',
        "storage_name='" . DB_escapeString($storageName) . "'"
    );
    if ((int) $exists > 0) {
        return (int) $exists;
    }

    $path = RADIO_storageDir() . $storageName;
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $tag = $extension === 'mp3' ? RADIO_id3ReadTag($path) : array('metadata' => array());
    $embedded = isset($tag['metadata']) && is_array($tag['metadata'])
        ? $tag['metadata']
        : array();
    $original = !empty($embedded['original_name'])
        ? basename((string) $embedded['original_name'])
        : $storageName;
    $metadata = RADIO_sharedMediaNormalizeMetadata($embedded, array(
        'title' => RADIO_cleanImportedTitle($original),
        'media_type' => 'music',
        'original_name' => $original
    ));
    if ($metadata['title'] === '') {
        $metadata['title'] = RADIO_cleanImportedTitle($storageName);
    }

    $duration = RADIO_detectAudioDuration($path, $extension);
    $mime = RADIO_detectMime($path);
    $size = (int) @filesize($path);
    $mtime = (int) @filemtime($path);
    $owner = isset($_USER['uid']) ? (int) $_USER['uid'] : 2;
    $groupId = RADIO_defaultGroupId();
    $now = date('Y-m-d H:i:s');

    DB_query(
        "INSERT INTO " . $table . " "
        . "(title,description,media_type,author,series_title,category,collection_name,tags,"
        . "season_number,episode_number,cover_name,source_kind,source_url,source_provider,"
        . "source_external_id,source_attribution,source_license,storage_name,original_name,"
        . "mime_type,duration,file_size,metadata_mtime,status,on_demand,broadcast,"
        . "automatic_rotation,allow_download,hits,owner_id,group_id,perm_owner,perm_group,"
        . "perm_members,perm_anon,created,modified) VALUES ("
        . "'" . DB_escapeString(substr($metadata['title'],0,255)) . "',"
        . "'" . DB_escapeString($metadata['description']) . "',"
        . "'" . DB_escapeString($metadata['media_type']) . "',"
        . "'" . DB_escapeString(substr($metadata['author'],0,255)) . "',"
        . "'" . DB_escapeString(substr($metadata['series_title'],0,255)) . "',"
        . "'" . DB_escapeString($metadata['category']) . "',"
        . "'" . DB_escapeString($metadata['collection_name']) . "',"
        . "'" . DB_escapeString($metadata['tags']) . "',"
        . (int) $metadata['season_number'] . "," . (int) $metadata['episode_number'] . ",'',"
        . "'local','','','','','',"
        . "'" . DB_escapeString($storageName) . "',"
        . "'" . DB_escapeString($original) . "',"
        . "'" . DB_escapeString($mime) . "',"
        . max(0, $duration) . "," . max(0, $size) . "," . max(0, $mtime) . ","
        . "'draft',1,1,1,0,0,"
        . $owner . "," . $groupId . ",3,2,2,2,'"
        . DB_escapeString($now) . "','" . DB_escapeString($now) . "')"
    );

    return DB_error() ? false : (int) DB_insertId();
}

function RADIO_syncSharedMediaLibrary($force)
{
    global $_TABLES;

    $summary = array(
        'enabled' => RADIO_sharedMediaEnabled(),
        'scanned' => 0,
        'imported' => 0,
        'updated' => 0,
        'errors' => 0,
        'skipped' => false
    );

    if (!$summary['enabled'] || !RADIO_metadataMtimeSchemaReady()) {
        return $summary;
    }

    $marker = RADIO_sharedMediaSyncMarkerPath();
    if (!$force && $marker !== '' && is_file($marker)) {
        $age = time() - (int) @filemtime($marker);
        if ($age >= 0 && $age < RADIO_sharedMediaSyncInterval()) {
            $summary['skipped'] = true;
            return $summary;
        }
    }

    $dir = RADIO_storageDir();
    if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
        $summary['errors']++;
        return $summary;
    }

    $table = isset($_TABLES['radio_media_local'])
        ? $_TABLES['radio_media_local']
        : $_TABLES['radio_media'];
    $known = array();
    $result = DB_query(
        "SELECT media_id,storage_name,source_kind,metadata_mtime,title,author,series_title,"
        . "category,collection_name,tags,description,media_type,season_number,episode_number,"
        . "original_name,status,on_demand,broadcast,automatic_rotation,allow_download,"
        . "owner_id,group_id,perm_owner,perm_group,perm_members,perm_anon "
        . "FROM " . $table . " WHERE source_kind='local'"
    );
    while ($row = DB_fetchArray($result)) {
        $known[basename((string) $row['storage_name'])] = $row;
    }

    $handle = @opendir($dir);
    if (!$handle) {
        $summary['errors']++;
        return $summary;
    }

    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $dir . basename($name);
        if (!is_file($path)) {
            continue;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(RADIO_allowedAudio()[$extension])) {
            continue;
        }

        $summary['scanned']++;
        if (!isset($known[$name])) {
            if (RADIO_registerSharedMediaFile($name) !== false) {
                $summary['imported']++;
            } else {
                $summary['errors']++;
            }
            continue;
        }

        if ($extension !== 'mp3') {
            continue;
        }

        clearstatcache(true, $path);
        $mtime = (int) @filemtime($path);
        if ($force || $mtime > (int) $known[$name]['metadata_mtime']) {
            if (RADIO_syncMediaMetadataFromFile($known[$name], true)) {
                $summary['updated']++;
            } else {
                $summary['errors']++;
            }
        }
    }
    closedir($handle);

    if ($marker !== '') {
        @touch($marker);
    }

    return $summary;
}

function RADIO_getMedia($id, $publishedOnly)
{
    global $_TABLES;
    $id = (int) $id;
    if ($id < 1) {
        return false;
    }
    $where = "media_id=" . $id;
    $result = DB_query("SELECT * FROM {$_TABLES['radio_media']} WHERE " . $where);
    if (DB_numRows($result) < 1) {
        return false;
    }
    $row = RADIO_mergeSiteMediaState(RADIO_normalizeImportedMediaTitle(DB_fetchArray($result)));
    if (RADIO_sharedMediaEnabled() && RADIO_syncMediaMetadataFromFile($row, false)) {
        $refresh = DB_query("SELECT * FROM {$_TABLES['radio_media']} WHERE media_id=" . $id);
        if (DB_numRows($refresh) > 0) {
            $row = RADIO_mergeSiteMediaState(RADIO_normalizeImportedMediaTitle(DB_fetchArray($refresh)));
        }
    }
    if ($publishedOnly && ($row['status'] !== 'published'
        || (RADIO_sharedMediaEnabled() && !empty($row['shared_hidden']))
        || !RADIO_hasReadAccess($row))) {
        return false;
    }
    return $row;
}

function RADIO_mediaClassificationSchemaReady()
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    global $_TABLES;

    $ready = true;
    foreach (array('category','collection_name','tags') as $column) {
        $result = DB_query(
            "SHOW COLUMNS FROM {$_TABLES['radio_media']} LIKE '"
            . DB_escapeString($column) . "'"
        );
        if (DB_numRows($result) < 1) {
            $ready = false;
            break;
        }
    }

    return $ready;
}

function RADIO_normalizeClassificationValue($value, $maxLength)
{
    $value = trim((string) $value);
    $value = preg_replace('/\\s+/', ' ', $value);
    if ($value === '') {
        return '';
    }
    return substr($value, 0, max(1, (int) $maxLength));
}

function RADIO_normalizeTags($value)
{
    $parts = preg_split('/[,;\\r\\n]+/', (string) $value);
    $tags = array();
    $seen = array();

    foreach ($parts as $part) {
        $tag = RADIO_normalizeClassificationValue($part, 64);
        if ($tag === '') {
            continue;
        }

        $key = strtolower($tag);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $tags[] = $tag;
        if (count($tags) >= 50) {
            break;
        }
    }

    return implode(',', $tags);
}

function RADIO_mediaClassificationOptions()
{
    global $_TABLES;

    $options = array(
        'categories' => array(),
        'collections' => array(),
        'tags' => array()
    );

    if (!RADIO_mediaClassificationSchemaReady()) {
        return $options;
    }

    $result = DB_query(
        "SELECT category,collection_name,tags FROM {$_TABLES['radio_media']} "
        . "ORDER BY category,collection_name"
    );

    $categories = array();
    $collections = array();
    $tags = array();

    while ($row = DB_fetchArray($result)) {
        if (!empty($row['category'])) {
            $categories[(string) $row['category']] = true;
        }
        if (!empty($row['collection_name'])) {
            $collections[(string) $row['collection_name']] = true;
        }
        if (!empty($row['tags'])) {
            foreach (explode(',', (string) $row['tags']) as $tag) {
                $tag = trim($tag);
                if ($tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }
    }

    $options['categories'] = array_keys($categories);
    $options['collections'] = array_keys($collections);
    $options['tags'] = array_keys($tags);

    natcasesort($options['categories']);
    natcasesort($options['collections']);
    natcasesort($options['tags']);

    $options['categories'] = array_values($options['categories']);
    $options['collections'] = array_values($options['collections']);
    $options['tags'] = array_values($options['tags']);

    return $options;
}

function RADIO_getMediaList($limit, $publishedOnly, $sort = 'modified', $direction = 'desc', $filters = array())
{
    global $_TABLES;

    if (RADIO_sharedMediaEnabled()) {
        RADIO_syncSharedMediaLibrary(false);
    }

    $limit = max(1, min(500, (int) $limit));
    $where = ($publishedOnly && !RADIO_sharedLibraryEnabled())
        ? " WHERE status='published'"
        : ' WHERE 1=1';
    if (RADIO_sharedMediaEnabled()) {
        $where .= " AND shared_hidden=0";
    }


    if ($publishedOnly && !RADIO_sharedLibraryEnabled()) {
        $where .= RADIO_mediaAvailabilitySql('on_demand', '');
        $where .= RADIO_permissionSql('radio_media', 0);
    }

    if (!is_array($filters)) {
        $filters = array();
    }

    $classificationReady = RADIO_mediaClassificationSchemaReady();
    $query = isset($filters['q']) ? trim((string) $filters['q']) : '';
    if ($query !== '') {
        $safeQuery = DB_escapeString($query);
        $where .= " AND (title LIKE '%" . $safeQuery . "%'"
            . " OR author LIKE '%" . $safeQuery . "%'"
            . " OR series_title LIKE '%" . $safeQuery . "%'"
            . ($classificationReady
                ? " OR category LIKE '%" . $safeQuery . "%'"
                    . " OR collection_name LIKE '%" . $safeQuery . "%'"
                    . " OR tags LIKE '%" . $safeQuery . "%'"
                : '')
            . " OR original_name LIKE '%" . $safeQuery . "%')";
    }

    foreach (array(
        'type' => 'media_type',
        'status' => 'status',
        'source' => 'source_kind'
    ) as $filterKey => $column) {
        if (isset($filters[$filterKey]) && trim((string) $filters[$filterKey]) !== '') {
            $where .= " AND " . $column . "='"
                . DB_escapeString(trim((string) $filters[$filterKey])) . "'";
        }
    }

    if ($classificationReady) {
        foreach (array(
            'category' => 'category',
            'collection' => 'collection_name'
        ) as $filterKey => $column) {
            if (isset($filters[$filterKey]) && trim((string) $filters[$filterKey]) !== '') {
                $where .= " AND " . $column . "='"
                    . DB_escapeString(trim((string) $filters[$filterKey])) . "'";
            }
        }

        if (isset($filters['tag']) && trim((string) $filters['tag']) !== '') {
            $tag = DB_escapeString(RADIO_normalizeClassificationValue($filters['tag'], 64));
            $where .= " AND FIND_IN_SET('" . $tag . "',tags)>0";
        }
    }

    foreach (array('on_demand','broadcast','automatic_rotation') as $flag) {
        if (isset($filters[$flag]) && ($filters[$flag] === '0' || $filters[$flag] === '1')) {
            $where .= " AND " . $flag . "=" . (int) $filters[$flag];
        }
    }

    $sortColumns = array(
        'title' => 'title',
        'type' => 'media_type',
        'category' => 'category',
        'collection' => 'collection_name',
        'status' => 'status',
        'availability' => 'on_demand',
        'source' => 'source_kind',
        'duration' => 'duration',
        'size' => 'file_size',
        'modified' => 'modified'
    );
    if (!isset($sortColumns[$sort])
        || (!$classificationReady && ($sort === 'category' || $sort === 'collection'))) {
        $sort = 'modified';
    }
    $direction = strtolower((string) $direction) === 'asc' ? 'ASC' : 'DESC';

    $orderBy = $sortColumns[$sort] . ' ' . $direction;
    if ($sort === 'availability') {
        $orderBy .= ', broadcast ' . $direction . ', automatic_rotation ' . $direction;
    }
    $orderBy .= ', media_id DESC';

    $result = DB_query(
        "SELECT * FROM {$_TABLES['radio_media']}"
        . $where
        . " ORDER BY " . $orderBy
        . " LIMIT " . $limit
    );
    $items = array();
    while ($row = DB_fetchArray($result)) {
        $row = RADIO_mergeSiteMediaState(RADIO_normalizeImportedMediaTitle($row));
        if ($publishedOnly && ($row['status'] !== 'published'
            || empty($row['site_enabled'])
            || !RADIO_isOnDemandAvailable($row)
            || !RADIO_hasReadAccess($row))) {
            continue;
        }
        $items[] = $row;
    }
    return $items;
}

function RADIO_updateMedia($id, $metadata)
{
    global $_TABLES;
    $row = RADIO_getMedia($id, false);
    if ($row === false || !RADIO_hasEditAccess($row)) {
        return false;
    }

    $metadataError = '';
    $preparedMetadata = RADIO_prepareSharedMediaUpdate($row, $metadata, $metadataError);
    if ($preparedMetadata === false) {
        COM_errorLog(
            'Radio: unable to write shared MP3 metadata for media '
            . (int) $id . ': ' . $metadataError,
            1
        );
        return false;
    }
    $metadata = $preparedMetadata;

    $title = trim(isset($metadata['title']) ? (string) $metadata['title'] : '');
    if ($title === '') {
        return false;
    }
    $description = isset($metadata['description']) ? trim((string)$metadata['description']) : '';
    $author = trim(isset($metadata['author']) ? (string)$metadata['author'] : '');
    $seriesTitle = trim(isset($metadata['series_title']) ? (string)$metadata['series_title'] : '');
    $category = RADIO_normalizeClassificationValue(isset($metadata['category']) ? $metadata['category'] : (isset($row['category']) ? $row['category'] : ''), 128);
    $collectionName = RADIO_normalizeClassificationValue(isset($metadata['collection_name']) ? $metadata['collection_name'] : (isset($row['collection_name']) ? $row['collection_name'] : ''), 255);
    $tags = RADIO_normalizeTags(isset($metadata['tags']) ? $metadata['tags'] : (isset($row['tags']) ? $row['tags'] : ''));
    $seasonNumber = isset($metadata['season_number']) ? max(0,(int)$metadata['season_number']) : (int)$row['season_number'];
    $episodeNumber = isset($metadata['episode_number']) ? max(0,(int)$metadata['episode_number']) : (int)$row['episode_number'];
    $coverName = isset($metadata['cover_name']) ? basename((string)$metadata['cover_name']) : $row['cover_name'];
    $mediaType = isset($metadata['media_type']) ? $metadata['media_type'] : 'music';
    $allowedTypes = array('music', 'podcast', 'interview', 'show', 'chronicle', 'jingle', 'announcement', 'promo');
    if (!in_array($mediaType, $allowedTypes, true)) {
        $mediaType = 'music';
    }
    $status = isset($metadata['status']) && $metadata['status'] === 'published' ? 'published' : 'draft';
    $onDemand = isset($metadata['on_demand'])
        ? (!empty($metadata['on_demand']) ? 1 : 0)
        : (!empty($row['on_demand']) ? 1 : 0);
    $broadcast = isset($metadata['broadcast'])
        ? (!empty($metadata['broadcast']) ? 1 : 0)
        : (!empty($row['broadcast']) ? 1 : 0);
    $automaticRotation = isset($metadata['automatic_rotation'])
        ? (!empty($metadata['automatic_rotation']) ? 1 : 0)
        : (!array_key_exists('automatic_rotation', $row) || !empty($row['automatic_rotation']) ? 1 : 0);
    $allowDownload = !empty($metadata['allow_download']) ? 1 : 0;
    $duration = isset($metadata['duration']) ? max(0, (int) $metadata['duration']) : (int) $row['duration'];
    if ($duration < 1 && RADIO_sourceKind($row) === 'local' && !empty($row['storage_name'])) {
        $storedPath = RADIO_storageDir() . basename($row['storage_name']);
        $storedExtension = strtolower(pathinfo($row['storage_name'], PATHINFO_EXTENSION));
        $duration = RADIO_detectAudioDuration($storedPath, $storedExtension);
    }
    $groupId = isset($metadata['group_id']) ? max(1, (int) $metadata['group_id']) : (int) $row['group_id'];
    list($permOwner, $permGroup, $permMembers, $permAnon) = RADIO_permissionValues($metadata, $row);
    $now = date('Y-m-d H:i:s');

    DB_query(
        "UPDATE {$_TABLES['radio_media']} SET "
        . "title='" . DB_escapeString(substr($title, 0, 255)) . "',"
        . "description='" . DB_escapeString($description) . "',"
        . "media_type='" . DB_escapeString($mediaType) . "',"
        . "author='" . DB_escapeString(substr($author,0,255)) . "',"
        . "series_title='" . DB_escapeString(substr($seriesTitle,0,255)) . "',"
        . "category='" . DB_escapeString($category) . "',"
        . "collection_name='" . DB_escapeString($collectionName) . "',"
        . "tags='" . DB_escapeString($tags) . "',"
        . "season_number=" . $seasonNumber . ",episode_number=" . $episodeNumber . ",cover_name='" . DB_escapeString($coverName) . "',"
        . (RADIO_sharedLibraryEnabled() ? "" :
            "status='" . DB_escapeString($status) . "',"
            . "on_demand=" . $onDemand . ","
            . "broadcast=" . $broadcast . ","
            . "automatic_rotation=" . $automaticRotation . ","
            . "allow_download=" . $allowDownload . ",")
        . "duration=" . $duration . ","
        . "group_id=" . $groupId . ",perm_owner=" . $permOwner . ",perm_group=" . $permGroup . ",perm_members=" . $permMembers . ",perm_anon=" . $permAnon . ","
        . (isset($metadata['_metadata_mtime'])
            ? "metadata_mtime=" . max(0, (int) $metadata['_metadata_mtime']) . ","
            : "")
        . "modified='" . DB_escapeString($now) . "' "
        . "WHERE media_id=" . (int) $id
    );
    if (DB_error()) {
        return false;
    }
    if (function_exists('PLG_itemSaved')) {
        PLG_itemSaved(RADIO_externalId('media', (int) $id), 'radio');
    }
    return true;
}

function RADIO_deleteMedia($id)
{
    global $_TABLES;
    $row = RADIO_getMedia($id, false);
    if ($row === false || !RADIO_hasEditAccess($row)) {
        return false;
    }

    DB_query("DELETE FROM {$_TABLES['radio_program_items']} WHERE media_id=" . (int) $id);

    if (RADIO_sharedMediaEnabled() && RADIO_sourceKind($row) === 'local') {
        DB_query(
            "UPDATE {$_TABLES['radio_media']} SET shared_hidden=1,"
            . "status='draft',on_demand=0,broadcast=0,automatic_rotation=0,"
            . "modified='" . DB_escapeString(date('Y-m-d H:i:s')) . "' "
            . "WHERE media_id=" . (int) $id
        );
        if (DB_error()) {
            return false;
        }
    } else {
        DB_query("DELETE FROM {$_TABLES['radio_media']} WHERE media_id=" . (int) $id);
        if (DB_error()) {
            return false;
        }

        if (!empty($row['cover_name'])) {
            RADIO_deleteCover($row['cover_name']);
        }

        $path = RADIO_storageDir() . basename($row['storage_name']);
        if (RADIO_sourceKind($row) === 'local' && is_file($path) && !@unlink($path)) {
            COM_errorLog('Radio: database row deleted but media file could not be removed: ' . $path, 1);
        }
    }

    if (function_exists('PLG_itemDeleted')) {
        PLG_itemDeleted(RADIO_externalId('media', (int) $id), 'radio');
    }
    return true;
}

function RADIO_mediaUrl($id, $download)
{
    global $_CONF;
    $url = $_CONF['site_url'] . '/radio/media.php?id=' . (int) $id;
    if ($download) {
        $url .= '&download=1';
    }
    return $url;
}

function RADIO_downloadAllowed($row)
{
    global $_RADIO_CONF;
    return is_array($row)
        && RADIO_sourceKind($row) === 'local'
        && !empty($_RADIO_CONF['allow_downloads'])
        && !empty($row['allow_download']);
}

function RADIO_playbackMime($row)
{
    $name = '';
    if (!empty($row['storage_name'])) {
        $name = (string) $row['storage_name'];
    } elseif (!empty($row['original_name'])) {
        $name = (string) $row['original_name'];
    }

    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($extension === 'm4a') {
        return 'audio/mp4';
    }
    if ($extension === 'mp3') {
        return 'audio/mpeg';
    }
    if ($extension === 'aac') {
        return 'audio/aac';
    }
    if ($extension === 'ogg') {
        return 'audio/ogg';
    }
    if ($extension === 'wav') {
        return 'audio/wav';
    }

    return !empty($row['mime_type'])
        ? (string) $row['mime_type']
        : 'application/octet-stream';
}

function RADIO_sendMedia($row, $download)
{
    global $_RADIO_CONF;

    $sourceKind = RADIO_sourceKind($row);
    if ($sourceKind === 'external' || $sourceKind === 'live') {
        if ($download) {
            return false;
        }
        $error = '';
        $remote = RADIO_externalUrlValidation(isset($row['source_url']) ? $row['source_url'] : '', $error);
        if ($remote === false) {
            return false;
        }
        header('Cache-Control: no-store');
        header('Location: ' . $remote, true, 302);
        exit;
    }

    $path = RADIO_storageDir() . basename($row['storage_name']);
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    if ($download && !RADIO_downloadAllowed($row)) {
        return false;
    }

    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $status = 200;

    if (!$download && isset($_SERVER['HTTP_RANGE'])) {
        $range = trim((string) $_SERVER['HTTP_RANGE']);
        if (strpos($range, ',') !== false
            || !preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)
            || ($m[1] === '' && $m[2] === '')) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $size);
            exit;
        }

        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix < 1) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min($end, (int) $m[2]);
            }
        }

        if ($start > $end || $start >= $size) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $status = 206;
    }

    if ($status === 206) {
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $mime = RADIO_playbackMime($row);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . (($end - $start) + 1));
    header('X-Content-Type-Options: nosniff');

    $filename = basename($row['original_name']);
    $disposition = $download ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');

    $handle = fopen($path, 'rb');
    if (!$handle) {
        return false;
    }
    if ($start > 0) {
        fseek($handle, $start);
    }

    $remaining = ($end - $start) + 1;
    while ($remaining > 0 && !feof($handle)) {
        $length = min(8192, $remaining);
        $buffer = fread($handle, $length);
        if ($buffer === false || $buffer === '') {
            break;
        }
        echo $buffer;
        $remaining -= strlen($buffer);
        if (function_exists('fastcgi_finish_request')) {
            // Do not call it here: the response must continue until the requested range is sent.
        }
    }
    fclose($handle);
    exit;
}


function RADIO_getPrograms($limit, $publishedOnly)
{
    global $_TABLES;
    $limit = max(1, min(200, (int) $limit));
    $where = $publishedOnly ? " WHERE status='published'" : '';
    if ($publishedOnly) {
        $where .= RADIO_permissionSql('radio_programs', 0);
    }
    $result = DB_query("SELECT * FROM {$_TABLES['radio_programs']}" . $where . " ORDER BY modified DESC, program_id DESC LIMIT " . $limit);
    $items = array();
    while ($row = DB_fetchArray($result)) {
        $items[] = $row;
    }
    return $items;
}

function RADIO_getProgram($id, $publishedOnly)
{
    global $_TABLES;
    $id = (int) $id;
    if ($id < 1) {
        return false;
    }
    $where = "program_id=" . $id;
    if ($publishedOnly) {
        $where .= " AND status='published'";
    }
    $result = DB_query("SELECT * FROM {$_TABLES['radio_programs']} WHERE " . $where);
    if (DB_numRows($result) < 1) {
        return false;
    }
    $row = DB_fetchArray($result);
    if ($publishedOnly && !RADIO_hasReadAccess($row)) {
        return false;
    }
    return $row;
}

function RADIO_saveProgram($id, $data)
{
    global $_TABLES, $_USER;
    $title = trim(isset($data['program_title']) ? (string) $data['program_title'] : '');
    if ($title === '') {
        return false;
    }
    $description = trim(isset($data['program_description']) ? (string) $data['program_description'] : '');
    $host = trim(isset($data['program_host']) ? (string) $data['program_host'] : '');
    $coverName = isset($data['cover_name']) ? basename((string)$data['cover_name']) : '';
    $status = isset($data['program_status']) && $data['program_status'] === 'published' ? 'published' : 'draft';
    $existingProgram = (int) $id > 0 ? RADIO_getProgram((int) $id, false) : array();
    if ((int) $id > 0 && ($existingProgram === false || !RADIO_hasEditAccess($existingProgram))) {
        return false;
    }
    $groupId = isset($data['group_id']) ? max(1, (int) $data['group_id']) : (!empty($existingProgram['group_id']) ? (int) $existingProgram['group_id'] : RADIO_defaultGroupId());
    list($permOwner, $permGroup, $permMembers, $permAnon) = RADIO_permissionValues($data, is_array($existingProgram) ? $existingProgram : array());
    $now = date('Y-m-d H:i:s');

    if ((int) $id > 0) {
        DB_query(
            "UPDATE {$_TABLES['radio_programs']} SET "
            . "title='" . DB_escapeString(substr($title, 0, 255)) . "',"
            . "description='" . DB_escapeString($description) . "',"
            . "host='" . DB_escapeString(substr($host,0,255)) . "',"
            . "cover_name='" . DB_escapeString($coverName !== '' ? $coverName : $existingProgram['cover_name']) . "',"
            . "status='" . DB_escapeString($status) . "',"
            . "group_id=" . $groupId . ",perm_owner=" . $permOwner . ",perm_group=" . $permGroup . ",perm_members=" . $permMembers . ",perm_anon=" . $permAnon . ","
            . "modified='" . DB_escapeString($now) . "' "
            . "WHERE program_id=" . (int) $id
        );
        if (DB_error()) {
            return false;
        }
        $programId = (int) $id;
    } else {
        $owner = isset($_USER['uid']) ? (int) $_USER['uid'] : 2;
        DB_query(
            "INSERT INTO {$_TABLES['radio_programs']} "
            . "(title,description,host,cover_name,status,owner_id,group_id,perm_owner,perm_group,perm_members,perm_anon,created,modified) VALUES ("
            . "'" . DB_escapeString(substr($title, 0, 255)) . "',"
            . "'" . DB_escapeString($description) . "',"
            . "'" . DB_escapeString(substr($host,0,255)) . "',"
            . "'" . DB_escapeString($coverName) . "',"
            . "'" . DB_escapeString($status) . "',"
            . $owner . "," . $groupId . "," . $permOwner . "," . $permGroup . "," . $permMembers . "," . $permAnon . ","
            . "'" . DB_escapeString($now) . "',"
            . "'" . DB_escapeString($now) . "')"
        );
        if (DB_error()) {
            return false;
        }
        $programId = (int) DB_insertId();
    }

    if (function_exists('PLG_itemSaved')) {
        PLG_itemSaved(RADIO_externalId('program', $programId), 'radio');
    }
    return $programId;
}

function RADIO_deleteProgram($id)
{
    global $_TABLES;
    $id = (int) $id;
    $program = $id > 0 ? RADIO_getProgram($id, false) : false;
    if ($program === false || !RADIO_hasEditAccess($program)) {
        return false;
    }
    if ($program !== false && !empty($program['cover_name'])) {
        RADIO_deleteCover($program['cover_name']);
    }
    DB_query("DELETE FROM {$_TABLES['radio_schedule']} WHERE program_id=" . $id);
    DB_query("DELETE FROM {$_TABLES['radio_program_items']} WHERE program_id=" . $id);
    DB_query("DELETE FROM {$_TABLES['radio_programs']} WHERE program_id=" . $id);
    if (DB_error()) {
        return false;
    }
    if (function_exists('PLG_itemDeleted')) {
        PLG_itemDeleted(RADIO_externalId('program', $id), 'radio');
    }
    return true;
}

function RADIO_getProgramItems($programId)
{
    global $_TABLES;
    $programId = (int) $programId;
    $sql = "SELECT pi.item_id,pi.sort_order,m.* "
        . "FROM {$_TABLES['radio_program_items']} pi "
        . "INNER JOIN {$_TABLES['radio_media']} m ON m.media_id=pi.media_id "
        . "WHERE pi.program_id=" . $programId . " "
        . "ORDER BY pi.sort_order ASC, pi.item_id ASC";
    $result = DB_query($sql);
    $items = array();
    while ($row = DB_fetchArray($result)) {
        $items[] = RADIO_mergeSiteMediaState($row);
    }
    return $items;
}

function RADIO_normalizeProgramItemOrder($programId)
{
    global $_TABLES;

    $items = RADIO_getProgramItems((int) $programId);
    $sort = 10;
    foreach ($items as $item) {
        DB_query(
            "UPDATE {$_TABLES['radio_program_items']} SET sort_order=" . $sort
            . " WHERE item_id=" . (int) $item['item_id']
            . " AND program_id=" . (int) $programId
        );
        if (DB_error()) {
            return false;
        }
        $sort += 10;
    }
    return true;
}

function RADIO_addProgramItem($programId, $mediaId, $afterItemId = 0)
{
    global $_TABLES;
    $programId = (int) $programId;
    $mediaId = (int) $mediaId;
    $afterItemId = (int) $afterItemId;
    $program = RADIO_getProgram($programId, false);
    $media = RADIO_getMedia($mediaId, false);
    if ($program === false || !RADIO_hasEditAccess($program)
        || $media === false || !RADIO_hasReadAccess($media)
        || !RADIO_isBroadcastAvailable($media)) {
        return false;
    }

    $items = RADIO_getProgramItems($programId);
    $sort = 10;

    if ($afterItemId > 0 && count($items) > 0) {
        $currentIndex = -1;
        for ($i = 0; $i < count($items); $i++) {
            if ((int) $items[$i]['item_id'] === $afterItemId) {
                $currentIndex = $i;
                break;
            }
        }

        if ($currentIndex >= 0) {
            $currentSort = (int) $items[$currentIndex]['sort_order'];
            if ($currentIndex + 1 < count($items)) {
                $nextSort = (int) $items[$currentIndex + 1]['sort_order'];
                if (($nextSort - $currentSort) < 2) {
                    if (!RADIO_normalizeProgramItemOrder($programId)) {
                        return false;
                    }
                    $items = RADIO_getProgramItems($programId);
                    for ($i = 0; $i < count($items); $i++) {
                        if ((int) $items[$i]['item_id'] === $afterItemId) {
                            $currentIndex = $i;
                            break;
                        }
                    }
                    $currentSort = (int) $items[$currentIndex]['sort_order'];
                    $nextSort = $currentIndex + 1 < count($items)
                        ? (int) $items[$currentIndex + 1]['sort_order']
                        : $currentSort + 10;
                }
                $sort = (int) floor(($currentSort + $nextSort) / 2);
            } else {
                $sort = $currentSort + 10;
            }
        } else {
            $max = DB_getItem($_TABLES['radio_program_items'], 'MAX(sort_order)', 'program_id=' . $programId);
            $sort = ((int) $max) + 10;
        }
    } else {
        $max = DB_getItem($_TABLES['radio_program_items'], 'MAX(sort_order)', 'program_id=' . $programId);
        $sort = ((int) $max) + 10;
    }

    DB_query(
        "INSERT INTO {$_TABLES['radio_program_items']} (program_id,media_id,sort_order) VALUES ("
        . $programId . "," . $mediaId . "," . $sort . ")"
    );
    return !DB_error();
}

function RADIO_removeProgramItem($itemId, $programId)
{
    global $_TABLES;
    $program = RADIO_getProgram((int) $programId, false);
    if ($program === false || !RADIO_hasEditAccess($program)) {
        return false;
    }
    DB_query(
        "DELETE FROM {$_TABLES['radio_program_items']} WHERE item_id=" . (int) $itemId
        . " AND program_id=" . (int) $programId
    );
    return !DB_error();
}

function RADIO_moveProgramItem($itemId, $programId, $direction)
{
    global $_TABLES;
    $program = RADIO_getProgram((int) $programId, false);
    if ($program === false || !RADIO_hasEditAccess($program)) {
        return false;
    }
    $items = RADIO_getProgramItems($programId);
    $index = -1;
    for ($i = 0; $i < count($items); $i++) {
        if ((int) $items[$i]['item_id'] === (int) $itemId) {
            $index = $i;
            break;
        }
    }
    if ($index < 0) {
        return false;
    }
    $other = $direction === 'up' ? $index - 1 : $index + 1;
    if ($other < 0 || $other >= count($items)) {
        return true;
    }

    $aId = (int) $items[$index]['item_id'];
    $bId = (int) $items[$other]['item_id'];
    $aSort = (int) $items[$index]['sort_order'];
    $bSort = (int) $items[$other]['sort_order'];
    DB_query("UPDATE {$_TABLES['radio_program_items']} SET sort_order=" . $bSort . " WHERE item_id=" . $aId);
    DB_query("UPDATE {$_TABLES['radio_program_items']} SET sort_order=" . $aSort . " WHERE item_id=" . $bId);
    return !DB_error();
}


function RADIO_scheduleRecurrences()
{
    return array('once', 'daily', 'weekly', 'weekdays');
}

function RADIO_parseDateTimeLocal($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return false;
    }
    return $timestamp;
}

function RADIO_scheduleWeekdaysFromData($data)
{
    $days = array();
    if (!empty($data['weekdays']) && is_array($data['weekdays'])) {
        foreach ($data['weekdays'] as $day) {
            $day = (int) $day;
            if ($day >= 1 && $day <= 7) {
                $days[$day] = $day;
            }
        }
    }
    ksort($days);
    return implode(',', $days);
}

function RADIO_saveSchedule($id, $data, &$error)
{
    global $_TABLES;
    $error = '';
    if (!SEC_hasRights('radio.schedule')) {
        $error = 'access_denied';
        return false;
    }

    $programId = isset($data['program_id']) ? (int) $data['program_id'] : 0;
    $program = RADIO_getProgram($programId, false);
    if ($program === false || (!RADIO_hasReadAccess($program) && !RADIO_hasEditAccess($program))) {
        $error = 'schedule_program_invalid';
        return false;
    }

    $startTs = RADIO_parseDateTimeLocal(isset($data['starts_at']) ? $data['starts_at'] : '');
    $endTs = RADIO_parseDateTimeLocal(isset($data['ends_at']) ? $data['ends_at'] : '');
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        $error = 'schedule_time_invalid';
        return false;
    }

    $recurrence = isset($data['recurrence']) ? $data['recurrence'] : 'once';
    if (!in_array($recurrence, RADIO_scheduleRecurrences(), true)) {
        $recurrence = 'once';
    }

    $weekdays = RADIO_scheduleWeekdaysFromData($data);
    if ($recurrence === 'weekdays' && $weekdays === '') {
        $error = 'schedule_weekdays_required';
        return false;
    }

    $activeFrom = isset($data['active_from']) ? trim((string) $data['active_from']) : '';
    $activeUntil = isset($data['active_until']) ? trim((string) $data['active_until']) : '';
    if ($activeFrom === '') {
        $activeFrom = date('Y-m-d', $startTs);
    }
    if ($activeUntil !== '' && strtotime($activeUntil . ' 23:59:59') < strtotime($activeFrom . ' 00:00:00')) {
        $error = 'schedule_date_invalid';
        return false;
    }

    $candidate = array(
        'schedule_id' => (int) $id,
        'program_id' => $programId,
        'starts_at' => date('Y-m-d H:i:s', $startTs),
        'ends_at' => date('Y-m-d H:i:s', $endTs),
        'recurrence' => $recurrence,
        'weekdays' => $weekdays,
        'active_from' => $activeFrom,
        'active_until' => $activeUntil,
        'enabled' => !empty($data['enabled']) ? 1 : 0
    );

    if ($candidate['enabled'] && RADIO_scheduleHasConflict($candidate, (int) $id)) {
        $error = 'schedule_conflict';
        return false;
    }

    if ((int) $id > 0) {
        DB_query(
            "UPDATE {$_TABLES['radio_schedule']} SET "
            . "program_id=" . $programId . ","
            . "starts_at='" . DB_escapeString($candidate['starts_at']) . "',"
            . "ends_at='" . DB_escapeString($candidate['ends_at']) . "',"
            . "recurrence='" . DB_escapeString($recurrence) . "',"
            . "weekdays='" . DB_escapeString($weekdays) . "',"
            . "active_from='" . DB_escapeString($activeFrom) . "',"
            . "active_until=" . ($activeUntil === '' ? "NULL" : "'" . DB_escapeString($activeUntil) . "'") . ","
            . "enabled=" . (int) $candidate['enabled'] . " "
            . "WHERE schedule_id=" . (int) $id
        );
        return DB_error() ? false : (int) $id;
    }

    DB_query(
        "INSERT INTO {$_TABLES['radio_schedule']} "
        . "(program_id,starts_at,ends_at,recurrence,weekdays,active_from,active_until,enabled) VALUES ("
        . $programId . ","
        . "'" . DB_escapeString($candidate['starts_at']) . "',"
        . "'" . DB_escapeString($candidate['ends_at']) . "',"
        . "'" . DB_escapeString($recurrence) . "',"
        . "'" . DB_escapeString($weekdays) . "',"
        . "'" . DB_escapeString($activeFrom) . "',"
        . ($activeUntil === '' ? "NULL" : "'" . DB_escapeString($activeUntil) . "'") . ","
        . (int) $candidate['enabled'] . ")"
    );
    return DB_error() ? false : (int) DB_insertId();
}

function RADIO_getSchedule($id)
{
    global $_TABLES;
    $id = (int) $id;
    if ($id < 1) {
        return false;
    }
    $result = DB_query("SELECT s.*,p.title AS program_title,p.status AS program_status "
        . "FROM {$_TABLES['radio_schedule']} s "
        . "INNER JOIN {$_TABLES['radio_programs']} p ON p.program_id=s.program_id "
        . "WHERE s.schedule_id=" . $id);
    return DB_numRows($result) > 0 ? DB_fetchArray($result) : false;
}

function RADIO_getSchedules($enabledOnly)
{
    global $_TABLES;
    $where = $enabledOnly ? ' WHERE s.enabled=1' : '';
    $result = DB_query("SELECT s.*,p.title AS program_title,p.status AS program_status "
        . "FROM {$_TABLES['radio_schedule']} s "
        . "INNER JOIN {$_TABLES['radio_programs']} p ON p.program_id=s.program_id"
        . $where
        . ($enabledOnly ? COM_getPermSQL('AND', 0, 2, 'p') : '')
        . " ORDER BY s.starts_at ASC,s.schedule_id ASC");
    $items = array();
    while ($row = DB_fetchArray($result)) {
        $items[] = $row;
    }
    return $items;
}

function RADIO_deleteSchedule($id)
{
    global $_TABLES;
    if (!SEC_hasRights('radio.schedule')) {
        return false;
    }
    DB_query("DELETE FROM {$_TABLES['radio_schedule']} WHERE schedule_id=" . (int) $id);
    return !DB_error();
}

function RADIO_scheduleDuration($schedule)
{
    $start = strtotime($schedule['starts_at']);
    $end = strtotime($schedule['ends_at']);
    return ($start !== false && $end !== false && $end > $start) ? ($end - $start) : 0;
}

function RADIO_scheduleOccursOnDate($schedule, $date)
{
    $dayStart = strtotime($date . ' 00:00:00');
    if ($dayStart === false) {
        return false;
    }

    $activeFrom = !empty($schedule['active_from'])
        ? strtotime($schedule['active_from'] . ' 00:00:00')
        : strtotime(date('Y-m-d', strtotime($schedule['starts_at'])) . ' 00:00:00');
    $activeUntil = !empty($schedule['active_until'])
        ? strtotime($schedule['active_until'] . ' 23:59:59')
        : false;

    if ($dayStart < $activeFrom || ($activeUntil !== false && $dayStart > $activeUntil)) {
        return false;
    }

    $anchor = strtotime($schedule['starts_at']);
    if ($anchor === false) {
        return false;
    }
    $anchorDate = date('Y-m-d', $anchor);

    if ($schedule['recurrence'] === 'once') {
        return $date === $anchorDate;
    }
    if ($schedule['recurrence'] === 'daily') {
        return $dayStart >= strtotime($anchorDate . ' 00:00:00');
    }
    if ($schedule['recurrence'] === 'weekly') {
        if ((int) date('N', $dayStart) !== (int) date('N', $anchor)) {
            return false;
        }
        $days = (int) floor(($dayStart - strtotime($anchorDate . ' 00:00:00')) / 86400);
        return $days >= 0 && ($days % 7) === 0;
    }
    if ($schedule['recurrence'] === 'weekdays') {
        $allowed = array_filter(array_map('intval', explode(',', $schedule['weekdays'])));
        return $dayStart >= strtotime($anchorDate . ' 00:00:00')
            && in_array((int) date('N', $dayStart), $allowed, true);
    }
    return false;
}

function RADIO_scheduleOccurrence($schedule, $date)
{
    if (!RADIO_scheduleOccursOnDate($schedule, $date)) {
        return false;
    }
    $anchorStart = strtotime($schedule['starts_at']);
    $duration = RADIO_scheduleDuration($schedule);
    if ($anchorStart === false || $duration < 1) {
        return false;
    }
    $start = strtotime($date . ' ' . date('H:i:s', $anchorStart));
    if ($start === false) {
        return false;
    }
    return array(
        'schedule_id' => (int) $schedule['schedule_id'],
        'program_id' => (int) $schedule['program_id'],
        'program_title' => isset($schedule['program_title']) ? $schedule['program_title'] : '',
        'program_status' => isset($schedule['program_status']) ? $schedule['program_status'] : '',
        'start' => $start,
        'end' => $start + $duration,
        'recurrence' => $schedule['recurrence']
    );
}

function RADIO_getOccurrences($fromTs, $toTs, $enabledOnly)
{
    $fromTs = (int) $fromTs;
    $toTs = (int) $toTs;
    if ($toTs < $fromTs) {
        return array();
    }

    $schedules = RADIO_getSchedules($enabledOnly);
    $occurrences = array();
    $dateTs = strtotime(date('Y-m-d', $fromTs) . ' 00:00:00');
    $lastDateTs = strtotime(date('Y-m-d', $toTs) . ' 00:00:00');

    while ($dateTs <= $lastDateTs) {
        $date = date('Y-m-d', $dateTs);
        foreach ($schedules as $schedule) {
            $occurrence = RADIO_scheduleOccurrence($schedule, $date);
            if ($occurrence !== false && $occurrence['end'] > $fromTs && $occurrence['start'] < $toTs) {
                $occurrences[] = $occurrence;
            }
        }
        $dateTs = strtotime('+1 day', $dateTs);
    }

    usort($occurrences, 'RADIO_compareOccurrences');
    return $occurrences;
}

function RADIO_compareOccurrences($a, $b)
{
    if ($a['start'] === $b['start']) {
        return $a['schedule_id'] - $b['schedule_id'];
    }
    return $a['start'] < $b['start'] ? -1 : 1;
}

function RADIO_scheduleHasConflict($candidate, $excludeId)
{
    $start = strtotime($candidate['active_from'] . ' 00:00:00');
    $endDate = $candidate['active_until'] !== ''
        ? strtotime($candidate['active_until'] . ' 23:59:59')
        : strtotime('+28 days', $start);
    if ($candidate['recurrence'] === 'once') {
        $start = strtotime($candidate['starts_at']);
        $endDate = strtotime($candidate['ends_at']);
    } else {
        $endDate = min($endDate, strtotime('+28 days', $start));
    }

    $candidate['schedule_id'] = (int) $excludeId;
    $candidateOccurrences = array();
    $dateTs = strtotime(date('Y-m-d', $start) . ' 00:00:00');
    $lastDateTs = strtotime(date('Y-m-d', $endDate) . ' 00:00:00');
    while ($dateTs <= $lastDateTs) {
        $occ = RADIO_scheduleOccurrence($candidate, date('Y-m-d', $dateTs));
        if ($occ !== false) {
            $candidateOccurrences[] = $occ;
        }
        $dateTs = strtotime('+1 day', $dateTs);
    }

    $existing = RADIO_getOccurrences($start, $endDate + 1, true);
    foreach ($candidateOccurrences as $candidateOccurrence) {
        foreach ($existing as $existingOccurrence) {
            if ((int) $existingOccurrence['schedule_id'] === (int) $excludeId) {
                continue;
            }
            if ($candidateOccurrence['start'] < $existingOccurrence['end']
                && $candidateOccurrence['end'] > $existingOccurrence['start']) {
                return true;
            }
        }
    }
    return false;
}

function RADIO_getNowPlaying($timestamp)
{
    $timestamp = $timestamp ? (int) $timestamp : time();
    $occurrences = RADIO_getOccurrences($timestamp, $timestamp + 1, true);
    foreach ($occurrences as $occurrence) {
        if ($occurrence['start'] <= $timestamp && $occurrence['end'] > $timestamp
            && $occurrence['program_status'] === 'published') {
            $occurrence['elapsed'] = $timestamp - $occurrence['start'];
            $occurrence['remaining'] = $occurrence['end'] - $timestamp;
            $occurrence['url'] = plugin_idtourl_radio('program', 'program:' . $occurrence['program_id']);
            return $occurrence;
        }
    }
    return false;
}

function RADIO_getUpcoming($limit, $fromTs)
{
    $limit = max(1, min(50, (int) $limit));
    $fromTs = $fromTs ? (int) $fromTs : time();
    $occurrences = RADIO_getOccurrences($fromTs, strtotime('+14 days', $fromTs), true);
    $items = array();
    foreach ($occurrences as $occurrence) {
        if ($occurrence['start'] >= $fromTs && $occurrence['program_status'] === 'published') {
            $occurrence['url'] = plugin_idtourl_radio('program', 'program:' . $occurrence['program_id']);
            $items[] = $occurrence;
            if (count($items) >= $limit) {
                break;
            }
        }
    }
    return $items;
}






function RADIO_getPodcastItems($limit, $since)
{
    global $_TABLES;

    $limit = max(1, min(100, (int) $limit));
    $sql = "SELECT * FROM {$_TABLES['radio_media']} "
        . "WHERE status='published'"
        . RADIO_mediaAvailabilitySql('on_demand', '')
        . " AND media_type='podcast' AND storage_name<>''"
        . RADIO_permissionSql('radio_media', 1);

    if ((int) $since > 0) {
        $sql .= " AND modified >= '" . DB_escapeString(date('Y-m-d H:i:s', (int) $since)) . "'";
    }

    $sql .= " ORDER BY modified DESC, media_id DESC LIMIT " . $limit;

    $result = DB_query($sql);
    $items = array();
    while ($row = DB_fetchArray($result)) {
        $items[] = $row;
    }
    return $items;
}

function RADIO_podcastFeedUrl()
{
    global $_CONF;
    return $_CONF['site_url'] . '/radio/podcast.php';
}

function RADIO_feedCollectionOptions($limit)
{
    $options = array('limit' => 20, 'since' => 0);
    $limit = trim((string) $limit);

    if ($limit === '') {
        return $options;
    }

    if (substr($limit, -1) === 'h') {
        $hours = (int) substr($limit, 0, -1);
        if ($hours > 0) {
            $options['since'] = time() - ($hours * 3600);
            $options['limit'] = 100;
        }
        return $options;
    }

    if (ctype_digit($limit)) {
        $options['limit'] = max(1, min(100, (int) $limit));
    }

    return $options;
}

function plugin_getfeednames_radio()
{
    if (!RADIO_publicAccessAllowed()) {
        return array();
    }
    return array(
        array('id' => 'podcast', 'name' => 'Radio Podcasts')
    );
}

function plugin_getfeedcontent_radio($feed, &$link, &$update, $feedType = '', $feedVersion = '')
{
    if (!RADIO_publicAccessAllowed()) {
        $link = '';
        $update = '';
        return array();
    }
    global $_TABLES;

    $link = RADIO_podcastFeedUrl();
    $update = '';

    $feedId = (int) $feed;
    if ($feedId <= 0) {
        return array();
    }

    $result = DB_query(
        "SELECT topic,limits,content_length FROM {$_TABLES['syndication']} WHERE fid=" . $feedId
    );
    $syndication = DB_fetchArray($result);
    if (!is_array($syndication)) {
        return array();
    }

    $source = isset($syndication['topic']) ? (string) $syndication['topic'] : 'podcast';
    if ($source !== '' && $source !== 'podcast') {
        return array();
    }

    $options = RADIO_feedCollectionOptions(isset($syndication['limits']) ? $syndication['limits'] : '');
    $items = RADIO_getPodcastItems($options['limit'], $options['since']);
    $contentLength = isset($syndication['content_length']) ? (int) $syndication['content_length'] : 0;
    $entries = array();
    $ids = array();

    foreach ($items as $item) {
        $summary = isset($item['description']) ? $item['description'] : '';
        $summary = PLG_replaceTags($summary);
        if ($contentLength > 1 && function_exists('COM_truncateHTML')) {
            $summary = COM_truncateHTML($summary, $contentLength, ' ...');
        }

        $entry = array(
            'title' => $item['title'],
            'summary' => $summary,
            'text' => $summary,
            'link' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $item['media_id'])),
            'uid' => isset($item['owner_id']) ? (int) $item['owner_id'] : 0,
            'author' => '',
            'date' => strtotime($item['modified']),
            'format' => 'html'
        );

        if (function_exists('PLG_getFeedElementExtensions')) {
            $entry['extensions'] = PLG_getFeedElementExtensions(
                'radio',
                RADIO_externalId('media', (int) $item['media_id']),
                (string) $feedType,
                (string) $feedVersion,
                'podcast',
                $feedId
            );
        }

        $entries[] = $entry;
        $ids[] = RADIO_externalId('media', (int) $item['media_id']) . ':' . $item['modified'];
    }

    $update = implode(',', $ids);
    return $entries;
}

function plugin_feedupdatecheck_radio(
    $feed,
    $topic,
    $updateData,
    $limit,
    $updatedType = '',
    $updatedTopic = '',
    $updatedId = ''
) {
    if (!RADIO_publicAccessAllowed()) {
        return true;
    }

    if ($updatedType === 'radio' && $updatedId !== '') {
        return false;
    }

    $options = RADIO_feedCollectionOptions($limit);
    $items = RADIO_getPodcastItems($options['limit'], $options['since']);
    $ids = array();

    foreach ($items as $item) {
        $ids[] = RADIO_externalId('media', (int) $item['media_id']) . ':' . $item['modified'];
    }

    return implode(',', $ids) === (string) $updateData;
}

function RADIO_replayRetentionDays()
{
    global $_RADIO_CONF;
    return isset($_RADIO_CONF['default_replay_days'])
        ? max(0, (int) $_RADIO_CONF['default_replay_days'])
        : 30;
}

function RADIO_getReplayOccurrences($limit, $timestamp)
{
    $limit = max(1, min(100, (int) $limit));
    $timestamp = $timestamp ? (int) $timestamp : time();
    $days = RADIO_replayRetentionDays();
    if ($days < 1) {
        return array();
    }

    $from = strtotime('-' . $days . ' days', $timestamp);
    $occurrences = RADIO_getOccurrences($from, $timestamp + 1, true);
    $replays = array();

    foreach ($occurrences as $occurrence) {
        if ($occurrence['end'] > $timestamp || $occurrence['program_status'] !== 'published') {
            continue;
        }

        $program = RADIO_getProgram($occurrence['program_id'], true);
        if ($program === false) {
            continue;
        }

        $replays[] = array(
            'replay_id' => 'replay:' . (int) $occurrence['schedule_id'] . ':' . (int) $occurrence['start'],
            'schedule_id' => (int) $occurrence['schedule_id'],
            'program_id' => (int) $occurrence['program_id'],
            'program_external_id' => RADIO_externalId('program', (int) $occurrence['program_id']),
            'title' => $occurrence['program_title'],
            'description' => $program['description'],
            'start' => (int) $occurrence['start'],
            'end' => (int) $occurrence['end'],
            'available_until' => strtotime('+' . $days . ' days', (int) $occurrence['end']),
            'url' => RADIO_replayUrl((int) $occurrence['schedule_id'], (int) $occurrence['start'])
        );
    }

    usort($replays, 'RADIO_compareReplayOccurrences');
    if (count($replays) > $limit) {
        $replays = array_slice($replays, 0, $limit);
    }
    return $replays;
}

function RADIO_getProgramUpcomingOccurrences($programId, $limit, $timestamp)
{
    $programId = (int) $programId;
    $limit = max(1, min(20, (int) $limit));
    $timestamp = $timestamp ? (int) $timestamp : time();

    if ($programId < 1) {
        return array();
    }

    $occurrences = RADIO_getOccurrences(
        $timestamp,
        strtotime('+90 days', $timestamp),
        true
    );
    $items = array();

    foreach ($occurrences as $occurrence) {
        if ((int) $occurrence['program_id'] !== $programId
            || (int) $occurrence['end'] <= $timestamp) {
            continue;
        }

        $items[] = $occurrence;
        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function RADIO_getProgramReplayOccurrences($programId, $limit, $timestamp)
{
    $programId = (int) $programId;
    $limit = max(1, min(20, (int) $limit));
    $timestamp = $timestamp ? (int) $timestamp : time();

    if ($programId < 1) {
        return array();
    }

    $replays = RADIO_getReplayOccurrences(100, $timestamp);
    $items = array();

    foreach ($replays as $replay) {
        if ((int) $replay['program_id'] !== $programId) {
            continue;
        }

        $items[] = $replay;
        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function RADIO_compareReplayOccurrences($a, $b)
{
    if ($a['start'] === $b['start']) {
        return 0;
    }
    return $a['start'] > $b['start'] ? -1 : 1;
}

function RADIO_replayUrl($scheduleId, $start)
{
    global $_CONF;
    return $_CONF['site_url'] . '/radio/replay.php?schedule_id=' . (int) $scheduleId
        . '&start=' . (int) $start;
}

function RADIO_getReplay($scheduleId, $start)
{
    $schedule = RADIO_getSchedule((int) $scheduleId);
    if ($schedule === false || empty($schedule['enabled']) || $schedule['program_status'] !== 'published') {
        return false;
    }

    $date = date('Y-m-d', (int) $start);
    $occurrence = RADIO_scheduleOccurrence($schedule, $date);
    if ($occurrence === false || (int) $occurrence['start'] !== (int) $start) {
        return false;
    }

    $now = time();
    if ($occurrence['end'] > $now) {
        return false;
    }

    $days = RADIO_replayRetentionDays();
    if ($days < 1 || strtotime('+' . $days . ' days', $occurrence['end']) < $now) {
        return false;
    }

    $program = RADIO_getProgram($occurrence['program_id'], true);
    if ($program === false) {
        return false;
    }

    return array(
        'replay_id' => 'replay:' . (int) $occurrence['schedule_id'] . ':' . (int) $occurrence['start'],
        'schedule_id' => (int) $occurrence['schedule_id'],
        'program_id' => (int) $occurrence['program_id'],
        'program_external_id' => RADIO_externalId('program', (int) $occurrence['program_id']),
        'title' => $occurrence['program_title'],
        'description' => $program['description'],
        'start' => (int) $occurrence['start'],
        'end' => (int) $occurrence['end'],
        'available_until' => strtotime('+' . $days . ' days', $occurrence['end']),
        'items' => RADIO_getProgramItems((int) $occurrence['program_id']),
        'url' => RADIO_replayUrl((int) $occurrence['schedule_id'], (int) $occurrence['start'])
    );
}


function RADIO_getReplayService($options)
{
    $limit = isset($options['limit']) ? (int) $options['limit'] : 20;
    $timestamp = isset($options['timestamp']) ? (int) $options['timestamp'] : time();
    $items = RADIO_getReplayOccurrences($limit, $timestamp);
    $result = array();

    foreach ($items as $item) {
        $result[] = array(
            'id' => $item['replay_id'],
            'type' => 'radio',
            'subtype' => 'replay',
            'program_id' => $item['program_external_id'],
            'title' => $item['title'],
            'description' => $item['description'],
            'url' => $item['url'],
            'date-start' => date('c', $item['start']),
            'date-end' => date('c', $item['end']),
            'available-until' => date('c', $item['available_until'])
        );
    }

    return $result;
}

function RADIO_getRotationMediaByTypes($types)
{
    global $_TABLES;

    if (!is_array($types) || count($types) === 0) {
        return array();
    }

    $safe = array();
    foreach ($types as $type) {
        $type = trim((string) $type);
        if ($type !== '') {
            $safe[] = "'" . DB_escapeString($type) . "'";
        }
    }
    if (count($safe) === 0) {
        return array();
    }

    $where = "WHERE duration>0 AND media_type IN (" . implode(',', $safe) . ") ";
    if (!RADIO_sharedLibraryEnabled()) {
        $where = "WHERE status='published'"
            . RADIO_mediaAvailabilitySql('broadcast', '')
            . RADIO_mediaAvailabilitySql('automatic_rotation', '')
            . " AND duration>0 AND media_type IN (" . implode(',', $safe) . ") "
            . RADIO_permissionSql('radio_media', 0) . " ";
    }

    $result = DB_query(
        "SELECT * FROM {$_TABLES['radio_media']} "
        . $where
        . "ORDER BY media_id ASC"
    );

    $items = array();
    while ($row = DB_fetchArray($result)) {
        $row = RADIO_mergeSiteMediaState($row);
        if (RADIO_sharedLibraryEnabled()
            && (!RADIO_isAutomaticRotationAvailable($row) || !RADIO_hasReadAccess($row))) {
            continue;
        }
        $items[] = $row;
    }
    return $items;
}

function RADIO_parseTypeWeights($value)
{
    $allowed = array('music','podcast','interview','chronicle','show');
    $weights = array();

    foreach (explode(',', (string) $value) as $part) {
        $bits = explode('=', trim($part), 2);
        if (count($bits) !== 2) {
            continue;
        }
        $type = trim($bits[0]);
        $weight = max(0, min(20, (int) trim($bits[1])));
        if (in_array($type, $allowed, true) && $weight > 0) {
            $weights[$type] = $weight;
        }
    }

    if (count($weights) === 0) {
        $weights = array('music' => 5, 'podcast' => 2, 'interview' => 2, 'chronicle' => 2);
    }
    return $weights;
}

function RADIO_rotationCompare($a, $b)
{
    if ($a['_rotation_key'] === $b['_rotation_key']) {
        return (int) $a['media_id'] - (int) $b['media_id'];
    }
    return strcmp($a['_rotation_key'], $b['_rotation_key']);
}

function RADIO_rotationShuffle($items, $seed, $scope)
{
    $seed = (string) $seed;
    foreach ($items as $index => $item) {
        $items[$index]['_rotation_key'] = sha1($seed . ':' . $scope . ':' . (int) $item['media_id']);
    }
    if (count($items) > 1) {
        usort($items, 'RADIO_rotationCompare');
    }
    foreach ($items as $index => $item) {
        unset($items[$index]['_rotation_key']);
    }
    return $items;
}

function RADIO_buildWeightedRegularSequence($seed)
{
    global $_RADIO_CONF;

    $weights = RADIO_parseTypeWeights(
        isset($_RADIO_CONF['fallback_type_weights']) ? $_RADIO_CONF['fallback_type_weights'] : ''
    );
    $queues = array();
    foreach ($weights as $type => $weight) {
        $queues[$type] = RADIO_rotationShuffle(
            RADIO_getRotationMediaByTypes(array($type)),
            $seed,
            'type-' . $type
        );
    }

    $pattern = array();
    foreach ($weights as $type => $weight) {
        for ($i = 0; $i < $weight; $i++) {
            $pattern[] = $type;
        }
    }

    $sequence = array();
    while (true) {
        $added = false;
        foreach ($pattern as $type) {
            if (!empty($queues[$type])) {
                $sequence[] = array_shift($queues[$type]);
                $added = true;
            }
        }
        if (!$added) {
            break;
        }
    }

    return $sequence;
}

function RADIO_buildFreshRotationSequence($date, $seed = null)
{
    global $_RADIO_CONF;

    if ($seed === null || $seed === '') {
        $seed = $date;
    }

    $regular = RADIO_buildWeightedRegularSequence($seed);
    if (count($regular) === 0) {
        return array();
    }

    $jingles = RADIO_rotationShuffle(
        RADIO_getRotationMediaByTypes(array('jingle')),
        $seed,
        'jingle'
    );
    $announcements = RADIO_rotationShuffle(
        RADIO_getRotationMediaByTypes(array('announcement','promo')),
        $seed,
        'announcement'
    );

    $jingleInterval = isset($_RADIO_CONF['fallback_jingle_interval'])
        ? max(0, (int) $_RADIO_CONF['fallback_jingle_interval'])
        : 4;
    $announcementInterval = isset($_RADIO_CONF['fallback_announcement_interval'])
        ? max(0, (int) $_RADIO_CONF['fallback_announcement_interval'])
        : 8;

    $sequence = array();
    $regularCount = 0;
    $jingleIndex = 0;
    $announcementIndex = 0;

    // Open every new fallback rotation cycle with a station jingle when one is available.
    if (count($jingles) > 0) {
        $sequence[] = $jingles[0];
        $jingleIndex = 1;
    }

    foreach ($regular as $item) {
        $sequence[] = $item;
        $regularCount++;

        if ($announcementInterval > 0 && count($announcements) > 0
            && ($regularCount % $announcementInterval) === 0) {
            $sequence[] = $announcements[$announcementIndex % count($announcements)];
            $announcementIndex++;
        }

        if ($jingleInterval > 0 && count($jingles) > 0
            && ($regularCount % $jingleInterval) === 0) {
            $sequence[] = $jingles[$jingleIndex % count($jingles)];
            $jingleIndex++;
        }
    }

    return $sequence;
}


function RADIO_rotationSnapshotSignature()
{
    global $_RADIO_CONF;

    $keys = array(
        'fallback_type_weights',
        'fallback_jingle_interval',
        'fallback_announcement_interval',
        'transition_mode',
        'crossfade_seconds'
    );
    $parts = array();
    foreach ($keys as $key) {
        $parts[] = $key . '=' . (isset($_RADIO_CONF[$key]) ? (string) $_RADIO_CONF[$key] : '');
    }
    return sha1(implode('|', $parts));
}

function RADIO_rotationSnapshotPath($date)
{
    $base = RADIO_storageDir();
    if ($base === '' || !RADIO_ensureStorage()) {
        return '';
    }
    $safeDate = preg_replace('/[^0-9-]/', '', (string) $date);
    return $base . 'rotation-' . $safeDate . '.json';
}

function RADIO_rebuildRotationNow($timestamp)
{
    $timestamp = $timestamp ? (int) $timestamp : time();
    $date = date('Y-m-d', $timestamp);
    $dates = array(
        $date,
        date('Y-m-d', $timestamp - 86400),
        date('Y-m-d', $timestamp + 86400)
    );

    $ok = true;
    foreach (array_unique($dates) as $snapshotDate) {
        $path = RADIO_rotationSnapshotPath($snapshotDate);
        if ($path !== '' && is_file($path) && !@unlink($path)) {
            $ok = false;
        }
    }

    if (!$ok) {
        return false;
    }

    $sequence = RADIO_buildFreshRotationSequence(
        $date,
        $timestamp . ':manual:' . sprintf('%.6F', microtime(true))
    );
    if (count($sequence) === 0) {
        return array(
            'sequence' => array(),
            'cycle_start' => $timestamp,
            'cycle_duration' => 0,
            'transition_mode' => RADIO_transitionMode(),
            'crossfade_seconds' => RADIO_crossfadeSeconds()
        );
    }

    if (!RADIO_saveRotationSnapshot($date, $sequence, $timestamp)) {
        return false;
    }

    return RADIO_rotationCycleState($date, $timestamp);
}

function RADIO_loadRotationSnapshot($date, $timestamp, &$nextCycleStart)
{
    global $_TABLES;

    $nextCycleStart = 0;
    $path = RADIO_rotationSnapshotPath($date);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return false;
    }

    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)
        || !isset($data['cycle_start']) || !isset($data['cycle_duration'])
        || !isset($data['transition_mode']) || !isset($data['crossfade_seconds'])
        || empty($data['items']) || !is_array($data['items'])) {
        return false;
    }

    $cycleStart = (int) $data['cycle_start'];
    $storedCycleDuration = max(1, (int) $data['cycle_duration']);
    $cycleEnd = $cycleStart + $storedCycleDuration;

    if ($timestamp >= $cycleEnd) {
        $nextCycleStart = $cycleEnd;
        return false;
    }

    $items = array();
    $ids = array();
    foreach ($data['items'] as $item) {
        if (!is_array($item) || empty($item['media_id'])) {
            continue;
        }
        $id = (int) $item['media_id'];
        if ($id < 1) {
            continue;
        }
        $items[$id] = $item;
        $ids[] = $id;
    }
    if (count($ids) === 0) {
        return false;
    }

    // Only deletion or leaving the published state may alter the active cycle.
    $result = DB_query(
        "SELECT media_id,status FROM {$_TABLES['radio_media']} WHERE media_id IN ("
        . implode(',', $ids) . ")"
    );
    $published = array();
    while ($row = DB_fetchArray($result)) {
        if (isset($row['status']) && $row['status'] === 'published') {
            $published[(int) $row['media_id']] = true;
        }
    }

    $sequence = array();
    foreach ($data['items'] as $item) {
        $id = isset($item['media_id']) ? (int) $item['media_id'] : 0;
        if ($id > 0 && isset($published[$id])) {
            $sequence[] = $item;
        }
    }

    $mode = (string) $data['transition_mode'];
    $seconds = (int) $data['crossfade_seconds'];
    $activeDuration = RADIO_rotationDuration($sequence, $mode, $seconds);

    if ($activeDuration < 1) {
        return array(
            'sequence' => array(),
            'cycle_start' => $cycleStart,
            'cycle_duration' => 0,
            'transition_mode' => $mode,
            'crossfade_seconds' => $seconds
        );
    }

    return array(
        'sequence' => $sequence,
        'cycle_start' => $cycleStart,
        'cycle_duration' => $activeDuration,
        'transition_mode' => $mode,
        'crossfade_seconds' => $seconds
    );
}

function RADIO_purgeRotationSnapshots($keepDays)
{
    $base = RADIO_storageDir();
    if ($base === '' || !is_dir($base)) {
        return;
    }

    $keepDays = max(2, (int) $keepDays);
    $cutoff = time() - ($keepDays * 86400);
    $files = glob($base . 'rotation-*.json');
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        if (is_file($file) && @filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

function RADIO_rotationSnapshotItem($item)
{
    return array(
        'media_id' => isset($item['media_id']) ? (int) $item['media_id'] : 0,
        'title' => isset($item['title']) ? (string) $item['title'] : '',
        'media_type' => isset($item['media_type']) ? (string) $item['media_type'] : 'music',
        'source_kind' => RADIO_sourceKind($item),
        'duration' => isset($item['duration']) ? max(0, (int) $item['duration']) : 0
    );
}

function RADIO_saveRotationSnapshot($date, $sequence, $cycleStart)
{
    $path = RADIO_rotationSnapshotPath($date);
    if ($path === '') {
        return false;
    }

    $items = array();
    foreach ($sequence as $item) {
        $snapshotItem = RADIO_rotationSnapshotItem($item);
        if ($snapshotItem['media_id'] > 0 && $snapshotItem['duration'] > 0) {
            $items[] = $snapshotItem;
        }
    }

    $mode = RADIO_transitionMode();
    $seconds = RADIO_crossfadeSeconds();
    $cycleDuration = RADIO_rotationDuration($items, $mode, $seconds);
    if ($cycleDuration < 1) {
        return false;
    }

    RADIO_purgeRotationSnapshots(7);

    $payload = json_encode(array(
        'date' => (string) $date,
        'signature' => RADIO_rotationSnapshotSignature(),
        'cycle_start' => (int) $cycleStart,
        'cycle_duration' => $cycleDuration,
        'transition_mode' => $mode,
        'crossfade_seconds' => $seconds,
        'items' => $items
    ));
    if (!is_string($payload)) {
        return false;
    }

    return @file_put_contents($path, $payload, LOCK_EX) !== false;
}

function RADIO_rotationCycleState($date, $timestamp)
{
    $timestamp = $timestamp ? (int) $timestamp : time();
    $nextCycleStart = 0;
    $snapshot = RADIO_loadRotationSnapshot($date, $timestamp, $nextCycleStart);

    if ($snapshot !== false) {
        return $snapshot;
    }

    $mode = RADIO_transitionMode();
    $seconds = RADIO_crossfadeSeconds();

    if ($nextCycleStart > 0) {
        $cycleStart = $nextCycleStart;
        $sequence = RADIO_buildFreshRotationSequence($date, $cycleStart);
        $cycleDuration = RADIO_rotationDuration($sequence, $mode, $seconds);

        while ($cycleDuration > 0 && ($cycleStart + $cycleDuration) <= $timestamp) {
            $cycleStart += $cycleDuration;
            $sequence = RADIO_buildFreshRotationSequence($date, $cycleStart);
            $cycleDuration = RADIO_rotationDuration($sequence, $mode, $seconds);
        }
    } else {
        $seed = $date . ':cycle:' . $timestamp;
        $sequence = RADIO_buildFreshRotationSequence($date, $seed);
        $cycleDuration = RADIO_rotationDuration($sequence, $mode, $seconds);

        if ($cycleDuration > 0) {
            $dayStart = strtotime($date . ' 00:00:00');
            $elapsedDay = max(0, $timestamp - $dayStart);
            $cycleStart = $dayStart + ((int) floor($elapsedDay / $cycleDuration) * $cycleDuration);
        } else {
            $cycleStart = $timestamp;
        }
    }

    if ($cycleDuration < 1) {
        return array(
            'sequence' => array(),
            'cycle_start' => $timestamp,
            'cycle_duration' => 0,
            'transition_mode' => $mode,
            'crossfade_seconds' => $seconds
        );
    }

    RADIO_saveRotationSnapshot($date, $sequence, $cycleStart);

    return array(
        'sequence' => array_map('RADIO_rotationSnapshotItem', $sequence),
        'cycle_start' => $cycleStart,
        'cycle_duration' => $cycleDuration,
        'transition_mode' => $mode,
        'crossfade_seconds' => $seconds
    );
}

function RADIO_buildRotationSequence($date)
{
    $state = RADIO_rotationCycleState($date, time());
    return isset($state['sequence']) ? $state['sequence'] : array();
}


function RADIO_rotationEligibilityDiagnostics()
{
    global $_TABLES, $_RADIO_CONF;

    $weights = RADIO_parseTypeWeights(
        isset($_RADIO_CONF['fallback_type_weights']) ? $_RADIO_CONF['fallback_type_weights'] : ''
    );
    $allowedTypes = array_merge(array_keys($weights), array('jingle','announcement','promo'));
    $rows = array();

    $result = DB_query(
        "SELECT * FROM {$_TABLES['radio_media']} ORDER BY media_id DESC"
    );

    while ($row = DB_fetchArray($result)) {
        $reasons = array();

        if ($row['status'] !== 'published') {
            $reasons[] = 'status';
        }
        if (array_key_exists('broadcast', $row) && empty($row['broadcast'])) {
            $reasons[] = 'broadcast';
        }
        if (array_key_exists('automatic_rotation', $row) && empty($row['automatic_rotation'])) {
            $reasons[] = 'automatic_rotation';
        }
        if ((int) $row['duration'] < 1) {
            $reasons[] = 'duration';
        }
        if (!in_array($row['media_type'], $allowedTypes, true)) {
            $reasons[] = 'type';
        }
        if ((int) $row['perm_anon'] < 2) {
            $reasons[] = 'anonymous';
        }

        $row['_rotation_eligible'] = count($reasons) === 0;
        $row['_rotation_reasons'] = $reasons;
        $rows[] = $row;
    }

    return $rows;
}

function RADIO_rotationDiagnostics($date)
{
    global $_RADIO_CONF;

    $sequence = RADIO_buildRotationSequence($date);
    $cycleDuration = RADIO_rotationDuration($sequence);
    $targetMinutes = isset($_RADIO_CONF['fallback_min_repeat_minutes'])
        ? max(0, (int) $_RADIO_CONF['fallback_min_repeat_minutes'])
        : 120;

    return array(
        'items' => count($sequence),
        'cycle_duration' => $cycleDuration,
        'cycle_minutes' => (int) floor($cycleDuration / 60),
        'target_repeat_minutes' => $targetMinutes,
        'repeat_target_met' => $targetMinutes === 0 || $cycleDuration >= ($targetMinutes * 60)
    );
}

function RADIO_transitionMode()
{
    global $_RADIO_CONF;

    $mode = isset($_RADIO_CONF['transition_mode'])
        ? strtolower(trim((string) $_RADIO_CONF['transition_mode']))
        : 'gapless';

    return in_array($mode, array('hard', 'gapless', 'crossfade'), true)
        ? $mode
        : 'gapless';
}

function RADIO_crossfadeSeconds()
{
    global $_RADIO_CONF;

    return isset($_RADIO_CONF['crossfade_seconds'])
        ? max(0, min(10, (int) $_RADIO_CONF['crossfade_seconds']))
        : 2;
}

function RADIO_transitionOverlapFor($current, $next, $mode, $seconds)
{
    $mode = strtolower(trim((string) $mode));
    $seconds = max(0, min(10, (int) $seconds));

    if ($mode !== 'crossfade'
        || !is_array($current) || !is_array($next)
        || !isset($current['media_type']) || !isset($next['media_type'])) {
        return 0;
    }

    if ($seconds < 1) {
        return 0;
    }

    $currentType = (string) $current['media_type'];
    $nextType = (string) $next['media_type'];
    $overlap = 0;

    if ($currentType === 'music' && $nextType === 'music') {
        $overlap = $seconds;
    } elseif ($currentType === 'jingle' && $nextType === 'music') {
        $overlap = max(1, (int) round($seconds / 2));
    } else {
        return 0;
    }

    $currentDuration = isset($current['duration']) ? (int) $current['duration'] : 0;
    $nextDuration = isset($next['duration']) ? (int) $next['duration'] : 0;
    if ($currentDuration < 2 || $nextDuration < 2) {
        return 0;
    }

    return min($overlap, $currentDuration - 1, $nextDuration - 1);
}

function RADIO_transitionOverlap($current, $next)
{
    return RADIO_transitionOverlapFor(
        $current,
        $next,
        RADIO_transitionMode(),
        RADIO_crossfadeSeconds()
    );
}

function RADIO_rotationDuration($sequence, $mode = null, $seconds = null)
{
    $duration = 0;
    $count = count($sequence);
    if ($count === 0) {
        return 0;
    }

    if ($mode === null) {
        $mode = RADIO_transitionMode();
    }
    if ($seconds === null) {
        $seconds = RADIO_crossfadeSeconds();
    }

    for ($i = 0; $i < $count; $i++) {
        $item = $sequence[$i];
        $next = $sequence[($i + 1) % $count];
        $slot = max(0, (int) $item['duration'])
            - RADIO_transitionOverlapFor($item, $next, $mode, $seconds);
        $duration += max(1, $slot);
    }
    return $duration;
}

function RADIO_getRotationState($timestamp)
{
    global $_RADIO_CONF;

    if (isset($_RADIO_CONF['fallback_enabled']) && empty($_RADIO_CONF['fallback_enabled'])) {
        return false;
    }

    $timestamp = $timestamp ? (int) $timestamp : time();
    $date = date('Y-m-d', $timestamp);
    $cycle = RADIO_rotationCycleState($date, $timestamp);
    $sequence = isset($cycle['sequence']) ? $cycle['sequence'] : array();
    $count = count($sequence);
    $cycleDuration = isset($cycle['cycle_duration']) ? (int) $cycle['cycle_duration'] : 0;
    $cycleStart = isset($cycle['cycle_start']) ? (int) $cycle['cycle_start'] : $timestamp;
    $transitionMode = isset($cycle['transition_mode']) ? $cycle['transition_mode'] : RADIO_transitionMode();
    $crossfadeSeconds = isset($cycle['crossfade_seconds']) ? (int) $cycle['crossfade_seconds'] : RADIO_crossfadeSeconds();
    if ($count === 0 || $cycleDuration < 1) {
        return false;
    }

    $cycleOffset = max(0, $timestamp - $cycleStart);
    if ($cycleOffset >= $cycleDuration) {
        $cycleOffset = $cycleOffset % $cycleDuration;
    }
    $cursor = 0;

    for ($i = 0; $i < $count; $i++) {
        $item = $sequence[$i];
        $next = $sequence[($i + 1) % $count];
        $nextNext = $sequence[($i + 2) % $count];
        $duration = (int) $item['duration'];
        if ($duration < 1) {
            continue;
        }

        $overlap = RADIO_transitionOverlapFor($item, $next, $transitionMode, $crossfadeSeconds);
        $slotDuration = max(1, $duration - $overlap);

        if ($cycleOffset < ($cursor + $slotDuration)) {
            return array(
                'source' => 'rotation',
                'date' => $date,
                'cycle_duration' => $cycleDuration,
                'cycle_start' => $cycleStart,
                'cycle_offset' => $cycleOffset,
                'media_id' => (int) $item['media_id'],
                'external_id' => RADIO_externalId('media', (int) $item['media_id']),
                'title' => $item['title'],
                'media_type' => $item['media_type'],
                'source_kind' => RADIO_sourceKind($item),
                'duration' => $duration,
                'slot_duration' => $slotDuration,
                'offset' => $cycleOffset - $cursor,
                'transition_mode' => $transitionMode,
                'transition_seconds' => $overlap,
                'stream_url' => RADIO_mediaUrl((int) $item['media_id'], false),
                'item_url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $item['media_id'])),
                'next_media' => array(
                    'media_id' => (int) $next['media_id'],
                    'external_id' => RADIO_externalId('media', (int) $next['media_id']),
                    'title' => $next['title'],
                    'media_type' => $next['media_type'],
                    'source_kind' => RADIO_sourceKind($next),
                    'duration' => (int) $next['duration'],
                    'stream_url' => RADIO_mediaUrl((int) $next['media_id'], false),
                    'item_url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $next['media_id']))
                ),
                'next_next_media' => array(
                    'media_id' => (int) $nextNext['media_id'],
                    'external_id' => RADIO_externalId('media', (int) $nextNext['media_id']),
                    'title' => $nextNext['title'],
                    'media_type' => $nextNext['media_type'],
                    'source_kind' => RADIO_sourceKind($nextNext),
                    'duration' => (int) $nextNext['duration'],
                    'stream_url' => RADIO_mediaUrl((int) $nextNext['media_id'], false),
                    'item_url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $nextNext['media_id']))
                )
            );
        }
        $cursor += $slotDuration;
    }

    return false;
}

function RADIO_programDuration($programId)
{
    $items = RADIO_getProgramItems($programId);
    $duration = 0;
    foreach ($items as $item) {
        if (RADIO_isBroadcastAvailable($item) && RADIO_hasReadAccess($item) && (int) $item['duration'] > 0) {
            $duration += (int) $item['duration'];
        }
    }
    return $duration;
}

function RADIO_resolveProgramPlayback($programId, $elapsed)
{
    $elapsed = max(0, (int) $elapsed);
    $rawItems = RADIO_getProgramItems($programId);
    $items = array();

    foreach ($rawItems as $item) {
        if (!RADIO_isBroadcastAvailable($item) || !RADIO_hasReadAccess($item)
            || (int) $item['duration'] < 1) {
            continue;
        }
        $items[] = $item;
    }

    $cursor = 0;
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        $item = $items[$i];
        $duration = (int) $item['duration'];
        if ($elapsed < ($cursor + $duration)) {
            $next = $i + 1 < $count ? $items[$i + 1] : false;
            $nextNext = $i + 2 < $count ? $items[$i + 2] : false;
            return array(
                'media_id' => (int) $item['media_id'],
                'external_id' => RADIO_externalId('media', (int) $item['media_id']),
                'title' => $item['title'],
                'media_type' => $item['media_type'],
                'source_kind' => RADIO_sourceKind($item),
                'duration' => $duration,
                'slot_duration' => $duration,
                'offset' => $elapsed - $cursor,
                'starts_after' => $cursor,
                'transition_mode' => RADIO_transitionMode() === 'hard' ? 'hard' : 'gapless',
                'transition_seconds' => 0,
                'stream_url' => RADIO_mediaUrl((int) $item['media_id'], false),
                'item_url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $item['media_id'])),
                'next_media' => $next === false ? false : array(
                    'media_id' => (int) $next['media_id'],
                    'external_id' => RADIO_externalId('media', (int) $next['media_id']),
                    'title' => $next['title'],
                    'media_type' => $next['media_type'],
                    'source_kind' => RADIO_sourceKind($next),
                    'duration' => (int) $next['duration'],
                    'stream_url' => RADIO_mediaUrl((int) $next['media_id'], false),
                    'item_url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $next['media_id']))
                ),
                'next_next_media' => $nextNext === false ? false : array(
                    'media_id' => (int) $nextNext['media_id'],
                    'external_id' => RADIO_externalId('media', (int) $nextNext['media_id']),
                    'title' => $nextNext['title'],
                    'media_type' => $nextNext['media_type'],
                    'source_kind' => RADIO_sourceKind($nextNext),
                    'duration' => (int) $nextNext['duration'],
                    'stream_url' => RADIO_mediaUrl((int) $nextNext['media_id'], false),
                    'item_url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $nextNext['media_id']))
                )
            );
        }
        $cursor += $duration;
    }

    return false;
}

function RADIO_getLiveState($timestamp)
{
    $timestamp = $timestamp ? (int) $timestamp : time();
    $program = RADIO_getNowPlaying($timestamp);

    if ($program !== false) {
        $media = RADIO_resolveProgramPlayback($program['program_id'], $program['elapsed']);
        return array(
            'timestamp' => $timestamp,
            'source' => 'schedule',
            'program' => $program,
            'media' => $media
        );
    }

    $rotation = RADIO_getRotationState($timestamp);
    return array(
        'timestamp' => $timestamp,
        'source' => $rotation !== false ? 'rotation' : 'idle',
        'program' => false,
        'media' => $rotation
    );
}


function RADIO_sourceSummary()
{
    global $_TABLES;

    $summary = array('local' => 0, 'external' => 0, 'live' => 0);
    $result = DB_query(
        "SELECT source_kind,COUNT(*) AS total FROM {$_TABLES['radio_media']} "
        . "WHERE status='published'" . RADIO_permissionSql('radio_media', 0)
        . " GROUP BY source_kind"
    );

    while ($row = DB_fetchArray($result)) {
        $kind = isset($row['source_kind']) ? $row['source_kind'] : 'local';
        if (!isset($summary[$kind])) {
            $kind = 'local';
        }
        $summary[$kind] = (int) $row['total'];
    }

    return $summary;
}

function RADIO_dashboardSummary()
{
    global $_TABLES;

    $mediaResult = DB_query(
        "SELECT COUNT(*) AS total FROM {$_TABLES['radio_media']} WHERE status='published'"
        . RADIO_permissionSql('', 0)
    );
    $mediaRow = DB_fetchArray($mediaResult);
    $mediaCount = is_array($mediaRow) ? (int) $mediaRow['total'] : 0;

    $programResult = DB_query(
        "SELECT COUNT(*) AS total FROM {$_TABLES['radio_programs']} WHERE status='published'"
        . RADIO_permissionSql('', 0)
    );
    $programRow = DB_fetchArray($programResult);
    $programCount = is_array($programRow) ? (int) $programRow['total'] : 0;
    $live = RADIO_getLiveState(time());
    $now = $live['program'];
    $upcoming = RADIO_getUpcoming(50, time());
    $replayCount = count(RADIO_getReplayOccurrences(100, time()));
    $weekLimit = strtotime('+7 days');
    $scheduledWeek = 0;
    foreach ($upcoming as $item) {
        if ($item['start'] < $weekLimit) {
            $scheduledWeek++;
        }
    }

    return array(
        'media_count' => $mediaCount,
        'program_count' => $programCount,
        'scheduled_next_7_days' => $scheduledWeek,
        'replay_count' => $replayCount,
        'stats' => RADIO_getStatsSummary(30),
        'sources' => RADIO_sourceSummary(),
        'feed_sync' => RADIO_feedSyncSummary(),
        'live_source' => $live['source'],
        'current_media' => $live['media'] !== false ? array(
            'media_id' => $live['media']['external_id'],
            'title' => $live['media']['title'],
            'media_type' => $live['media']['media_type']
        ) : false,
        'now_playing' => $now !== false ? array(
            'program_id' => RADIO_externalId('program', $now['program_id']),
            'title' => $now['program_title'],
            'url' => $now['url'],
            'start' => date('c', $now['start']),
            'end' => date('c', $now['end'])
        ) : false
    );
}

function plugin_wsEnabled_radio()
{
    return true;
}

function plugin_getcapabilities_radio()
{
    return array(
        'schema' => 1,
        'roles' => array('content', 'service'),
        'capabilities' => array(
            'content.read',
            'content.collection',
            'content.search',
            'content.url.resolve',
            'content.lifecycle',
            'content.syndication',
            'content.related',
            'dashboard.summary',
            'radio.media.read',
            'radio.program.read',
            'radio.schedule.read',
            'radio.upcoming.read',
            'radio.now-playing.read',
            'radio.current-media.read',
            'radio.rotation.read',
            'radio.replay.read',
            'radio.podcast.read',
            'radio.stats.read',
            'radio.source.read',
            'radio.source.summary',
            'radio.source.feed.read',
            'radio.source.sync.read'
        )
    );
}

function plugin_getiteminfo_radio($id, $what, $uid = 0, $options = array())
{
    if (!RADIO_publicAccessAllowed()) {
        return array();
    }
    global $_TABLES, $_CONF;
    $fields = array_map('trim', explode(',', $what));

    if ($id === '*') {
        $type = isset($options['type']) ? $options['type'] : 'media';
        $limit = isset($options['limit']) ? max(1, min(100, (int) $options['limit'])) : 20;
        $items = array();

        if ($type === 'program') {
            $sql = "SELECT * FROM {$_TABLES['radio_programs']} WHERE status='published'" . RADIO_permissionSql('radio_programs', (int) $uid);
            if (!empty($options['since'])) {
                $since = DB_escapeString(date('Y-m-d H:i:s', (int) $options['since']));
                $sql .= " AND modified >= '" . $since . "'";
            }
            $sql .= ' ORDER BY modified DESC LIMIT ' . $limit;
            $result = DB_query($sql);
            while ($row = DB_fetchArray($result)) {
                $items[] = RADIO_programInfoFromRow($row, $fields);
            }
            return $items;
        }

        $sql = "SELECT * FROM {$_TABLES['radio_media']} WHERE status='published'"
            . RADIO_mediaAvailabilitySql('on_demand', '')
            . RADIO_permissionSql('radio_media', (int) $uid);
        if (!empty($options['since'])) {
            $since = DB_escapeString(date('Y-m-d H:i:s', (int) $options['since']));
            $sql .= " AND modified >= '" . $since . "'";
        }
        $order = isset($options['order']) ? $options['order'] : 'modified-desc';
        if ($order === 'created-desc') {
            $sql .= ' ORDER BY created DESC';
        } elseif ($order === 'hits-desc') {
            $sql .= ' ORDER BY hits DESC';
        } else {
            $sql .= ' ORDER BY modified DESC';
        }
        $sql .= ' LIMIT ' . $limit;
        $result = DB_query($sql);
        while ($row = DB_fetchArray($result)) {
            $items[] = RADIO_itemInfoFromRow($row, $fields);
        }
        return $items;
    }

    $identity = RADIO_parseExternalId($id);
    if ($identity === false || $identity['id'] < 1) {
        return array();
    }
    if ($identity['type'] === 'program') {
        $sql = "SELECT * FROM {$_TABLES['radio_programs']} WHERE program_id=" . (int) $identity['id'] . " AND status='published'" . RADIO_permissionSql('radio_programs', (int) $uid);
        $result = DB_query($sql);
        $row = DB_numRows($result) > 0 ? DB_fetchArray($result) : false;
        if ($row === false) {
            return array();
        }
        return RADIO_singleItemInfoResult(
            RADIO_programInfoFromRow($row, $fields),
            $fields
        );
    }

    $sql = "SELECT * FROM {$_TABLES['radio_media']} WHERE media_id=" . (int) $identity['id']
        . " AND status='published'"
        . RADIO_mediaAvailabilitySql('on_demand', '')
        . RADIO_permissionSql('radio_media', (int) $uid);
    $result = DB_query($sql);
    $row = DB_numRows($result) > 0 ? DB_fetchArray($result) : false;
    if ($row === false) {
        return array();
    }
    return RADIO_singleItemInfoResult(
        RADIO_itemInfoFromRow($row, $fields),
        $fields
    );
}

function RADIO_singleItemInfoResult($info, $fields)
{
    if (!is_array($info)) {
        return array();
    }

    $mapped = array();
    foreach ($fields as $field) {
        $mapped[] = array_key_exists($field, $info) ? $info[$field] : '';
    }

    if (count($mapped) === 1) {
        return $mapped[0];
    }

    return $mapped;
}

function RADIO_selectInfoFields($all, $fields)
{
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

function RADIO_itemInfoFromRow($row, $fields)
{
    global $_CONF;
    $id = (int) $row['media_id'];
    $all = array(
        'id' => RADIO_externalId('media', $id),
        'type' => 'radio',
        'subtype' => 'media',
        'media_type' => $row['media_type'],
        'author' => isset($row['author']) ? $row['author'] : '',
        'series' => isset($row['series_title']) ? $row['series_title'] : '',
        'category' => isset($row['category']) ? $row['category'] : '',
        'collection' => isset($row['collection_name']) ? $row['collection_name'] : '',
        'tags' => !empty($row['tags']) ? explode(',', $row['tags']) : array(),
        'season' => isset($row['season_number']) ? (int)$row['season_number'] : 0,
        'episode' => isset($row['episode_number']) ? (int)$row['episode_number'] : 0,
        'image' => !empty($row['cover_name']) ? RADIO_coverUrl('media',$id) : '',
        'duration' => isset($row['duration']) ? (int) $row['duration'] : 0,
        'stream_url' => RADIO_mediaUrl($id, false),
        'source_kind' => RADIO_sourceKind($row),
        'source_provider' => isset($row['source_provider']) ? $row['source_provider'] : '',
        'source_external_id' => isset($row['source_external_id']) ? $row['source_external_id'] : '',
        'attribution' => isset($row['source_attribution']) ? $row['source_attribution'] : '',
        'license' => isset($row['source_license']) ? $row['source_license'] : '',
        'title' => $row['title'],
        'url' => $_CONF['site_url'] . '/radio/index.php?id=' . $id,
        'canonical_url' => $_CONF['site_url'] . '/radio/index.php?id=' . $id,
        'description' => $row['description'],
        'excerpt' => $row['description'],
        'date-created' => strtotime($row['created']),
        'date-modified' => strtotime($row['modified']),
        'hits' => (int) $row['hits']
    );
    return RADIO_selectInfoFields($all, $fields);
}

function RADIO_programInfoFromRow($row, $fields)
{
    global $_CONF;
    $id = (int) $row['program_id'];
    $all = array(
        'id' => RADIO_externalId('program', $id),
        'type' => 'radio',
        'subtype' => 'program',
        'title' => $row['title'],
        'author' => isset($row['host']) ? $row['host'] : '',
        'image' => !empty($row['cover_name']) ? RADIO_coverUrl('program',$id) : '',
        'url' => $_CONF['site_url'] . '/radio/program.php?id=' . $id,
        'canonical_url' => $_CONF['site_url'] . '/radio/program.php?id=' . $id,
        'description' => $row['description'],
        'excerpt' => $row['description'],
        'date-created' => strtotime($row['created']),
        'date-modified' => strtotime($row['modified'])
    );
    return RADIO_selectInfoFields($all, $fields);
}

function plugin_idtourl_radio($sub_type = '', $item_id = null)
{
    global $_CONF;

    if ($item_id === null) {
        $item_id = $sub_type;
        $sub_type = '';
    }
    $identity = RADIO_parseExternalId($item_id);
    if ($identity === false && ($sub_type === 'media' || $sub_type === 'program')) {
        $identity = array('type' => $sub_type, 'id' => (int) $item_id);
    }
    if ($identity === false || $identity['id'] < 1) {
        return '';
    }
    if ($identity['type'] === 'program') {
        return $_CONF['site_url'] . '/radio/program.php?id=' . $identity['id'];
    }
    return $_CONF['site_url'] . '/radio/index.php?id=' . $identity['id'];
}

function plugin_chkVersion_radio()
{
    global $_CONF;

    require_once $_CONF['path'] . 'plugins/radio/autoinstall.php';
    $inst = plugin_autoinstall_radio('radio');

    return isset($inst['info']['pi_version']) ? $inst['info']['pi_version'] : RADIO_PLUGIN_VERSION;
}

function RADIO_assetVersion($path)
{
    $version = RADIO_PLUGIN_VERSION;

    if (is_file($path)) {
        clearstatcache(true, $path);
        $modified = @filemtime($path);
        if ($modified !== false && $modified > 0) {
            $version .= '-' . (int) $modified;
        }
    }

    return $version;
}

function RADIO_adminStylesheetLink()
{
    global $_CONF;

    $path = !empty($_CONF['path_admin'])
        ? rtrim($_CONF['path_admin'], '/\\') . '/plugins/radio/radio-admin.css'
        : '';
    if ($path === '' || !is_file($path)) {
        $path = rtrim($_CONF['path'], '/\\') . '/plugins/radio/admin/radio-admin.css';
    }

    return '<link rel="stylesheet" type="text/css" href="'
        . htmlspecialchars(rtrim($_CONF['site_admin_url'], '/') . '/plugins/radio/radio-admin.css', ENT_QUOTES, 'UTF-8')
        . '?v=' . rawurlencode(RADIO_assetVersion($path)) . '">' . "\n";
}

function RADIO_publicStylesheetLink()
{
    global $_CONF;

    $path = !empty($_CONF['path_html'])
        ? rtrim($_CONF['path_html'], '/\\') . '/radio/radio.css'
        : '';
    if ($path === '' || !is_file($path)) {
        $path = rtrim($_CONF['path'], '/\\') . '/plugins/radio/public_html/radio.css';
    }

    return '<link rel="stylesheet" type="text/css" href="'
        . htmlspecialchars(rtrim($_CONF['site_url'], '/') . '/radio/radio.css', ENT_QUOTES, 'UTF-8')
        . '?v=' . rawurlencode(RADIO_assetVersion($path)) . '">' . "\n";
}

function RADIO_blockStylesheetLink()
{
    global $_CONF;

    $path = !empty($_CONF['path_html'])
        ? rtrim($_CONF['path_html'], '/\\') . '/radio/radio-block.css'
        : '';
    if ($path === '' || !is_file($path)) {
        $path = rtrim($_CONF['path'], '/\\') . '/plugins/radio/public_html/radio-block.css';
    }

    return '<link rel="stylesheet" type="text/css" href="'
        . htmlspecialchars(rtrim($_CONF['site_url'], '/') . '/radio/radio-block.css', ENT_QUOTES, 'UTF-8')
        . '?v=' . rawurlencode(RADIO_assetVersion($path)) . '">' . "\n";
}

function RADIO_publicScriptTag()
{
    global $_CONF;

    $path = !empty($_CONF['path_html'])
        ? rtrim($_CONF['path_html'], '/\\') . '/radio/radio.js'
        : '';
    if ($path === '' || !is_file($path)) {
        $path = rtrim($_CONF['path'], '/\\') . '/plugins/radio/public_html/radio.js';
    }

    return '<script id="radio-public-js" defer src="'
        . htmlspecialchars(rtrim($_CONF['site_url'], '/') . '/radio/radio.js', ENT_QUOTES, 'UTF-8')
        . '?v=' . rawurlencode(RADIO_assetVersion($path)) . '"'
        . ' data-event-endpoint="'
        . htmlspecialchars(rtrim($_CONF['site_url'], '/') . '/radio/event.php', ENT_QUOTES, 'UTF-8')
        . '"></script>' . "\n";
}

function RADIO_blockScriptTag()
{
    global $_CONF;

    $path = !empty($_CONF['path_html'])
        ? rtrim($_CONF['path_html'], '/\\') . '/radio/radio-block.js'
        : '';
    if ($path === '' || !is_file($path)) {
        $path = rtrim($_CONF['path'], '/\\') . '/plugins/radio/public_html/radio-block.js';
    }

    return '<script src="'
        . htmlspecialchars(rtrim($_CONF['site_url'], '/') . '/radio/radio-block.js', ENT_QUOTES, 'UTF-8')
        . '?v=' . rawurlencode(RADIO_assetVersion($path)) . '"></script>' . "\n";
}

function RADIO_adminScriptTag()
{
    global $_CONF;

    $path = !empty($_CONF['path_admin'])
        ? rtrim($_CONF['path_admin'], '/\\') . '/plugins/radio/radio-admin.js'
        : '';
    if ($path === '' || !is_file($path)) {
        $path = rtrim($_CONF['path'], '/\\') . '/plugins/radio/admin/radio-admin.js';
    }

    return '<script src="'
        . htmlspecialchars(rtrim($_CONF['site_admin_url'], '/') . '/plugins/radio/radio-admin.js', ENT_QUOTES, 'UTF-8')
        . '?v=' . rawurlencode(RADIO_assetVersion($path)) . '"></script>' . "\n";
}

function RADIO_requestPath()
{
    $path = '';
    if (!empty($_SERVER['REQUEST_URI'])) {
        $parsedPath = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = is_string($parsedPath) ? $parsedPath : '';
    }
    if ($path === '' && !empty($_SERVER['SCRIPT_NAME'])) {
        $path = (string) $_SERVER['SCRIPT_NAME'];
    }
    return str_replace('\\', '/', $path);
}

function RADIO_isPublicHtmlPage($path)
{
    foreach (array(
        '/radio/',
        '/radio/index.php',
        '/radio/program.php',
        '/radio/schedule.php',
        '/radio/replay.php'
    ) as $suffix) {
        if (RADIO_pathEndsWith($path, $suffix)) {
            return true;
        }
    }
    return false;
}

function plugin_getheadercode_radio()
{
    global $_RADIO_CONF;

    $path = RADIO_requestPath();
    $isAdmin = strpos($path, '/admin/plugins/radio/') !== false;
    $configGroup = '';
    if (isset($_POST['conf_group'])) {
        $configGroup = (string) $_POST['conf_group'];
    } elseif (isset($_GET['conf_group'])) {
        $configGroup = (string) $_GET['conf_group'];
    }
    $isRadioConfig = strpos($path, '/admin/configuration.php') !== false
        && $configGroup === 'radio';

    if ($isAdmin || $isRadioConfig) {
        return RADIO_adminStylesheetLink();
    }

    if (!RADIO_isEnabled()) {
        return '';
    }

    $header = '';

    if (RADIO_isPublicHtmlPage($path)) {
        $header .= RADIO_publicStylesheetLink();
    }

    if (!empty($GLOBALS['_RADIO_NEEDS_BLOCK_CSS'])) {
        $header .= RADIO_blockStylesheetLink();
    }

    return $header;
}

function plugin_getadminoption_radio()
{
    global $_CONF, $LANG_RADIO;

    if (!SEC_hasRights('radio.admin')) {
        return null;
    }

    $label = isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio';

    return array(
        $label,
        $_CONF['site_admin_url'] . '/plugins/radio/index.php',
        0
    );
}


function plugin_getmenuitems_radio()
{
    global $_CONF, $_RADIO_CONF, $LANG_RADIO;

    if (isset($_RADIO_CONF['enabled']) && !$_RADIO_CONF['enabled']) {
        return array();
    }

    $label = isset($_RADIO_CONF['public_title']) && trim((string) $_RADIO_CONF['public_title']) !== ''
        ? trim((string) $_RADIO_CONF['public_title'])
        : (isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio');

    return array(
        $label => rtrim($_CONF['site_url'], '/') . '/radio/index.php'
    );
}

function plugin_getBlocks_radio($side, $topic = '')
{
    global $_RADIO_CONF, $LANG_RADIO;

    $blocks = array();

    if ((isset($_RADIO_CONF['enabled']) && !$_RADIO_CONF['enabled'])
        || empty($_RADIO_CONF['block_enabled'])) {
        return $blocks;
    }

    $configuredSide = empty($_RADIO_CONF['block_isleft']) ? 'right' : 'left';
    if ($side !== $configuredSide) {
        return $blocks;
    }

    $content = RADIO_renderBlock();
    if ($content === '') {
        return $blocks;
    }

    $GLOBALS['_RADIO_NEEDS_BLOCK_CSS'] = true;
    $GLOBALS['_RADIO_NEEDS_BLOCK_JS'] = true;

    $blocks[] = array(
        'name'           => 'radio_now_playing',
        'type'           => 'dynamic',
        'onleft'         => empty($_RADIO_CONF['block_isleft']) ? 0 : 1,
        'title'          => isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio',
        'blockorder'     => isset($_RADIO_CONF['block_order']) ? (int) $_RADIO_CONF['block_order'] : 50,
        'content'        => $content,
        'allow_autotags' => false,
        'help'           => ''
    );

    return $blocks;
}

function plugin_getBlocksConfig_radio($side, $topic = '')
{
    global $_RADIO_CONF, $LANG_RADIO;

    return array(
        array(
            'plugin'         => isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio',
            'name'           => 'radio_now_playing',
            'title'          => isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio',
            'type'           => 'dynamic',
            'onleft'         => empty($_RADIO_CONF['block_isleft']) ? 0 : 1,
            'blockorder'     => isset($_RADIO_CONF['block_order']) ? (int) $_RADIO_CONF['block_order'] : 50,
            'allow_autotags' => false,
            'help'           => '',
            'enable'         => empty($_RADIO_CONF['block_enabled']) ? 0 : 1,
            'topic_option'   => 'all',
            'topic'          => array(),
            'inherit'        => array()
        )
    );
}

function RADIO_renderBlock()
{
    global $_CONF, $LANG_RADIO;

    $state = RADIO_getLiveState(time());
    $scheduleUrl = rtrim($_CONF['site_url'], '/') . '/radio/schedule.php';
    $radioUrl = rtrim($_CONF['site_url'], '/') . '/radio/index.php';
    $playerUrl = rtrim($_CONF['site_url'], '/') . '/radio/player.php?autoplay=1';
    $nowUrl = rtrim($_CONF['site_url'], '/') . '/radio/now.php';
    $eventUrl = rtrim($_CONF['site_url'], '/') . '/radio/event.php';
    $listenLabel = isset($LANG_RADIO['public_listen']) ? $LANG_RADIO['public_listen'] : 'Listen';
    $pauseLabel = isset($LANG_RADIO['public_pause']) ? $LANG_RADIO['public_pause'] : 'Pause';

    $html = '<div class="radio-block"'
        . ' data-now-endpoint="' . htmlspecialchars($nowUrl, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-event-endpoint="' . htmlspecialchars($eventUrl, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-listen-label="' . htmlspecialchars($listenLabel, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-pause-label="' . htmlspecialchars($pauseLabel, ENT_QUOTES, 'UTF-8') . '">';

    if ($state['media'] !== false) {
        $media = $state['media'];
        $html .= '<div class="radio-block__status"><span class="radio-block__dot" aria-hidden="true"></span>'
            . htmlspecialchars(isset($LANG_RADIO['public_on_air']) ? $LANG_RADIO['public_on_air'] : 'On air', ENT_QUOTES, 'UTF-8')
            . '</div>'
            . '<div class="radio-block__player">'
            . '<button type="button" class="radio-block__play" aria-label="'
            . htmlspecialchars($listenLabel, ENT_QUOTES, 'UTF-8') . '" title="'
            . htmlspecialchars($listenLabel, ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="radio-block__play-icon" aria-hidden="true"></span>'
            . '</button>'
            . '<div class="radio-block__content">'
            . '<p class="radio-block__title"><a data-radio-block-title href="'
            . htmlspecialchars(isset($media['item_url']) ? $media['item_url'] : $radioUrl, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($media['title'], ENT_QUOTES, 'UTF-8') . '</a></p>';

        if ($state['program'] !== false && !empty($state['program']['program_title'])) {
            $html .= '<p class="radio-block__meta" data-radio-block-program>'
                . htmlspecialchars($state['program']['program_title'], ENT_QUOTES, 'UTF-8')
                . '</p>';
        } else {
            $html .= '<p class="radio-block__meta" data-radio-block-program></p>';
        }

        $html .= '</div>'
            . '<canvas class="radio-block__scope" width="320" height="36" aria-hidden="true"></canvas>'
            . '<audio class="radio-block__audio" preload="none"></audio>'
            . '</div>';
    } else {
        $html .= '<p class="radio-block__meta">'
            . htmlspecialchars(isset($LANG_RADIO['nothing_scheduled_now']) ? $LANG_RADIO['nothing_scheduled_now'] : 'Nothing is on air right now.', ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    $html .= '<div class="radio-block__links">'
        . '<a href="' . htmlspecialchars($radioUrl, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars(isset($LANG_RADIO['listen_live']) ? $LANG_RADIO['listen_live'] : 'Listen live', ENT_QUOTES, 'UTF-8') . '</a>'
        . '<a href="' . htmlspecialchars($playerUrl, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-radio-persistent-player>'
        . htmlspecialchars(isset($LANG_RADIO['persistent_listen']) ? $LANG_RADIO['persistent_listen'] : 'Keep listening', ENT_QUOTES, 'UTF-8') . '</a>'
        . '<a href="' . htmlspecialchars($scheduleUrl, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars(isset($LANG_RADIO['view_full_schedule']) ? $LANG_RADIO['view_full_schedule'] : 'Schedule', ENT_QUOTES, 'UTF-8') . '</a>'
        . '</div></div>';

    return $html;
}

function RADIO_ensureSchemaColumn($table, $column, $definition)
{
    $result = DB_query("SHOW COLUMNS FROM " . $table . " LIKE '" . DB_escapeString($column) . "'");
    if (DB_numRows($result) > 0) return true;
    DB_query("ALTER TABLE " . $table . " ADD " . $column . " " . $definition);
    return !DB_error();
}

function plugin_upgrade_radio()
{
    global $_CONF, $_TABLES;

    $installedVersion = DB_getItem(
        $_TABLES['plugins'],
        'pi_version',
        "pi_name = 'radio'"
    );

    require_once $_CONF['path_system'] . 'classes/config.class.php';
    require_once $_CONF['path'] . 'plugins/radio/install_defaults.php';
    require_once $_CONF['path'] . 'plugins/radio/install_updates.php';

    if ($installedVersion !== RADIO_PLUGIN_VERSION
        && !radio_apply_updates($installedVersion, RADIO_PLUGIN_VERSION)) {
        COM_errorLog(
            "Radio upgrade: migration failed from '"
            . $installedVersion . "' to '" . RADIO_PLUGIN_VERSION . "'.",
            1
        );
        return false;
    }

    if (!RADIO_ensureConfig()) return false;
    $ok = true;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'author',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'series_title',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'category',"varchar(128) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'collection_name',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'tags',"text") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'season_number',"int(10) unsigned NOT NULL default '0'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'episode_number',"int(10) unsigned NOT NULL default '0'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'cover_name',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_kind',"varchar(16) NOT NULL default 'local'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_url',"text") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_provider',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_external_id',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_attribution',"text") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_license',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'on_demand',"tinyint(1) unsigned NOT NULL default '1'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'broadcast',"tinyint(1) unsigned NOT NULL default '1'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'automatic_rotation',"tinyint(1) unsigned NOT NULL default '1'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_programs'],'host',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_programs'],'cover_name',"varchar(255) NOT NULL default ''") && $ok;
    foreach (array('radio_media','radio_programs') as $aclTable) {
        $ok = RADIO_ensureSchemaColumn($_TABLES[$aclTable],'group_id',"mediumint(8) unsigned NOT NULL default '1'") && $ok;
        $ok = RADIO_ensureSchemaColumn($_TABLES[$aclTable],'perm_owner',"tinyint(1) unsigned NOT NULL default '3'") && $ok;
        $ok = RADIO_ensureSchemaColumn($_TABLES[$aclTable],'perm_group',"tinyint(1) unsigned NOT NULL default '2'") && $ok;
        $ok = RADIO_ensureSchemaColumn($_TABLES[$aclTable],'perm_members',"tinyint(1) unsigned NOT NULL default '2'") && $ok;
        $ok = RADIO_ensureSchemaColumn($_TABLES[$aclTable],'perm_anon',"tinyint(1) unsigned NOT NULL default '2'") && $ok;
    }
    if (!$ok || !RADIO_ensureStatsTable() || !RADIO_ensureSourcesTable() || !RADIO_ensureFeedSyncSchema()) return false;

    DB_query(
        "UPDATE {$_TABLES['plugins']} SET "
        . "pi_version='" . DB_escapeString(RADIO_PLUGIN_VERSION) . "',"
        . "pi_gl_version='" . DB_escapeString(RADIO_MIN_GEEKLOG_VERSION) . "' "
        . "WHERE pi_name='radio'"
    );

    return !DB_error();
}


function plugin_searchtypes_radio()
{
    global $LANG_RADIO;
    return array('radio' => isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio');
}

function plugin_dopluginsearch_radio($query, $datestart, $dateend, $topic, $type, $author, $keyType, $page = -1, $perpage = -1)
{
    global $_TABLES, $_CONF, $LANG_RADIO;

    if (!RADIO_isDatabaseCurrent()) {
        return array();
    }

    if (empty($type)) {
        $type = 'all';
    }
    if ($type !== 'all' && $type !== 'radio') {
        return array();
    }

    $query = trim(DB_escapeString($query));
    $siteUrl = DB_escapeString(rtrim($_CONF['site_url'], '/'));
    $label = isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio';

    $mediaSql = "SELECT m.media_id AS id,m.title,m.description,m.modified AS date,"
        . "m.owner_id AS uid,m.hits,"
        . "CONCAT('" . $siteUrl . "/radio/index.php?id=',m.media_id) AS url "
        . "FROM {$_TABLES['radio_media']} m "
        . "WHERE m.status='published'"
        . RADIO_mediaAvailabilitySql('on_demand', 'm')
        . COM_getPermSQL('AND', 0, 2, 'm') . " ";

    if ((int) $author > 0) {
        $mediaSql .= "AND m.owner_id=" . (int) $author . " ";
    }

    $mediaSearch = new SearchCriteria('radio', $label);
    $mediaColumns = array(
        'title' => 'm.title',
        'm.description',
        'm.author',
        'm.series_title',
        'm.category',
        'm.collection_name',
        'm.tags'
    );
    $mediaSql .= $mediaSearch->getDateRangeSQL('AND', 'm.modified', $datestart, $dateend);
    list($mediaSql, $mediaFtSql) = $mediaSearch->buildSearchSQL(
        $keyType,
        $query,
        $mediaColumns,
        $mediaSql
    );
    $mediaSearch->setSQL($mediaSql);
    $mediaSearch->setFtSQL($mediaFtSql);
    $mediaSearch->setRank(3);
    $mediaSearch->setAppendQuery(false);

    $programSql = "SELECT p.program_id AS id,p.title,p.description,p.modified AS date,"
        . "p.owner_id AS uid,0 AS hits,"
        . "CONCAT('" . $siteUrl . "/radio/program.php?id=',p.program_id) AS url "
        . "FROM {$_TABLES['radio_programs']} p "
        . "WHERE p.status='published'"
        . COM_getPermSQL('AND', 0, 2, 'p') . " ";

    if ((int) $author > 0) {
        $programSql .= "AND p.owner_id=" . (int) $author . " ";
    }

    $programSearch = new SearchCriteria('radio', $label);
    $programColumns = array('title' => 'p.title', 'p.description', 'p.host');
    $programSql .= $programSearch->getDateRangeSQL('AND', 'p.modified', $datestart, $dateend);
    list($programSql, $programFtSql) = $programSearch->buildSearchSQL(
        $keyType,
        $query,
        $programColumns,
        $programSql
    );
    $programSearch->setSQL($programSql);
    $programSearch->setFtSQL($programFtSql);
    $programSearch->setRank(3);
    $programSearch->setAppendQuery(false);

    return array($mediaSearch, $programSearch);
}

function plugin_whatsnewsupported_radio()
{
    global $_RADIO_CONF, $LANG_RADIO, $LANG_WHATSNEW;

    if (isset($_RADIO_CONF['whatsnew_enabled']) && empty($_RADIO_CONF['whatsnew_enabled'])) {
        return false;
    }

    $interval = isset($_RADIO_CONF['whatsnew_interval'])
        ? max(60, (int) $_RADIO_CONF['whatsnew_interval'])
        : 1209600;
    $title = isset($LANG_RADIO['whatsnew_title']) ? $LANG_RADIO['whatsnew_title'] : 'Radio';
    $byline = isset($LANG_WHATSNEW['new_last'])
        ? COM_formatTimeString($LANG_WHATSNEW['new_last'], $interval)
        : '';

    return array($title, $byline);
}

function plugin_getwhatsnew_radio()
{
    if (!RADIO_publicAccessAllowed()) {
        return '';
    }
    global $_RADIO_CONF, $LANG_RADIO;

    if (!RADIO_isDatabaseCurrent()) {
        return '';
    }

    if (isset($_RADIO_CONF['whatsnew_enabled']) && empty($_RADIO_CONF['whatsnew_enabled'])) {
        return '';
    }

    $interval = isset($_RADIO_CONF['whatsnew_interval'])
        ? max(60, (int) $_RADIO_CONF['whatsnew_interval'])
        : 1209600;
    $limit = isset($_RADIO_CONF['whatsnew_limit'])
        ? max(1, min(50, (int) $_RADIO_CONF['whatsnew_limit']))
        : 10;
    $since = time() - $interval;

    $items = array_merge(
        plugin_getiteminfo_radio('*', '*', 0, array('type' => 'media', 'since' => $since, 'limit' => $limit, 'order' => 'modified-desc')),
        plugin_getiteminfo_radio('*', '*', 0, array('type' => 'program', 'since' => $since, 'limit' => $limit, 'order' => 'modified-desc'))
    );

    usort($items, 'RADIO_compareItemModifiedDesc');
    if (count($items) > $limit) {
        $items = array_slice($items, 0, $limit);
    }

    $links = array();
    foreach ($items as $item) {
        if (empty($item['title']) || empty($item['url'])) {
            continue;
        }
        $title = $item['title'];
        $links[] = COM_createLink(
            COM_truncate($title, 60, '...'),
            $item['url'],
            array('title' => $title)
        ) . LB;
    }

    if (empty($links)) {
        return isset($LANG_RADIO['whatsnew_none'])
            ? $LANG_RADIO['whatsnew_none'] . '<br' . XHTML . '>'
            : '';
    }

    if (function_exists('PLG_getThemeItem')) {
        return COM_makeList($links, PLG_getThemeItem('core-css-list-new', 'core'));
    }
    return COM_makeList($links);
}

function RADIO_compareItemModifiedDesc($a, $b)
{
    $at = isset($a['date-modified']) ? strtotime($a['date-modified']) : 0;
    $bt = isset($b['date-modified']) ? strtotime($b['date-modified']) : 0;
    if ($at === $bt) {
        return 0;
    }
    return $at > $bt ? -1 : 1;
}

function plugin_collectSitemapItems_radio($uid = 1, $limit = 0)
{
    if (!RADIO_publicAccessAllowed()) {
        return array();
    }
    global $_TABLES, $_CONF;

    if (!RADIO_isDatabaseCurrent()) {
        return array();
    }

    $limit = max(0, (int) $limit);
    $items = array();

    $mediaSql = "SELECT media_id,modified FROM {$_TABLES['radio_media']} "
        . "WHERE status='published'"
        . RADIO_mediaAvailabilitySql('on_demand', '')
        . COM_getPermSQL('AND', (int) $uid, 2)
        . " ORDER BY modified DESC,media_id DESC";
    $media = DB_query($mediaSql);
    while ($row = DB_fetchArray($media)) {
        $modified = strtotime($row['modified']);
        $items[] = array(
            'url' => $_CONF['site_url'] . '/radio/index.php?id=' . (int) $row['media_id'],
            'date-modified' => $modified === false ? 0 : $modified
        );
        if ($limit > 0 && count($items) >= $limit) {
            return $items;
        }
    }

    $programSql = "SELECT program_id,modified FROM {$_TABLES['radio_programs']} "
        . "WHERE status='published'" . COM_getPermSQL('AND', (int) $uid, 2) . " ORDER BY modified DESC,program_id DESC";
    $programs = DB_query($programSql);
    while ($row = DB_fetchArray($programs)) {
        $modified = strtotime($row['modified']);
        $items[] = array(
            'url' => $_CONF['site_url'] . '/radio/program.php?id=' . (int) $row['program_id'],
            'date-modified' => $modified === false ? 0 : $modified
        );
        if ($limit > 0 && count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function plugin_getrelateditems_radio($tids, $max, $trim)
{
    if (!RADIO_publicAccessAllowed()) {
        return array();
    }
    global $_TABLES;

    if (!isset($_TABLES['topic_assignments']) || !is_array($tids) || empty($tids)) {
        return array();
    }

    $safeTids = array();
    foreach ($tids as $tid) {
        $tid = trim((string) $tid);
        if ($tid !== '') {
            $safeTids[] = "'" . DB_escapeString($tid) . "'";
        }
    }
    if (empty($safeTids)) {
        return array();
    }

    $max = max(1, min(100, (int) $max));
    $trim = max(1, (int) $trim);

    $sql = "SELECT ta.id FROM {$_TABLES['topic_assignments']} ta "
        . "WHERE ta.type='radio' AND ta.tid IN (" . implode(',', $safeTids) . ") "
        . "ORDER BY ta.inherit DESC LIMIT " . ($max * 2);
    $result = DB_query($sql);

    $related = array();
    while ($row = DB_fetchArray($result)) {
        $info = plugin_getiteminfo_radio($row['id'], '*');
        if (empty($info['title']) || empty($info['url'])) {
            continue;
        }

        $timestamp = !empty($info['date-modified']) ? strtotime($info['date-modified']) : time();
        if ($timestamp === false || $timestamp <= 0) {
            $timestamp = time();
        }
        while (isset($related[$timestamp])) {
            $timestamp--;
        }

        $related[$timestamp] = COM_createLink(
            htmlspecialchars(COM_truncate($info['title'], $trim, '...'), ENT_QUOTES, 'UTF-8'),
            $info['url']
        );

        if (count($related) >= $max) {
            break;
        }
    }

    return $related;
}
