<?php

/**
 * Studio master-output helpers.
 *
 * Recording data is written below Radio persistent storage. The browser sends
 * small MediaRecorder chunks through short authenticated requests; PHP never
 * remains open as a continuous audio transport.
 */

function RADIO_studioSiteStorageDir()
{
    global $_CONF;

    $base = isset($_CONF['path_data']) ? rtrim((string) $_CONF['path_data'], "/\\") : '';
    if ($base === '') {
        return '';
    }

    return dirname($base) . DIRECTORY_SEPARATOR . basename($base) . '-radio' . DIRECTORY_SEPARATOR;
}

function RADIO_studioEnsureSiteStorage()
{
    $dir = RADIO_studioSiteStorageDir();
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        COM_errorLog('Radio: cannot create site-specific Studio storage directory ' . $dir, 1);
        return false;
    }

    return is_writable($dir);
}

function RADIO_studioRecordingDir()
{
    $base = RADIO_studioSiteStorageDir();
    return $base === '' ? '' : $base . 'recordings' . DIRECTORY_SEPARATOR;
}

function RADIO_studioEnsureRecordingStorage()
{
    if (!RADIO_studioEnsureSiteStorage()) {
        return false;
    }

    $dir = RADIO_studioRecordingDir();
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        COM_errorLog('Radio: cannot create Studio recording directory ' . $dir, 1);
        return false;
    }

    return is_writable($dir);
}

function RADIO_studioRecordingMimeInfo($mime)
{
    $mime = strtolower(trim((string) $mime));
    $base = trim(strtok($mime, ';'));

    $map = array(
        'audio/webm' => array('extension' => 'webm', 'mime' => 'audio/webm'),
        'video/webm' => array('extension' => 'webm', 'mime' => 'audio/webm'),
        'audio/ogg' => array('extension' => 'ogg', 'mime' => 'audio/ogg'),
        'audio/mp4' => array('extension' => 'm4a', 'mime' => 'audio/mp4')
    );

    return isset($map[$base]) ? $map[$base] : false;
}

function RADIO_studioRecordingSessionId()
{
    return sha1(uniqid('radio-studio-', true) . mt_rand() . microtime(true));
}

function RADIO_studioRecordingMetaPath($sessionId)
{
    if (!preg_match('/^[a-f0-9]{40}$/', (string) $sessionId)) {
        return '';
    }
    return RADIO_studioRecordingDir() . $sessionId . '.json';
}

function RADIO_studioRecordingPartPath($sessionId)
{
    if (!preg_match('/^[a-f0-9]{40}$/', (string) $sessionId)) {
        return '';
    }
    return RADIO_studioRecordingDir() . $sessionId . '.part';
}

function RADIO_studioRecordingReadMeta($sessionId)
{
    $path = RADIO_studioRecordingMetaPath($sessionId);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return false;
    }

    $raw = @file_get_contents($path);
    $meta = $raw !== false ? json_decode($raw, true) : null;
    return is_array($meta) ? $meta : false;
}

function RADIO_studioRecordingWriteMeta($sessionId, $meta)
{
    $path = RADIO_studioRecordingMetaPath($sessionId);
    if ($path === '' || !is_array($meta)) {
        return false;
    }

    $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);

    return true;
}

function RADIO_studioRecordingCleanupStale($maxAgeSeconds)
{
    $maxAgeSeconds = max(300, (int) $maxAgeSeconds);

    if (!RADIO_studioEnsureRecordingStorage()) {
        return 0;
    }

    $dir = RADIO_studioRecordingDir();
    $files = @glob($dir . '*.json');
    if (!is_array($files)) {
        return 0;
    }

    $now = time();
    $cleaned = 0;

    foreach ($files as $metaPath) {
        $sessionId = basename((string) $metaPath, '.json');
        if (!preg_match('/^[a-f0-9]{40}$/', $sessionId)) {
            continue;
        }

        $meta = RADIO_studioRecordingReadMeta($sessionId);
        if ($meta === false || !isset($meta['status']) || $meta['status'] !== 'recording') {
            continue;
        }

        $startedAt = !empty($meta['started_at']) ? strtotime((string) $meta['started_at']) : false;
        $lastChunkAt = !empty($meta['last_chunk_at']) ? strtotime((string) $meta['last_chunk_at']) : false;
        $activityAt = $lastChunkAt !== false ? $lastChunkAt : $startedAt;

        if ($activityAt === false || ($now - $activityAt) <= $maxAgeSeconds) {
            continue;
        }

        $partPath = RADIO_studioRecordingPartPath($sessionId);
        if ($partPath !== '' && is_file($partPath)) {
            @unlink($partPath);
        }

        $meta['status'] = 'aborted';
        $meta['ended_at'] = date('Y-m-d H:i:s');
        $meta['abort_reason'] = 'stale_session_timeout';

        if (RADIO_studioRecordingWriteMeta($sessionId, $meta)) {
            $cleaned++;
        }
    }

    return $cleaned;
}

