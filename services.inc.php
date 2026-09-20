<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

function RADIO_SERVICE_rejectWeb($args, &$svc_msg)
{
    if (is_array($args) && !empty($args['gl_svc'])) {
        $svc_msg = array('error_desc' => 'Radio services are available only to trusted internal plugin calls.');
        return true;
    }
    return false;
}

function RADIO_SERVICE_ok($value, &$output, &$svc_msg)
{
    $output = $value;
    $svc_msg = array();
    return defined('PLG_RET_OK') ? PLG_RET_OK : 0;
}

function RADIO_SERVICE_error($message, &$output, &$svc_msg)
{
    $output = array();
    $svc_msg = array('error_desc' => (string) $message);
    return defined('PLG_RET_ERROR') ? PLG_RET_ERROR : -1;
}

function RADIO_SERVICE_denied(&$output, &$svc_msg)
{
    $output = array();
    $svc_msg = array('error_desc' => 'Radio service access denied.');
    return defined('PLG_RET_PERMISSION_DENIED') ? PLG_RET_PERMISSION_DENIED : -2;
}

function RADIO_SERVICE_envelope($service, $data)
{
    return array(
        'provider' => 'radio',
        'schema_version' => 1,
        'service' => (string) $service,
        'generated_at' => time(),
        'data' => $data
    );
}

function RADIO_SERVICE_admin()
{
    return SEC_hasRights('radio.admin');
}

function RADIO_SERVICE_limit($args, $default, $max)
{
    $limit = is_array($args) && isset($args['limit']) ? (int) $args['limit'] : (int) $default;
    return max(1, min((int) $max, $limit));
}

function RADIO_SERVICE_liveData()
{
    $live = RADIO_getLiveState(time());
    $program = $live['program'];
    $media = $live['media'];

    return array(
        'source' => $live['source'],
        'timestamp' => date('c', (int) $live['timestamp']),
        'program' => $program !== false ? array(
            'id' => RADIO_externalId('program', (int) $program['program_id']),
            'title' => $program['program_title'],
            'url' => $program['url'],
            'start' => date('c', (int) $program['start']),
            'end' => date('c', (int) $program['end']),
            'elapsed' => isset($program['elapsed']) ? (int) $program['elapsed'] : 0
        ) : false,
        'media' => $media !== false ? array(
            'id' => isset($media['external_id']) ? $media['external_id'] : RADIO_externalId('media', (int) $media['media_id']),
            'title' => $media['title'],
            'media_type' => isset($media['media_type']) ? $media['media_type'] : '',
            'duration' => isset($media['duration']) ? (int) $media['duration'] : 0,
            'offset' => isset($media['offset']) ? (int) $media['offset'] : 0,
            'stream_url' => isset($media['stream_url']) ? $media['stream_url'] : '',
            'url' => isset($media['item_url']) ? $media['item_url'] : ''
        ) : false
    );
}

function service_now_playing_radio($args, &$output, &$svc_msg)
{
    $svc_msg = array();
    if (RADIO_SERVICE_rejectWeb($args, $svc_msg)) {
        return defined('PLG_RET_AUTH_FAILED') ? PLG_RET_AUTH_FAILED : -3;
    }
    return RADIO_SERVICE_ok(
        RADIO_SERVICE_envelope('radio.now_playing', RADIO_SERVICE_liveData()),
        $output,
        $svc_msg
    );
}

function service_upcoming_radio($args, &$output, &$svc_msg)
{
    $svc_msg = array();
    if (RADIO_SERVICE_rejectWeb($args, $svc_msg)) {
        return defined('PLG_RET_AUTH_FAILED') ? PLG_RET_AUTH_FAILED : -3;
    }

    $limit = RADIO_SERVICE_limit($args, 10, 50);
    $items = RADIO_getUpcoming($limit, time());
    $result = array();

    foreach ($items as $item) {
        $result[] = array(
            'id' => 'schedule:' . (int) $item['schedule_id'] . ':' . (int) $item['start'],
            'program_id' => RADIO_externalId('program', (int) $item['program_id']),
            'title' => $item['program_title'],
            'url' => $item['url'],
            'start' => date('c', (int) $item['start']),
            'end' => date('c', (int) $item['end'])
        );
    }

    return RADIO_SERVICE_ok(
        RADIO_SERVICE_envelope('radio.upcoming', array('items' => $result, 'count' => count($result))),
        $output,
        $svc_msg
    );
}

