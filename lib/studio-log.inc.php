<?php

/**
 * Central Studio diagnostics.
 *
 * All Studio server-side diagnostics go to Geeklog's radio.log. This helper is
 * intentionally dependency-light so it can be used by the AJAX endpoint and
 * CLI workers after Geeklog has bootstrapped.
 */

function RADIO_studioLog($event, $context = array(), $level = 'INFO')
{
    global $_CONF;

    $level = strtoupper(trim((string) $level));
    if ($level === '') {
        $level = 'INFO';
    }

    $parts = array();
    if (is_array($context)) {
        foreach ($context as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }

            $value = str_replace(array("\r", "\n"), ' ', (string) $value);
            $parts[] = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $key)
                . '=' . $value;
        }
    }

    $line = '[' . date('Y-m-d H:i:s') . '] [Studio] [' . $level . '] '
        . trim((string) $event)
        . (!empty($parts) ? ' ' . implode(' ', $parts) : '')
        . PHP_EOL;

    $logDir = isset($_CONF['path_log']) ? rtrim((string) $_CONF['path_log'], '/\\') : '';
    $path = $logDir !== '' ? $logDir . DIRECTORY_SEPARATOR . 'radio.log' : '';

    if ($path !== '' && @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) {
        return true;
    }

    error_log(trim($line));
    return false;
}

function RADIO_studioInstallFatalLogger()
{
    register_shutdown_function(function () {
        $error = error_get_last();
        if (!is_array($error)
            || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
            return;
        }

        RADIO_studioLog('php.fatal', array(
            'type' => isset($error['type']) ? (int) $error['type'] : 0,
            'message' => isset($error['message']) ? $error['message'] : '',
            'file' => isset($error['file']) ? $error['file'] : '',
            'line' => isset($error['line']) ? (int) $error['line'] : 0
        ), 'ERROR');
    });
}
