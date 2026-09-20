<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/lib/feed.inc.php';
require_once __DIR__ . '/services.inc.php';

global $_TABLES, $_DB_table_prefix, $_RADIO_CONF;
$_TABLES['radio_media'] = $_DB_table_prefix . 'radio_media';
$_TABLES['radio_programs'] = $_DB_table_prefix . 'radio_programs';
$_TABLES['radio_program_items'] = $_DB_table_prefix . 'radio_program_items';
$_TABLES['radio_schedule'] = $_DB_table_prefix . 'radio_schedule';
$_TABLES['radio_events'] = $_DB_table_prefix . 'radio_events';
$_TABLES['radio_sources'] = $_DB_table_prefix . 'radio_sources';
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




function RADIO_trackingScript()
{
    global $_CONF;

    $endpoint = $_CONF['site_url'] . '/radio/event.php';

    return '<script>(function(){'
        . 'var endpoint=' . json_encode($endpoint) . ';'
        . 'function send(mediaId,programId,eventType,source,seconds){'
        . 'if(!mediaId)return;'
        . 'var body=new URLSearchParams();'
        . 'body.set("media_id",mediaId);'
        . 'body.set("program_id",programId||0);'
        . 'body.set("event_type",eventType);'
        . 'body.set("source",source);'
        . 'body.set("seconds",Math.max(0,Math.round(seconds||0)));'
        . 'if(navigator.sendBeacon){navigator.sendBeacon(endpoint,body);return;}'
        . 'fetch(endpoint,{method:"POST",body:body,credentials:"same-origin",keepalive:true}).catch(function(){});'
        . '}'
        . 'function bind(a){'
        . 'var mediaId=parseInt(a.getAttribute("data-radio-media-id")||"0",10);'
        . 'var programId=parseInt(a.getAttribute("data-radio-program-id")||"0",10);'
        . 'var source=a.getAttribute("data-radio-source")||"catalogue";'
        . 'var started=false,total=0,last=0;'
        . 'a.addEventListener("play",function(){if(!started){started=true;send(mediaId,programId,"play",source,0);}last=Date.now();});'
        . 'a.addEventListener("pause",function(){if(last){total+=(Date.now()-last)/1000;last=0;}});'
        . 'a.addEventListener("ended",function(){if(last){total+=(Date.now()-last)/1000;last=0;}if(total>0){send(mediaId,programId,"listen",source,total);total=0;}});'
        . 'window.addEventListener("pagehide",function(){if(last){total+=(Date.now()-last)/1000;last=0;}if(total>0){send(mediaId,programId,"listen",source,total);total=0;}});'
        . '}'
        . 'var audios=document.querySelectorAll("audio[data-radio-media-id]");'
        . 'for(var i=0;i<audios.length;i++)bind(audios[i]);'
        . '})();</script>';
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
    $allowedSources = array('catalogue','program','replay','live','podcast','download');
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
    global $_CONF;
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

    $status = isset($metadata['status']) && $metadata['status'] === 'published' ? 'published' : 'draft';
    $duration = $sourceKind === 'live' ? 0 : max(0, (int) (isset($metadata['duration']) ? $metadata['duration'] : 0));
    $owner = isset($_USER['uid']) ? (int) $_USER['uid'] : 2;
    $groupId = isset($metadata['group_id']) ? max(1, (int) $metadata['group_id']) : RADIO_defaultGroupId();
    list($permOwner, $permGroup, $permMembers, $permAnon) = RADIO_permissionValues($metadata, array());
    $now = date('Y-m-d H:i:s');

    DB_query(
        "INSERT INTO {$_TABLES['radio_media']} "
        . "(title,description,media_type,author,series_title,season_number,episode_number,cover_name,"
        . "source_kind,source_url,source_provider,source_external_id,source_attribution,source_license,"
        . "storage_name,original_name,mime_type,duration,file_size,status,allow_download,hits,owner_id,"
        . "group_id,perm_owner,perm_group,perm_members,perm_anon,created,modified) VALUES ("
        . "'" . DB_escapeString(substr($title,0,255)) . "',"
        . "'" . DB_escapeString(isset($metadata['description']) ? trim((string)$metadata['description']) : '') . "',"
        . "'" . DB_escapeString($mediaType) . "',"
        . "'" . DB_escapeString(substr(trim(isset($metadata['author']) ? (string)$metadata['author'] : ''),0,255)) . "',"
        . "'" . DB_escapeString(substr(trim(isset($metadata['series_title']) ? (string)$metadata['series_title'] : ''),0,255)) . "',"
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
        . "'" . DB_escapeString($status) . "',0,0,"
        . $owner . "," . $groupId . "," . $permOwner . "," . $permGroup . "," . $permMembers . "," . $permAnon . ","
        . "'" . DB_escapeString($now) . "','" . DB_escapeString($now) . "')"
    );

    if (DB_error()) {
        $error = 'database_error';
        return false;
    }

    $id = (int) DB_insertId();
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
        $title = pathinfo($validated['original_name'], PATHINFO_FILENAME);
    }
    $title = DB_escapeString(substr($title, 0, 255));
    $description = DB_escapeString(isset($metadata['description']) ? trim((string) $metadata['description']) : '');
    $author = DB_escapeString(substr(trim(isset($metadata['author']) ? (string)$metadata['author'] : ''),0,255));
    $seriesTitle = DB_escapeString(substr(trim(isset($metadata['series_title']) ? (string)$metadata['series_title'] : ''),0,255));
    $seasonNumber = isset($metadata['season_number']) ? max(0,(int)$metadata['season_number']) : 0;
    $episodeNumber = isset($metadata['episode_number']) ? max(0,(int)$metadata['episode_number']) : 0;
    $coverName = isset($metadata['cover_name']) ? basename((string)$metadata['cover_name']) : '';
    $mediaType = isset($metadata['media_type']) ? $metadata['media_type'] : 'music';
    $allowedTypes = array('music', 'podcast', 'interview', 'show', 'chronicle', 'jingle', 'announcement', 'promo');
    if (!in_array($mediaType, $allowedTypes, true)) {
        $mediaType = 'music';
    }
    $status = isset($metadata['status']) && $metadata['status'] === 'published' ? 'published' : 'draft';
    $allowDownload = !empty($metadata['allow_download']) ? 1 : 0;
    $duration = isset($metadata['duration']) ? max(0, (int) $metadata['duration']) : 0;
    $owner = isset($_USER['uid']) ? (int) $_USER['uid'] : 2;
    $groupId = isset($metadata['group_id']) ? max(1, (int) $metadata['group_id']) : RADIO_defaultGroupId();
    list($permOwner, $permGroup, $permMembers, $permAnon) = RADIO_permissionValues($metadata, array());
    $now = date('Y-m-d H:i:s');

    $sql = "INSERT INTO {$_TABLES['radio_media']} "
        . "(title,description,media_type,author,series_title,season_number,episode_number,cover_name,storage_name,original_name,mime_type,duration,file_size,status,allow_download,hits,owner_id,group_id,perm_owner,perm_group,perm_members,perm_anon,created,modified) VALUES ("
        . "'" . $title . "',"
        . "'" . $description . "',"
        . "'" . DB_escapeString($mediaType) . "',"
        . "'" . $author . "','" . $seriesTitle . "'," . $seasonNumber . "," . $episodeNumber . ",'" . DB_escapeString($coverName) . "',"
        . "'" . DB_escapeString($storageName) . "',"
        . "'" . DB_escapeString($validated['original_name']) . "',"
        . "'" . DB_escapeString($validated['mime']) . "',"
        . $duration . ","
        . (int) $validated['size'] . ","
        . "'" . DB_escapeString($status) . "',"
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