function service_replays_radio($args, &$output, &$svc_msg)
{
    $svc_msg = array();
    if (RADIO_SERVICE_rejectWeb($args, $svc_msg)) {
        return defined('PLG_RET_AUTH_FAILED') ? PLG_RET_AUTH_FAILED : -3;
    }

    $limit = RADIO_SERVICE_limit($args, 20, 50);
    $items = RADIO_getReplayService(array('limit' => $limit, 'timestamp' => time()));

    return RADIO_SERVICE_ok(
        RADIO_SERVICE_envelope('radio.replays', array('items' => $items, 'count' => count($items))),
        $output,
        $svc_msg
    );
}

function service_stats_radio($args, &$output, &$svc_msg)
{
    $svc_msg = array();
    if (RADIO_SERVICE_rejectWeb($args, $svc_msg)) {
        return defined('PLG_RET_AUTH_FAILED') ? PLG_RET_AUTH_FAILED : -3;
    }
    if (!RADIO_SERVICE_admin()) {
        return RADIO_SERVICE_denied($output, $svc_msg);
    }

    $days = is_array($args) && isset($args['days']) ? (int) $args['days'] : 30;
    $days = max(1, min(RADIO_statsRetentionDays(), $days));

    return RADIO_SERVICE_ok(
        RADIO_SERVICE_envelope('radio.stats', RADIO_getStatsSummary($days)),
        $output,
        $svc_msg
    );
}

function service_sources_radio($args, &$output, &$svc_msg)
{
    $svc_msg = array();
    if (RADIO_SERVICE_rejectWeb($args, $svc_msg)) {
        return defined('PLG_RET_AUTH_FAILED') ? PLG_RET_AUTH_FAILED : -3;
    }
    if (!RADIO_SERVICE_admin()) {
        return RADIO_SERVICE_denied($output, $svc_msg);
    }

    $limit = RADIO_SERVICE_limit($args, 20, 50);
    $sources = RADIO_getFeedSources($limit, false);
    $items = array();

    foreach ($sources as $source) {
        if (!RADIO_hasReadAccess($source) && !RADIO_hasEditAccess($source)) {
            continue;
        }
        $items[] = array(
            'id' => (int) $source['source_id'],
            'title' => $source['title'],
            'source_type' => $source['source_type'],
            'provider' => $source['provider'],
            'enabled' => !empty($source['enabled']),
            'sync_mode' => isset($source['sync_mode']) ? $source['sync_mode'] : 'preview',
            'last_checked' => (string) $source['last_checked'],
            'last_status' => (int) $source['last_status'],
            'has_error' => !empty($source['last_error']),
            'last_sync' => isset($source['last_sync']) ? (string) $source['last_sync'] : '',
            'last_sync_new' => isset($source['last_sync_new']) ? (int) $source['last_sync_new'] : 0,
            'last_sync_existing' => isset($source['last_sync_existing']) ? (int) $source['last_sync_existing'] : 0,
            'last_sync_imported' => isset($source['last_sync_imported']) ? (int) $source['last_sync_imported'] : 0,
            'last_sync_errors' => isset($source['last_sync_errors']) ? (int) $source['last_sync_errors'] : 0
        );
    }

    return RADIO_SERVICE_ok(
        RADIO_SERVICE_envelope('radio.sources', array(
            'summary' => RADIO_sourceSummary(),
            'feed_sync' => RADIO_feedSyncSummary(),
            'items' => $items,
            'count' => count($items)
        )),
        $output,
        $svc_msg
    );
}

