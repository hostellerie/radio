<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

/**
 * Lightweight ID3v2 reader/writer used by Radio shared-media mode.
 *
 * Radio intentionally keeps this implementation small and dependency-free so
 * Geeklog/PHP 5.6 installations can share MP3 metadata without requiring
 * Composer. Unknown ID3 frames are preserved when Radio rewrites the tag.
 */

function RADIO_id3SynchsafeToInt($bytes)
{
    if (!is_string($bytes) || strlen($bytes) !== 4) {
        return 0;
    }
    return ((ord($bytes[0]) & 0x7f) << 21)
        | ((ord($bytes[1]) & 0x7f) << 14)
        | ((ord($bytes[2]) & 0x7f) << 7)
        | (ord($bytes[3]) & 0x7f);
}

function RADIO_id3IntToSynchsafe($value)
{
    $value = max(0, (int) $value);
    return chr(($value >> 21) & 0x7f)
        . chr(($value >> 14) & 0x7f)
        . chr(($value >> 7) & 0x7f)
        . chr($value & 0x7f);
}

function RADIO_id3FrameSize($bytes, $version)
{
    if (!is_string($bytes) || strlen($bytes) !== 4) {
        return 0;
    }
    if ((int) $version >= 4) {
        return RADIO_id3SynchsafeToInt($bytes);
    }
    $unpacked = unpack('Nsize', $bytes);
    return isset($unpacked['size']) ? (int) $unpacked['size'] : 0;
}

function RADIO_id3Utf8($value, $from)
{
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_convert_encoding')) {
        return (string) @mb_convert_encoding($value, 'UTF-8', $from);
    }
    if (function_exists('iconv')) {
        $converted = @iconv($from, 'UTF-8//IGNORE', $value);
        if ($converted !== false) {
            return (string) $converted;
        }
    }
    return (string) $value;
}

function RADIO_id3Utf16($value)
{
    $value = (string) $value;
    if (function_exists('mb_convert_encoding')) {
        return "\xff\xfe" . (string) @mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'UTF-16LE//IGNORE', $value);
        if ($converted !== false) {
            return "\xff\xfe" . $converted;
        }
    }

    // ASCII fallback keeps the tag writable even on minimal PHP builds.
    $ascii = preg_replace('/[^\x20-\x7e]/', '?', $value);
    $out = "\xff\xfe";
    for ($i = 0; $i < strlen($ascii); $i++) {
        $out .= $ascii[$i] . "\x00";
    }
    return $out;
}

function RADIO_id3DecodeText($data)
{
    if (!is_string($data) || $data === '') {
        return '';
    }

    $encoding = ord($data[0]);
    $text = substr($data, 1);

    if ($encoding === 0) {
        return trim(str_replace("\x00", '', RADIO_id3Utf8($text, 'ISO-8859-1')));
    }
    if ($encoding === 3) {
        return trim(str_replace("\x00", '', $text));
    }
    if ($encoding === 1) {
        if (substr($text, 0, 2) === "\xff\xfe") {
            return trim(str_replace("\x00", '', RADIO_id3Utf8(substr($text, 2), 'UTF-16LE')));
        }
        if (substr($text, 0, 2) === "\xfe\xff") {
            return trim(str_replace("\x00", '', RADIO_id3Utf8(substr($text, 2), 'UTF-16BE')));
        }
        return trim(str_replace("\x00", '', RADIO_id3Utf8($text, 'UTF-16')));
    }
    if ($encoding === 2) {
        return trim(str_replace("\x00", '', RADIO_id3Utf8($text, 'UTF-16BE')));
    }

    return trim(str_replace("\x00", '', $text));
}

function RADIO_id3SplitEncoded($data, $encoding)
{
    $separator = ((int) $encoding === 1 || (int) $encoding === 2) ? "\x00\x00" : "\x00";
    $position = strpos($data, $separator);
    if ($position === false) {
        return array($data, '');
    }
    return array(
        substr($data, 0, $position),
        substr($data, $position + strlen($separator))
    );
}

function RADIO_id3DecodeEncodedPart($data, $encoding)
{
    return RADIO_id3DecodeText(chr((int) $encoding) . $data);
}

