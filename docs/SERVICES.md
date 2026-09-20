# Radio read-only Geeklog services

Radio exposes bounded read-only services through Geeklog's native `PLG_invokeService()` dispatcher.

## Rules

- Services are internal inter-plugin APIs, not public HTTP/Atom webservices.
- `gl_svc` webservice-style calls are rejected.
- Content services preserve the current Geeklog user's Radio ACLs.
- Administrative summaries, source diagnostics, synchronization status and listening statistics require `radio.admin`.
- Services never perform feed synchronization, imports, uploads or other mutations.
- Feed source URLs are deliberately not exposed by the source-status service.
- Consumers should discover capabilities through `plugin_getcapabilities_radio()` and invoke the documented services rather than call Radio implementation helpers directly.

## Capability-to-service mapping

| Capability | Geeklog service | Access |
|---|---|---|
| `dashboard.summary` | `dashboard_summary` | Radio admin |
| `radio.now-playing.read` / `radio.current-media.read` | `now_playing` | current user ACL |
| `radio.upcoming.read` / `radio.schedule.read` | `upcoming` | current user ACL |
| `radio.replay.read` | `replays` | current user ACL |
| `radio.stats.read` | `stats` | Radio admin |
| `radio.source.summary` / `radio.source.feed.read` | `sources` | Radio admin |
| `radio.source.sync.read` | `sync_status` | Radio admin |

## Invocation

```php
$args = array('limit' => 10);
$output = array();
$svc_msg = array();

$ret = PLG_invokeService('radio', 'upcoming', $args, $output, $svc_msg);
if ($ret == PLG_RET_OK) {
    $items = $output['data']['items'];
}
```

## Envelopes

Most services return:

```php
array(
    'provider' => 'radio',
    'schema_version' => 1,
    'service' => 'radio.upcoming',
    'generated_at' => time(),
    'data' => array(...)
)
```

`dashboard_summary` follows the shared Eclipse dashboard contract directly:

```text
schema
status
metrics
alerts
links
updated
```

This avoids an Eclipse-specific adapter while keeping Radio authoritative for its counts, health and permissions.

## Bounds

- `upcoming.limit`: 1..50, default 10.
- `replays.limit`: 1..50, default 20.
- `sources.limit`: 1..50, default 20.
- `sync_status.limit`: 1..25, default 10.
- `stats.days`: 1..configured statistics retention, default 30.

The services do not refresh RSS/Atom feeds or contact external providers. Dashboard rendering therefore remains local and bounded.