function RADIO_studioRecordingStart($programId, $mime, $uid, &$error)
{
    $error = '';
    $mimeInfo = RADIO_studioRecordingMimeInfo($mime);
    if ($mimeInfo === false) {
        $error = 'studio_recording_mime_unsupported';
        return false;
    }
    if (!RADIO_studioEnsureRecordingStorage()) {
        $error = 'storage_unavailable';
        return false;
    }

    RADIO_studioRecordingCleanupStale(21600);

    $sessionId = RADIO_studioRecordingSessionId();
    $partPath = RADIO_studioRecordingPartPath($sessionId);
    if ($partPath === '' || @file_put_contents($partPath, '') === false) {
        $error = 'studio_recording_start_failed';
        return false;
    }
    @chmod($partPath, 0600);

    $meta = array(
        'session_id' => $sessionId,
        'program_id' => max(0, (int) $programId),
        'owner_id' => max(0, (int) $uid),
        'status' => 'recording',
        'mime' => $mimeInfo['mime'],
        'extension' => $mimeInfo['extension'],
        'started_at' => date('Y-m-d H:i:s'),
        'last_chunk_at' => '',
        'ended_at' => '',
        'chunks' => 0,
        'bytes' => 0,
        'filename' => ''
    );

    if (!RADIO_studioRecordingWriteMeta($sessionId, $meta)) {
        @unlink($partPath);
        $error = 'studio_recording_start_failed';
        return false;
    }

    return $meta;
}

function RADIO_studioRecordingAppend($sessionId, $tmpPath, $size, $chunkIndex, $uid, &$error)
{
    $error = '';
    $meta = RADIO_studioRecordingReadMeta($sessionId);
    if ($meta === false || $meta['status'] !== 'recording') {
        $error = 'studio_recording_session_invalid';
        return false;
    }
    if ((int) $meta['owner_id'] !== (int) $uid) {
        $error = 'access_denied';
        return false;
    }

    $size = (int) $size;
    if ($size < 1 || $size > 8388608) {
        $error = 'studio_recording_chunk_invalid';
        return false;
    }
    if ((int) $chunkIndex !== (int) $meta['chunks']) {
        $error = 'studio_recording_chunk_order';
        return false;
    }
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $error = 'studio_recording_chunk_invalid';
        return false;
    }

    $source = @fopen($tmpPath, 'rb');
    $target = @fopen(RADIO_studioRecordingPartPath($sessionId), 'ab');
    if ($source === false || $target === false) {
        if (is_resource($source)) {
            @fclose($source);
        }
        if (is_resource($target)) {
            @fclose($target);
        }
        $error = 'studio_recording_write_failed';
        return false;
    }

    if (!@flock($target, LOCK_EX)) {
        @fclose($source);
        @fclose($target);
        $error = 'studio_recording_write_failed';
        return false;
    }

    $written = stream_copy_to_stream($source, $target);
    @fflush($target);
    @flock($target, LOCK_UN);
    @fclose($source);
    @fclose($target);

    if ($written === false || (int) $written !== $size) {
        $error = 'studio_recording_write_failed';
        return false;
    }

    $meta['chunks'] = (int) $meta['chunks'] + 1;
    $meta['bytes'] = (int) $meta['bytes'] + $size;
    $meta['last_chunk_at'] = date('Y-m-d H:i:s');
    if (!RADIO_studioRecordingWriteMeta($sessionId, $meta)) {
        $error = 'studio_recording_write_failed';
        return false;
    }

    return $meta;
}

function RADIO_studioRecordingStop($sessionId, $uid, &$error)
{
    $error = '';
    $meta = RADIO_studioRecordingReadMeta($sessionId);
    if ($meta === false || $meta['status'] !== 'recording') {
        $error = 'studio_recording_session_invalid';
        return false;
    }
    if ((int) $meta['owner_id'] !== (int) $uid) {
        $error = 'access_denied';
        return false;
    }

    $partPath = RADIO_studioRecordingPartPath($sessionId);
    if (!is_file($partPath) || (int) @filesize($partPath) < 1) {
        $error = 'studio_recording_empty';
        return false;
    }

    $programPart = (int) $meta['program_id'] > 0 ? '-program-' . (int) $meta['program_id'] : '';
    $filename = 'studio-' . gmdate('Ymd-His') . $programPart . '-' . substr($sessionId, 0, 10)
        . '.' . $meta['extension'];
    $finalPath = RADIO_studioRecordingDir() . $filename;

    if (!@rename($partPath, $finalPath)) {
        $error = 'studio_recording_finalize_failed';
        return false;
    }
    @chmod($finalPath, 0600);

    $meta['status'] = 'complete';
    $meta['ended_at'] = date('Y-m-d H:i:s');
    $meta['bytes'] = (int) @filesize($finalPath);
    $meta['filename'] = $filename;

    if (!RADIO_studioRecordingWriteMeta($sessionId, $meta)) {
        $error = 'studio_recording_finalize_failed';
        return false;
    }

    return $meta;
}

function RADIO_studioRecordingAbort($sessionId, $uid)
{
    $meta = RADIO_studioRecordingReadMeta($sessionId);
    if ($meta === false || (int) $meta['owner_id'] !== (int) $uid) {
        return false;
    }

    $partPath = RADIO_studioRecordingPartPath($sessionId);
    if (is_file($partPath)) {
        @unlink($partPath);
    }

    $meta['status'] = 'aborted';
    $meta['ended_at'] = date('Y-m-d H:i:s');
    return RADIO_studioRecordingWriteMeta($sessionId, $meta);
}