function RADIO_id3ReadTag($path)
{
    $result = array(
        'version' => 0,
        'audio_offset' => 0,
        'frames' => array(),
        'metadata' => array()
    );

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return $result;
    }

    $header = fread($handle, 10);
    if (!is_string($header) || strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
        fclose($handle);
        return $result;
    }

    $version = ord($header[3]);
    if ($version < 2 || $version > 4) {
        fclose($handle);
        return $result;
    }

    $tagSize = RADIO_id3SynchsafeToInt(substr($header, 6, 4));
    if ($tagSize < 1 || $tagSize > 16777216) {
        fclose($handle);
        return $result;
    }

    $payload = fread($handle, $tagSize);
    fclose($handle);
    if (!is_string($payload)) {
        return $result;
    }

    $result['version'] = $version;
    $result['audio_offset'] = 10 + $tagSize;

    $offset = 0;
    $length = strlen($payload);

    // Skip extended header when present.
    $flags = ord($header[5]);
    if (($flags & 0x40) && $length >= 4) {
        if ($version === 3) {
            $sizeData = unpack('Nsize', substr($payload, 0, 4));
            $extended = isset($sizeData['size']) ? (int) $sizeData['size'] : 0;
            $offset = min($length, 4 + max(0, $extended));
        } elseif ($version === 4) {
            $extended = RADIO_id3SynchsafeToInt(substr($payload, 0, 4));
            $offset = min($length, max(4, $extended));
        }
    }

    while (($offset + 10) <= $length) {
        $id = substr($payload, $offset, 4);
        if ($id === "\x00\x00\x00\x00" || !preg_match('/^[A-Z0-9]{4}$/', $id)) {
            break;
        }

        $size = RADIO_id3FrameSize(substr($payload, $offset + 4, 4), $version);
        $frameFlags = substr($payload, $offset + 8, 2);
        $offset += 10;

        if ($size < 0 || ($offset + $size) > $length) {
            break;
        }

        $data = substr($payload, $offset, $size);
        $offset += $size;

        $result['frames'][] = array(
            'id' => $id,
            'flags' => $frameFlags,
            'data' => $data
        );

        if (in_array($id, array('TIT2','TPE1','TALB','TCON'), true)) {
            $map = array(
                'TIT2' => 'title',
                'TPE1' => 'author',
                'TALB' => 'collection_name',
                'TCON' => 'category'
            );
            $result['metadata'][$map[$id]] = RADIO_id3DecodeText($data);
            continue;
        }

        if ($id === 'COMM' && strlen($data) >= 4) {
            $encoding = ord($data[0]);
            $body = substr($data, 4);
            list($short, $comment) = RADIO_id3SplitEncoded($body, $encoding);
            $result['metadata']['description'] = RADIO_id3DecodeEncodedPart($comment, $encoding);
            continue;
        }

        if ($id === 'TXXX' && strlen($data) >= 2) {
            $encoding = ord($data[0]);
            list($description, $value) = RADIO_id3SplitEncoded(substr($data, 1), $encoding);
            $description = strtoupper(trim(RADIO_id3DecodeEncodedPart($description, $encoding)));
            $value = RADIO_id3DecodeEncodedPart($value, $encoding);
            $custom = array(
                'RADIO_TAGS' => 'tags',
                'RADIO_SERIES' => 'series_title',
                'RADIO_SEASON' => 'season_number',
                'RADIO_EPISODE' => 'episode_number',
                'RADIO_TYPE' => 'media_type',
                'RADIO_ORIGINAL_NAME' => 'original_name'
            );
            if (isset($custom[$description])) {
                $result['metadata'][$custom[$description]] = $value;
            }
        }
    }

    if (isset($result['metadata']['season_number'])) {
        $result['metadata']['season_number'] = max(0, (int) $result['metadata']['season_number']);
    }
    if (isset($result['metadata']['episode_number'])) {
        $result['metadata']['episode_number'] = max(0, (int) $result['metadata']['episode_number']);
    }

    return $result;
}

function RADIO_id3TextFrame($id, $value)
{
    $body = chr(1) . RADIO_id3Utf16((string) $value);
    return $id . pack('N', strlen($body)) . "\x00\x00" . $body;
}

function RADIO_id3TxxxFrame($description, $value)
{
    $body = chr(1)
        . RADIO_id3Utf16((string) $description)
        . "\x00\x00"
        . substr(RADIO_id3Utf16((string) $value), 2);
    return 'TXXX' . pack('N', strlen($body)) . "\x00\x00" . $body;
}

function RADIO_id3CommentFrame($value)
{
    $body = chr(1) . 'eng' . RADIO_id3Utf16('') . "\x00\x00"
        . substr(RADIO_id3Utf16((string) $value), 2);
    return 'COMM' . pack('N', strlen($body)) . "\x00\x00" . $body;
}

