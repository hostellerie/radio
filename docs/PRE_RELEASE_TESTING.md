# Radio 0.2.1 pre-release validation

This checklist separates repository/CI validation from Geeklog runtime validation.

## Already enforced by CI

- versioned Radio public CSS/JS and admin JavaScript are packaged and loaded through Geeklog plugin callbacks;
- Radio 0.2.1 configuration upgrade includes `on_demand_enabled`;

- PHP syntax on PHP 5.6, 8.1 and 8.3.
- plugin.json baseline: Geeklog 2.1.1 / PHP 5.6.
- installable ZIP structure and hidden-file rejection.
- bounded internal service facade is present.
- RSS/Atom server-side fetching uses cURL with validated public DNS pinned through CURLOPT_RESOLVE.
- no hard-coded gl_ prefix in Radio DB calls.
- required administration/public endpoints are included in the archive.

## Runtime matrix still required

Run the complete smoke test on:

| Environment | Expected |
|---|---|
| Geeklog 2.1.1 / PHP 5.6 | install + upgrade + public/admin smoke |
| Geeklog 2.2.2 / PHP 8.1 | install + upgrade + public/admin smoke |

Local reference containers used by the project:

```text
geeklog211-web  -> http://localhost:8082
geeklog222-web  -> http://localhost:8081
```

## 1. Fresh installation

For each environment:

1. Upload/install `dist/radio_0.2.1_2.1.1.zip`.
2. Confirm the plugin manager records Data/Code 0.2.1.
3. Confirm the following tables exist with the active site prefix:
   - radio_media
   - radio_programs
   - radio_program_items
   - radio_schedule
   - radio_events
   - radio_sources
   - radio_source_sync_log
4. Confirm Radio Admin group/features are created.
5. Confirm persistent storage is created from the active site's `path_data`, not below the plugin directory.

## 2. Upgrade test

Start with the previous Radio archive/database state, then replace code with 0.2.1.

Verify:

1. Geeklog reports an upgrade rather than requiring reinstall.
2. Run the normal Geeklog plugin upgrade.
3. Existing media/programmes/schedules remain.
4. Existing persistent files/covers remain.
5. Running the upgrade a second time is harmless.
6. Data and Code versions become identical.

## 3. Local media security smoke

Upload one MP3 and one cover.

Verify:

- fake .mp3 renamed from text/image is rejected;
- oversized uploads are rejected;
- cover with fake extension/content is rejected;
- generated storage filename is unrelated to the original name;
- published anonymous-readable item is playable;
- draft item is not available through public media.php;
- restricted item returns no public media;
- download disabled returns 403 and is not counted as a download;
- download enabled works;
- `Range: bytes=0-99` returns 206;
- `Range: bytes=-100` returns the final 100 bytes;
- multi-range request is rejected with 416.

## 4. ACL smoke

Create:

- one public media item;
- one members-only media item;
- one private/draft media item;
- one programme mixing public and restricted media.

Verify anonymous, normal member and Radio Admin visibility separately on:

- catalogue;
- programme;
- replay;
- now-playing/live;
- podcast;
- Search;
- What's New;
- XML Sitemap;
- Item Info/service consumers.

Restricted media must never leak through a public programme.

## 5. Programme and schedule

Verify:

- create/edit/delete programme;
- add/remove/reorder items;
- one-time schedule;
- daily schedule;
- selected weekdays;
- weekly recurrence;
- active date ranges;
- conflict rejection;
- current programme;
- upcoming list;
- fallback rotation in an unscheduled gap.

If `radio.schedule` is delegated without `radio.admin`, confirm inaccessible programmes/media are not listed or mutable.

## 6. Public assets and listening modes

Verify:

- public Radio pages load `/radio/radio.css?v=0.2.1-<mtime>`;
- public Radio pages load `/radio/radio.js?v=0.2.1-<mtime>`;
- Radio admin upload/edit pages load `radio-admin.js?v=0.2.1-<mtime>`;
- no Radio inline player JavaScript remains in the generated page source;
- disabling `on_demand_enabled` hides the catalogue/on-demand players while keeping live listening available;
- enabling it restores on-demand playback;
- the home live player resynchronizes against `now.php` every 15 seconds.

## 7. Synchronized player

Verify:

- joining an already-running programme seeks to the expected offset;
- resynchronization after pause/browser sleep;
- transition to next item;
- transition from scheduled programme back to fallback rotation;
- no long-running PHP request remains open.

## 8. Replay, podcast and statistics

Verify:

- completed programme appears in replay;
- expired replay disappears after configured retention;
- podcast RSS validates;
- podcast enclosure obeys visibility/download rules;
- play/listen events are aggregated;
- allowed downloads increment the counter;
- rejected downloads do not;
- stats contain no IP, uid or persistent visitor identifier.

## 9. External sources / SSRF

Create a public HTTPS podcast feed.

Verify:

- preview works;
- redirects are followed only after validation;
- localhost/private/reserved URLs are rejected;
- sync preview mode imports nothing;
- drafts mode creates only new draft references;
- duplicate episodes are not recreated;
- feed response above 1 MB is rejected;
- source errors appear in diagnostics;
- source URL is not exposed by the internal `sources` service.

Server-side feed retrieval deliberately requires cURL + CURLOPT_RESOLVE. It must fail closed if DNS pinning cannot be provided.

## 10. Geeklog services

From a Geeklog test context invoke:

```php
PLG_invokeService('radio', 'dashboard_summary', array(), $output, $svc_msg);
PLG_invokeService('radio', 'now_playing', array(), $output, $svc_msg);
PLG_invokeService('radio', 'upcoming', array('limit' => 5), $output, $svc_msg);
PLG_invokeService('radio', 'replays', array('limit' => 5), $output, $svc_msg);
PLG_invokeService('radio', 'stats', array('days' => 30), $output, $svc_msg);
PLG_invokeService('radio', 'sources', array('limit' => 10), $output, $svc_msg);
PLG_invokeService('radio', 'sync_status', array('limit' => 10), $output, $svc_msg);
```

Verify:

- read services follow current-user ACL;
- admin-only services deny non-admins;
- dashboard_summary matches Eclipse schema 1;
- none of these services performs a remote feed refresh or mutation.

## 10. Eclipse / Agent

### Eclipse 1.2

Source-contract inspection confirms Eclipse discovers `dashboard.summary`, invokes `dashboard_summary`, validates schema 1 and renders metrics/alerts/links generically.

Runtime test still required: activate Radio + Eclipse and confirm the Radio card/metrics and source warning appear without any Eclipse-specific Radio code.

### Agent develop-1.0

Radio already exposes Item Info, capabilities and bounded services.

Current Agent provider catalog still explicitly contains Stories and Static Pages only. Do not add a Radio-specific workaround in Radio. Agent should gain generic capability-driven provider/service discovery in the Agent project, then Radio should be tested through that path.

## 11. Multisite isolation

With two Geeklog sites sharing Radio code:

1. use different database prefixes;
2. use different `path_data`;
3. install/upgrade one site first;
4. verify the other site remains usable until its own upgrade;
5. upload media on site A and confirm site B cannot enumerate/read/delete it;
6. configure a feed on site A and confirm site B cannot see its source state/logs;
7. confirm stats and configuration remain site-specific.

## 12. Release gate

Do not label Radio stable until:

- both Geeklog runtime environments pass this checklist;
- multisite isolation is verified;
- no high-severity security finding remains;
- Agent generic provider integration is tested or explicitly documented as post-release optional;
- administrator/developer documentation matches the supported feature set.
