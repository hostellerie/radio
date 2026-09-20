<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}
require_once __DIR__ . '/version.php';

global $_TABLES, $_DB_table_prefix, $_RADIO_CONF;
$_TABLES['radio_media'] = $_DB_table_prefix . 'radio_media';
$_TABLES['radio_programs'] = $_DB_table_prefix . 'radio_programs';
$_TABLES['radio_program_items'] = $_DB_table_prefix . 'radio_program_items';
$_TABLES['radio_schedule'] = $_DB_table_prefix . 'radio_schedule';

if (!isset($_RADIO_CONF) || !is_array($_RADIO_CONF)) {
    $_RADIO_CONF = array();
    if (class_exists('config')) {
        $radioConfig = config::get_instance();
        if ($radioConfig->group_exists('radio')) {
            $_RADIO_CONF = $radioConfig->get_config('radio');
        }
    }
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
    if ($mime !== '' && !in_array($mime, $allowed[$extension], true)) {
        $error = 'upload_type';
        return false;
    }

    return array(
        'extension' => $extension,
        'mime' => $mime !== '' ? $mime : $allowed[$extension][0],
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
    $mediaType = isset($metadata['media_type']) ? $metadata['media_type'] : 'music';
    $allowedTypes = array('music', 'podcast', 'interview', 'show', 'chronicle', 'jingle', 'announcement', 'promo');
    if (!in_array($mediaType, $allowedTypes, true)) {
        $mediaType = 'music';
    }
    $status = isset($metadata['status']) && $metadata['status'] === 'published' ? 'published' : 'draft';
    $allowDownload = !empty($metadata['allow_download']) ? 1 : 0;
    $duration = isset($metadata['duration']) ? max(0, (int) $metadata['duration']) : 0;
    $owner = isset($_USER['uid']) ? (int) $_USER['uid'] : 2;
    $now = date('Y-m-d H:i:s');

    $sql = "INSERT INTO {$_TABLES['radio_media']} "
        . "(title,description,media_type,storage_name,original_name,mime_type,duration,file_size,status,allow_download,hits,owner_id,created,modified) VALUES ("
        . "'" . $title . "',"
        . "'" . $description . "',"
        . "'" . DB_escapeString($mediaType) . "',"
        . "'" . DB_escapeString($storageName) . "',"
        . "'" . DB_escapeString($validated['original_name']) . "',"
        . "'" . DB_escapeString($validated['mime']) . "',"
        . $duration . ","
        . (int) $validated['size'] . ","
        . "'" . DB_escapeString($status) . "',"
        . $allowDownload . ",0,"
        . $owner . ","
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
    return DB_fetchArray($result);
}

function RADIO_getMediaList($limit, $publishedOnly)
{
    global $_TABLES;
    $limit = max(1, min(200, (int) $limit));
    $where = $publishedOnly ? " WHERE status='published'" : '';
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
    if ($row === false) {
        return false;
    }

    $title = trim(isset($metadata['title']) ? (string) $metadata['title'] : '');
    if ($title === '') {
        return false;
    }
    $description = isset($metadata['description']) ? trim((string) $metadata['description']) : '';
    $mediaType = isset($metadata['media_type']) ? $metadata['media_type'] : 'music';
    $allowedTypes = array('music', 'podcast', 'interview', 'show', 'chronicle', 'jingle', 'announcement', 'promo');
    if (!in_array($mediaType, $allowedTypes, true)) {
        $mediaType = 'music';
    }
    $status = isset($metadata['status']) && $metadata['status'] === 'published' ? 'published' : 'draft';
    $allowDownload = !empty($metadata['allow_download']) ? 1 : 0;
    $duration = isset($metadata['duration']) ? max(0, (int) $metadata['duration']) : (int) $row['duration'];
    $now = date('Y-m-d H:i:s');

    DB_query(
        "UPDATE {$_TABLES['radio_media']} SET "
        . "title='" . DB_escapeString(substr($title, 0, 255)) . "',"
        . "description='" . DB_escapeString($description) . "',"
        . "media_type='" . DB_escapeString($mediaType) . "',"
        . "status='" . DB_escapeString($status) . "',"
        . "allow_download=" . $allowDownload . ","
        . "duration=" . $duration . ","
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
    if ($row === false) {
        return false;
    }

    DB_query("DELETE FROM {$_TABLES['radio_program_items']} WHERE media_id=" . (int) $id);
    DB_query("DELETE FROM {$_TABLES['radio_media']} WHERE media_id=" . (int) $id);
    if (DB_error()) {
        return false;
    }

    $path = RADIO_storageDir() . basename($row['storage_name']);
    if (is_file($path) && !@unlink($path)) {
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

function RADIO_sendMedia($row, $download)
{
    global $_RADIO_CONF;

    $path = RADIO_storageDir() . basename($row['storage_name']);
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $downloadAllowed = !empty($_RADIO_CONF['allow_downloads']) && !empty($row['allow_download']);
    if ($download && !$downloadAllowed) {
        return false;
    }

    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $status = 200;

    if (!$download && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') {
            $start = (int) $m[1];
        }
        if ($m[2] !== '') {
            $end = min($end, (int) $m[2]);
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
    return DB_numRows($result) > 0 ? DB_fetchArray($result) : false;
}

function RADIO_saveProgram($id, $data)
{
    global $_TABLES, $_USER;
    $title = trim(isset($data['program_title']) ? (string) $data['program_title'] : '');
    if ($title === '') {
        return false;
    }
    $description = trim(isset($data['program_description']) ? (string) $data['program_description'] : '');
    $status = isset($data['program_status']) && $data['program_status'] === 'published' ? 'published' : 'draft';
    $now = date('Y-m-d H:i:s');

    if ((int) $id > 0) {
        DB_query(
            "UPDATE {$_TABLES['radio_programs']} SET "
            . "title='" . DB_escapeString(substr($title, 0, 255)) . "',"
            . "description='" . DB_escapeString($description) . "',"
            . "status='" . DB_escapeString($status) . "',"
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
            . "(title,description,status,owner_id,created,modified) VALUES ("
            . "'" . DB_escapeString(substr($title, 0, 255)) . "',"
            . "'" . DB_escapeString($description) . "',"
            . "'" . DB_escapeString($status) . "',"
            . $owner . ","
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
    if ($id < 1 || RADIO_getProgram($id, false) === false) {
        return false;
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
    if (RADIO_getProgram($programId, false) === false || RADIO_getMedia($mediaId, false) === false) {
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
    DB_query(
        "DELETE FROM {$_TABLES['radio_program_items']} WHERE item_id=" . (int) $itemId
        . " AND program_id=" . (int) $programId
    );
    return !DB_error();
}

function RADIO_moveProgramItem($itemId, $programId, $direction)
{
    global $_TABLES;
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

    $programId = isset($data['program_id']) ? (int) $data['program_id'] : 0;
    $program = RADIO_getProgram($programId, false);
    if ($program === false) {
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
        . $where . " ORDER BY s.starts_at ASC,s.schedule_id ASC");
    $items = array();
    while ($row = DB_fetchArray($result)) {
        $items[] = $row;
    }
    return $items;
}

function RADIO_deleteSchedule($id)
{
    global $_TABLES;
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
        if ($item['status'] === 'published' && (int) $item['duration'] > 0) {
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
        if ($item['status'] !== 'published') {
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

function RADIO_dashboardSummary()
{
    global $_TABLES;

    $mediaCount = (int) DB_getItem($_TABLES['radio_media'], 'COUNT(*)', "status='published'");
    $programCount = (int) DB_getItem($_TABLES['radio_programs'], 'COUNT(*)', "status='published'");
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

function plugin_getcapabilities_radio()
{
    return array(
        'schema' => 1,
        'roles' => array('content', 'service'),
        'capabilities' => array(
            'content.read',
            'content.collection',
            'content.url.resolve',
            'content.lifecycle',
            'dashboard.summary',
            'radio.media.read',
            'radio.program.read',
            'radio.schedule.read',
            'radio.now-playing.read',
            'radio.current-media.read',
            'radio.rotation.read',
            'radio.replay.read'
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
            $sql = "SELECT * FROM {$_TABLES['radio_programs']} WHERE status='published'";
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

        $sql = "SELECT media_id,title,description,media_type,created,modified,hits FROM {$_TABLES['radio_media']} WHERE status='published'";
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
        $row = RADIO_getProgram($identity['id'], true);
        return $row === false ? array() : RADIO_programInfoFromRow($row, $fields);
    }

    $row = RADIO_getMedia($identity['id'], true);
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
        'duration' => isset($row['duration']) ? (int) $row['duration'] : 0,
        'stream_url' => RADIO_mediaUrl($id, false),
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
    global $_CONF;
    if (!SEC_hasRights('radio.admin')) {
        return false;
    }
    return array(
        'url' => $_CONF['site_admin_url'] . '/plugins/radio/index.php',
        'text' => 'Radio',
        'count' => 0
    );
}


function plugin_upgrade_radio()
{
    global $_CONF, $_TABLES;

    require_once $_CONF['path_system'] . 'classes/config.class.php';
    require_once $_CONF['path'] . 'plugins/radio/install_defaults.php';

    if (!RADIO_ensureConfig()) {
        return false;
    }

    DB_query(
        "UPDATE {$_TABLES['plugins']} SET "
        . "pi_version='" . DB_escapeString(RADIO_PLUGIN_VERSION) . "',"
        . "pi_gl_version='" . DB_escapeString(RADIO_MIN_GEEKLOG_VERSION) . "' "
        . "WHERE pi_name='radio'"
    );

    return !DB_error();
}
