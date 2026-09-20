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
        . "0,"
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
    $now = date('Y-m-d H:i:s');

    DB_query(
        "UPDATE {$_TABLES['radio_media']} SET "
        . "title='" . DB_escapeString(substr($title, 0, 255)) . "',"
        . "description='" . DB_escapeString($description) . "',"
        . "media_type='" . DB_escapeString($mediaType) . "',"
        . "status='" . DB_escapeString($status) . "',"
        . "allow_download=" . $allowDownload . ","
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
            'radio.now-playing.read'
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