function RADIO_id3ManagedTxxxDescription($data)
{
    if (!is_string($data) || strlen($data) < 2) {
        return '';
    }
    $encoding = ord($data[0]);
    list($description) = RADIO_id3SplitEncoded(substr($data, 1), $encoding);
    return strtoupper(trim(RADIO_id3DecodeEncodedPart($description, $encoding)));
}

function RADIO_id3WriteMetadata($path, $metadata, &$error)
{
    $error = '';
    if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
        $error = 'metadata_file_not_writable';
        return false;
    }

    $tag = RADIO_id3ReadTag($path);
    $preserved = '';
    $managedTxxx = array(
        'RADIO_TAGS',
        'RADIO_SERIES',
        'RADIO_SEASON',
        'RADIO_EPISODE',
        'RADIO_TYPE',
        'RADIO_ORIGINAL_NAME'
    );
    foreach ($tag['frames'] as $frame) {
        $id = $frame['id'];
        if (in_array($id, array('TIT2','TPE1','TALB','TCON','COMM'), true)) {
            continue;
        }
        if ($id === 'TXXX' && in_array(RADIO_id3ManagedTxxxDescription($frame['data']), $managedTxxx, true)) {
            continue;
        }

        // Normalize preserved frames to an ID3v2.3 frame header.
        $preserved .= $id . pack('N', strlen($frame['data'])) . "\x00\x00" . $frame['data'];
    }

    $frames = '';
    $frames .= RADIO_id3TextFrame('TIT2', isset($metadata['title']) ? $metadata['title'] : '');
    $frames .= RADIO_id3TextFrame('TPE1', isset($metadata['author']) ? $metadata['author'] : '');
    $frames .= RADIO_id3TextFrame('TALB', isset($metadata['collection_name']) ? $metadata['collection_name'] : '');
    $frames .= RADIO_id3TextFrame('TCON', isset($metadata['category']) ? $metadata['category'] : '');
    $frames .= RADIO_id3CommentFrame(isset($metadata['description']) ? $metadata['description'] : '');
    $frames .= RADIO_id3TxxxFrame('RADIO_TAGS', isset($metadata['tags']) ? $metadata['tags'] : '');
    $frames .= RADIO_id3TxxxFrame('RADIO_SERIES', isset($metadata['series_title']) ? $metadata['series_title'] : '');
    $frames .= RADIO_id3TxxxFrame('RADIO_SEASON', isset($metadata['season_number']) ? (int) $metadata['season_number'] : 0);
    $frames .= RADIO_id3TxxxFrame('RADIO_EPISODE', isset($metadata['episode_number']) ? (int) $metadata['episode_number'] : 0);
    $frames .= RADIO_id3TxxxFrame('RADIO_TYPE', isset($metadata['media_type']) ? $metadata['media_type'] : 'music');
    $frames .= RADIO_id3TxxxFrame('RADIO_ORIGINAL_NAME', isset($metadata['original_name']) ? $metadata['original_name'] : '');

    $payload = $frames . $preserved;
    // Small padding avoids rewriting the audio data for tiny future tag changes
    // only when a later implementation chooses to update in-place.
    $payload .= str_repeat("\x00", 1024);
    $header = 'ID3' . chr(3) . chr(0) . chr(0) . RADIO_id3IntToSynchsafe(strlen($payload));

    $source = @fopen($path, 'rb');
    if (!$source) {
        $error = 'metadata_read_failed';
        return false;
    }

    $tmp = $path . '.radio-id3-' . sha1(uniqid('', true));
    $target = @fopen($tmp, 'wb');
    if (!$target) {
        fclose($source);
        $error = 'metadata_write_failed';
        return false;
    }

    $audioOffset = isset($tag['audio_offset']) ? max(0, (int) $tag['audio_offset']) : 0;
    if ($audioOffset > 0) {
        fseek($source, $audioOffset);
    }

    $ok = fwrite($target, $header . $payload) !== false;
    if ($ok) {
        $copied = stream_copy_to_stream($source, $target);
        $ok = $copied !== false;
    }

    fclose($source);
    fclose($target);

    if (!$ok) {
        @unlink($tmp);
        $error = 'metadata_write_failed';
        return false;
    }

    $mode = @fileperms($path);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        $error = 'metadata_write_failed';
        return false;
    }
    if ($mode !== false) {
        @chmod($path, $mode & 0777);
    }

    clearstatcache(true, $path);
    return true;
}