function RADIO_getMedia($id, $publishedOnly)
{
    global $_TABLES;
    $id = (int) $id;
    if ($id < 1) {
        return false;
    }
    $where = "media_id=" . $id;
    if ($publishedOnly) {
        $where .= " AND status='published'";
    }
    $result = DB_query("SELECT * FROM {$_TABLES['radio_media']} WHERE " . $where);
    if (DB_numRows($result) < 1) {
        return false;
    }
    $row = DB_fetchArray($result);
    if ($publishedOnly && !RADIO_hasReadAccess($row)) {
        return false;
    }
    return $row;
}

function RADIO_getMediaList($limit, $publishedOnly)
{
    global $_TABLES;
    $limit = max(1, min(200, (int) $limit));
    $where = $publishedOnly ? " WHERE status='published'" : '';
    if ($publishedOnly) {
        $where .= RADIO_permissionSql('radio_media', 0);
    }
    $result = DB_query("SELECT * FROM {$_TABLES['radio_media']}" . $where . " ORDER BY modified DESC, media_id DESC LIMIT " . $limit);
    $items = array();
    while ($row = DB_fetchArray($result)) {
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

    $title = trim(isset($metadata['title']) ? (string) $metadata['title'] : '');
    if ($title === '') {
        return false;
    }
    $description = isset($metadata['description']) ? trim((string)$metadata['description']) : '';
    $author = trim(isset($metadata['author']) ? (string)$metadata['author'] : '');
    $seriesTitle = trim(isset($metadata['series_title']) ? (string)$metadata['series_title'] : '');
    $seasonNumber = isset($metadata['season_number']) ? max(0,(int)$metadata['season_number']) : (int)$row['season_number'];
    $episodeNumber = isset($metadata['episode_number']) ? max(0,(int)$metadata['episode_number']) : (int)$row['episode_number'];
    $coverName = isset($metadata['cover_name']) ? basename((string)$metadata['cover_name']) : $row['cover_name'];
    $mediaType = isset($metadata['media_type']) ? $metadata['media_type'] : 'music';
    $allowedTypes = array('music', 'podcast', 'interview', 'show', 'chronicle', 'jingle', 'announcement', 'promo');
    if (!in_array($mediaType, $allowedTypes, true)) {
        $mediaType = 'music';
    }
    $status = isset($metadata['status']) && $metadata['status'] === 'published' ? 'published' : 'draft';
    $allowDownload = !empty($metadata['allow_download']) ? 1 : 0;
    $duration = isset($metadata['duration']) ? max(0, (int) $metadata['duration']) : (int) $row['duration'];
    $groupId = isset($metadata['group_id']) ? max(1, (int) $metadata['group_id']) : (int) $row['group_id'];
    list($permOwner, $permGroup, $permMembers, $permAnon) = RADIO_permissionValues($metadata, $row);
    $now = date('Y-m-d H:i:s');

    DB_query(
        "UPDATE {$_TABLES['radio_media']} SET "
        . "title='" . DB_escapeString(substr($title, 0, 255)) . "',"
        . "description='" . DB_escapeString($description) . "',"
        . "media_type='" . DB_escapeString($mediaType) . "',"
        . "author='" . DB_escapeString(substr($author,0,255)) . "',series_title='" . DB_escapeString(substr($seriesTitle,0,255)) . "',season_number=" . $seasonNumber . ",episode_number=" . $episodeNumber . ",cover_name='" . DB_escapeString($coverName) . "',"
        . "status='" . DB_escapeString($status) . "',"
        . "allow_download=" . $allowDownload . ","
        . "duration=" . $duration . ","
        . "group_id=" . $groupId . ",perm_owner=" . $permOwner . ",perm_group=" . $permGroup . ",perm_members=" . $permMembers . ",perm_anon=" . $permAnon . ","
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

    $mime = $row['mime_type'] !== '' ? $row['mime_type'] : 'application/octet-stream';
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
        $items[] = $row;
    }
    return $items;
}

function RADIO_addProgramItem($programId, $mediaId)
{
    global $_TABLES;
    $programId = (int) $programId;
    $mediaId = (int) $mediaId;
    $program = RADIO_getProgram($programId, false);
    $media = RADIO_getMedia($mediaId, false);
    if ($program === false || !RADIO_hasEditAccess($program)
        || $media === false || !RADIO_hasReadAccess($media)) {
        return false;
    }
    $max = DB_getItem($_TABLES['radio_program_items'], 'MAX(sort_order)', 'program_id=' . $programId);
    $sort = ((int) $max) + 10;
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
        . "WHERE status='published' AND media_type='podcast' AND storage_name<>''"
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
    return array(
        array('id' => 'podcast', 'name' => 'Radio Podcasts')
    );
}

function plugin_getfeedcontent_radio($feed, &$link, &$update, $feedType = '', $feedVersion = '')
{
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

    $result = DB_query(
        "SELECT * FROM {$_TABLES['radio_media']} "
        . "WHERE status='published' AND duration>0 AND media_type IN (" . implode(',', $safe) . ") "
        . RADIO_permissionSql('radio_media', 0) . " "
        . "ORDER BY media_id ASC"
    );

    $items = array();
    while ($row = DB_fetchArray($result)) {
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

function RADIO_rotationShuffle($items, $date, $scope)
{
    foreach ($items as $index => $item) {
        $items[$index]['_rotation_key'] = sha1($date . ':' . $scope . ':' . (int) $item['media_id']);
    }
    if (count($items) > 1) {
        usort($items, 'RADIO_rotationCompare');
    }
    foreach ($items as $index => $item) {
        unset($items[$index]['_rotation_key']);
    }
    return $items;
}

function RADIO_buildWeightedRegularSequence($date)
{
    global $_RADIO_CONF;

    $weights = RADIO_parseTypeWeights(
        isset($_RADIO_CONF['fallback_type_weights']) ? $_RADIO_CONF['fallback_type_weights'] : ''
    );
    $queues = array();
    foreach ($weights as $type => $weight) {
        $queues[$type] = RADIO_rotationShuffle(
            RADIO_getRotationMediaByTypes(array($type)),
            $date,
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

function RADIO_buildRotationSequence($date)
{
    global $_RADIO_CONF;

    $regular = RADIO_buildWeightedRegularSequence($date);
    if (count($regular) === 0) {
        return array();
    }

    $jingles = RADIO_rotationShuffle(
        RADIO_getRotationMediaByTypes(array('jingle')),
        $date,
        'jingle'
    );
    $announcements = RADIO_rotationShuffle(
        RADIO_getRotationMediaByTypes(array('announcement','promo')),
        $date,
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

function RADIO_rotationDuration($sequence)
{
    $duration = 0;
    foreach ($sequence as $item) {
        $duration += max(0, (int) $item['duration']);
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
    $sequence = RADIO_buildRotationSequence($date);
    $cycleDuration = RADIO_rotationDuration($sequence);
    if ($cycleDuration < 1) {
        return false;
    }

    $dayStart = strtotime($date . ' 00:00:00');
    $elapsedDay = max(0, $timestamp - $dayStart);
    $cycleOffset = $elapsedDay % $cycleDuration;
    $cursor = 0;

    foreach ($sequence as $item) {
        $duration = (int) $item['duration'];
        if ($duration < 1) {
            continue;
        }
        if ($cycleOffset < ($cursor + $duration)) {
            return array(
                'source' => 'rotation',
                'date' => $date,
                'cycle_duration' => $cycleDuration,
                'cycle_offset' => $cycleOffset,
                'media_id' => (int) $item['media_id'],
                'external_id' => RADIO_externalId('media', (int) $item['media_id']),
                'title' => $item['title'],
                'media_type' => $item['media_type'],
                'duration' => $duration,
                'offset' => $cycleOffset - $cursor,
                'stream_url' => RADIO_mediaUrl((int) $item['media_id'], false),
                'item_url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $item['media_id']))
            );
        }
        $cursor += $duration;
    }

    return false;
}

function RADIO_programDuration($programId)
{
    $items = RADIO_getProgramItems($programId);
    $duration = 0;
    foreach ($items as $item) {
        if ($item['status'] === 'published' && RADIO_hasReadAccess($item) && (int) $item['duration'] > 0) {
            $duration += (int) $item['duration'];
        }
    }
    return $duration;
}

function RADIO_resolveProgramPlayback($programId, $elapsed)
{
    $elapsed = max(0, (int) $elapsed);
    $items = RADIO_getProgramItems($programId);
    $cursor = 0;

    foreach ($items as $item) {
        if ($item['status'] !== 'published' || !RADIO_hasReadAccess($item)) {
            continue;
        }
        $duration = (int) $item['duration'];
        if ($duration < 1) {
            continue;
        }
        if ($elapsed < ($cursor + $duration)) {
            return array(
                'media_id' => (int) $item['media_id'],
                'external_id' => RADIO_externalId('media', (int) $item['media_id']),
                'title' => $item['title'],
                'media_type' => $item['media_type'],
                'duration' => $duration,
                'offset' => $elapsed - $cursor,
                'starts_after' => $cursor,
                'stream_url' => RADIO_mediaUrl((int) $item['media_id'], false),
                'item_url' => plugin_idtourl_radio('media', RADIO_externalId('media', (int) $item['media_id']))
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

        $sql = "SELECT * FROM {$_TABLES['radio_media']} WHERE status='published'" . RADIO_permissionSql('radio_media', (int) $uid);
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
        return $row === false ? array() : RADIO_programInfoFromRow($row, $fields);
    }

    $sql = "SELECT * FROM {$_TABLES['radio_media']} WHERE media_id=" . (int) $identity['id'] . " AND status='published'" . RADIO_permissionSql('radio_media', (int) $uid);
    $result = DB_query($sql);
    $row = DB_numRows($result) > 0 ? DB_fetchArray($result) : false;
    return $row === false ? array() : RADIO_itemInfoFromRow($row, $fields);
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
        'date-created' => $row['created'],
        'date-modified' => $row['modified'],
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
        'date-created' => $row['created'],
        'date-modified' => $row['modified']
    );
    return RADIO_selectInfoFields($all, $fields);
}

function plugin_idtourl_radio($sub_type, $item_id)
{
    global $_CONF;
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

    require_once $_CONF['path_system'] . 'classes/config.class.php';
    require_once $_CONF['path'] . 'plugins/radio/install_defaults.php';

    if (!RADIO_ensureConfig()) return false;
    $ok = true;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'author',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'series_title',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'season_number',"int(10) unsigned NOT NULL default '0'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'episode_number',"int(10) unsigned NOT NULL default '0'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'cover_name',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_kind',"varchar(16) NOT NULL default 'local'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_url',"text") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_provider',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_external_id',"varchar(255) NOT NULL default ''") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_attribution',"text") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_media'],'source_license',"varchar(255) NOT NULL default ''") && $ok;
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

    if (empty($type)) {
        $type = 'all';
    }
    if ($type !== 'all' && $type !== 'radio') {
        return array();
    }

    $query = trim(DB_escapeString($query));
    $siteUrl = DB_escapeString(rtrim($_CONF['site_url'], '/'));

    $sql = "SELECT rs.id,rs.title,rs.description,rs.date,rs.uid,rs.hits,rs.url FROM ("
        . "SELECT CONCAT('media:',m.media_id) AS id,m.title,m.description,m.modified AS date,"
        . "m.owner_id AS uid,m.hits,"
        . "CONCAT('" . $siteUrl . "/radio/index.php?id=',m.media_id) AS url,"
        . "m.author AS author "
        . "FROM {$_TABLES['radio_media']} m WHERE m.status='published' " . COM_getPermSQL('AND', 0, 2, 'm') . " "
        . "UNION ALL "
        . "SELECT CONCAT('program:',p.program_id) AS id,p.title,p.description,p.modified AS date,"
        . "p.owner_id AS uid,0 AS hits,"
        . "CONCAT('" . $siteUrl . "/radio/program.php?id=',p.program_id) AS url,"
        . "p.host AS author "
        . "FROM {$_TABLES['radio_programs']} p WHERE p.status='published'" . COM_getPermSQL('AND', 0, 2, 'p')
        . ") rs WHERE 1 ";

    if ((int) $author > 0) {
        $sql .= "AND rs.uid=" . (int) $author . " ";
    }

    $search = new SearchCriteria('radio', isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio');
    $columns = array('title' => 'rs.title', 'rs.description', 'rs.author');
    $sql .= $search->getDateRangeSQL('AND', 'rs.date', $datestart, $dateend);
    list($sql, $ftsql) = $search->buildSearchSQL($keyType, $query, $columns, $sql);

    $search->setSQL($sql);
    $search->setFtSQL($ftsql);
    $search->setRank(3);
    $search->setAppendQuery(false);

    return array($search, null);
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
    global $_RADIO_CONF, $LANG_RADIO;

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
    global $_TABLES, $_CONF;

    $limit = max(0, (int) $limit);
    $items = array();

    $mediaSql = "SELECT media_id,modified FROM {$_TABLES['radio_media']} "
        . "WHERE status='published'" . COM_getPermSQL('AND', (int) $uid, 2) . " ORDER BY modified DESC,media_id DESC";
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