function service_sync_status_radio($args, &$output, &$svc_msg)
{
    $svc_msg = array();
    if (RADIO_SERVICE_rejectWeb($args, $svc_msg)) {
        return defined('PLG_RET_AUTH_FAILED') ? PLG_RET_AUTH_FAILED : -3;
    }
    if (!RADIO_SERVICE_admin()) {
        return RADIO_SERVICE_denied($output, $svc_msg);
    }

    $limit = RADIO_SERVICE_limit($args, 10, 25);
    $logs = RADIO_getFeedSyncLog(0, $limit);
    $items = array();

    foreach ($logs as $log) {
        $source = RADIO_getFeedSource((int) $log['source_id'], false);
        if ($source === false || (!RADIO_hasReadAccess($source) && !RADIO_hasEditAccess($source))) {
            continue;
        }
        $items[] = array(
            'source_id' => (int) $log['source_id'],
            'source_title' => $source['title'],
            'mode' => $log['sync_mode'],
            'status' => $log['status'],
            'new' => (int) $log['new_count'],
            'existing' => (int) $log['existing_count'],
            'imported' => (int) $log['imported_count'],
            'errors' => (int) $log['error_count'],
            'message' => $log['message'],
            'created' => $log['created']
        );
    }

    return RADIO_SERVICE_ok(
        RADIO_SERVICE_envelope('radio.sync_status', array(
            'summary' => RADIO_feedSyncSummary(),
            'history' => $items,
            'count' => count($items)
        )),
        $output,
        $svc_msg
    );
}

function service_dashboard_summary_radio($args, &$output, &$svc_msg)
{
    global $_CONF;

    $svc_msg = array();
    if (RADIO_SERVICE_rejectWeb($args, $svc_msg)) {
        return defined('PLG_RET_AUTH_FAILED') ? PLG_RET_AUTH_FAILED : -3;
    }
    if (!RADIO_SERVICE_admin()) {
        return RADIO_SERVICE_denied($output, $svc_msg);
    }

    $summary = RADIO_dashboardSummary();
    $feed = isset($summary['feed_sync']) && is_array($summary['feed_sync'])
        ? $summary['feed_sync'] : array();

    $alerts = array();
    if (!empty($feed['sources_with_errors'])) {
        $alerts[] = array(
            'id' => 'source-errors',
            'status' => 'warning',
            'message' => (int) $feed['sources_with_errors'] . ' Radio source(s) need attention.',
            'count' => (int) $feed['sources_with_errors'],
            'url' => $_CONF['site_admin_url'] . '/plugins/radio/sources.php'
        );
    }

    $status = empty($alerts) ? 'ok' : 'warning';

    $output = array(
        'schema' => 1,
        'status' => $status,
        'metrics' => array(
            array('id' => 'media', 'label' => 'Media', 'value' => (int) $summary['media_count']),
            array('id' => 'programmes', 'label' => 'Programmes', 'value' => (int) $summary['program_count']),
            array('id' => 'scheduled', 'label' => 'Scheduled next 7 days', 'value' => (int) $summary['scheduled_next_7_days']),
            array('id' => 'replays', 'label' => 'Replays', 'value' => (int) $summary['replay_count']),
            array('id' => 'plays_30d', 'label' => 'Plays (30 days)', 'value' => isset($summary['stats']['plays']) ? (int) $summary['stats']['plays'] : 0),
            array('id' => 'feed_sources', 'label' => 'Enabled feed sources', 'value' => isset($feed['enabled_sources']) ? (int) $feed['enabled_sources'] : 0)
        ),
        'alerts' => $alerts,
        'links' => array(
            array('label' => 'Manage Radio', 'url' => $_CONF['site_admin_url'] . '/plugins/radio/index.php'),
            array('label' => 'Sources', 'url' => $_CONF['site_admin_url'] . '/plugins/radio/sources.php'),
            array('label' => 'Statistics', 'url' => $_CONF['site_admin_url'] . '/plugins/radio/stats.php')
        ),
        'updated' => time()
    );

    $svc_msg = array();
    return defined('PLG_RET_OK') ? PLG_RET_OK : 0;
}
