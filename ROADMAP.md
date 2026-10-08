# Radio for Geeklog — Roadmap


## Vision

Radio is an independent Geeklog plugin for publishing, organizing, scheduling and playing audio content.

It is not a port of the historical radio script and it is not a MediaGallery feature. The old script may be used only as a functional reference for useful ideas such as playlists, non-repetition rules, jingles, direct listening and downloads.

The target model is:

```text
Audio media
    ↓
Episodes / programme items
    ↓
Programmes / shows / playlists
    ↓
Schedule
    ↓
Now playing / replay / podcast / download
```

Radio should remain usable without MediaGallery, Eclipse, Hub, Agent, Icecast or any external provider. Integrations must be optional and capability-driven.

---

## Compatibility and development principles

During the current Geeklog modernization period, follow the shared memorandum compatibility target unless the project policy is explicitly revised:

- Geeklog 2.1.1 through 2.2.2;
- PHP 5.6 through PHP 8.1;
- feature detection where possible instead of hard-coded version checks;
- no hard-coded `gl_` table prefix;
- use the active site context and Geeklog ACL;
- keep compatibility code isolated.

Before the first stable release, re-evaluate whether Radio should keep the transition baseline or move to the post-migration Geeklog 2.2.2 / PHP 8.1 baseline.

---

## Implementation status snapshot — 0.6.2

The original roadmap was intentionally broad. The implementation has now advanced beyond the initial 0.1.x foundation in several areas.

Current state:

- **Foundation / storage / installer:** substantially implemented; remaining work is mainly compatibility testing and security audit.
- **Local media / public player:** implemented for core upload, metadata editing, covers, ACL, controlled delivery, HTML5 playback, categories, collections, tags and combined library filtering; full codec inspection remains open.
- **Programmes / scheduling / synchronized radio:** core model, recurrence, weekly schedule, deterministic fallback rotation, now-playing and synchronized offset are implemented.
- **Replay / podcast:** replay, RSS podcast generation, podcast metadata and listening/download statistics are implemented; richer per-programme download policies remain open.
- **External sources:** direct remote references, live stream references, bounded RSS/Atom preview/import and controlled feed synchronization are implemented experimentally; provider allowlists, credentialed providers and deeper MIME/content validation remain open.
- **Interoperability:** Item Info, lifecycle events, URL resolution, Search, What’s New, XML Sitemap, related items, capability declaration and bounded Geeklog services are implemented.
- **Public availability / SEO:** the global switch is explicitly a public-Radio switch; disabling it keeps administration and YouTube output independent, withdraws Radio URLs from the sitemap, returns HTTP 410 to public requests, and re-emits lifecycle events so integrations such as IndexNow can retire previously submitted URLs. Re-enabling republishes those lifecycle events.
- **Eclipse / Agent readiness:** structured dashboard, now-playing, upcoming, replay, source/sync and stats services are implemented. Agent/Eclipse integration still needs end-to-end testing against their current branches.
- **Hub:** Radio exposes the contracts Hub can consume, but explicit Hub relationship workflows are not yet implemented/tested.
- **Security / multisite / compatibility:** pre-release hardening is in progress. Shared media now supports independent per-site databases with one common audio directory, MP3 ID3 metadata synchronization and conflict-safe tag writes. CSRF on remote fetches, RSS/Atom SSRF DNS pinning, upload signatures, media/download ACLs and PHP 5.6/8.1/8.3 syntax are CI/audit covered. Geeklog 2.1.1/2.2.2 runtime tests and two-site isolation still remain open.
- **Studio / browser DJ:** the administration Studio is now operational as a live mixing surface around the programme queue. It supports current/next/reserve buffering, transitions/crossfade, EQ, filter, pan, headroom, echo, visual monitoring and assignable jingle pads. The existing `Broadcast` session remains editorial and is independent from YouTube output.
- **YouTube Live:** server-side FFmpeg/RTMPS output is implemented with manual and scheduled modes, Station Card / Full Background / Minimal / Visualizer templates, white/green visual styles, selectable `line` / `cline` / `p2p` waveforms, artwork/programme/track overlays and live FPS/bitrate/encoding-speed diagnostics. Current automatic YouTube output still renders programme media server-side; it does not yet receive the browser Studio's post-FX master mix.
- **Next active step — Studio master output refactor:** keep one explicit post-FX/post-limiter master audio bus that feeds local monitoring, recording and Studio-driven YouTube Live, but separate editorial Studio actions from realtime output transport. `admin/studio-api.php` remains the short-lived playlist/search/broadcast API. `admin/studio-stream.php` is now the dedicated authenticated JSON control/ingest boundary for Record and Studio YouTube Live and intentionally does not include Geeklog `admin/auth.inc.php`. This removes themed HTML error handling from the realtime path and prevents an unrelated admin bootstrap failure from breaking live output. Recording remains in `lib/studio-output.inc.php`, live state in `lib/studio-live.inc.php`, and diagnostics in `lib/studio-log.inc.php`. The detached Studio PHP CLI relay has now been removed: the realtime layer owns one detached `tail → FFmpeg` process group directly, without bootstrapping Geeklog a second time.

# Phase 0 — Architecture and plugin skeleton

- [x] Define the canonical plugin id as `radio`.
- [x] Create standard Geeklog plugin structure, installer, uninstall and upgrade paths.
- [x] Add `plugin.json` following the memorandum metadata manifest.
- [ ] Add repository-root `README.md`, `ROADMAP.md` and changelog/release notes convention.
- [x] Add automated packaging to `dist/radio-x.y.z.zip`.
- [x] Ensure generated archives contain no dot-prefixed files that can break Geeklog plugin upload/install workflows.
- [x] Define database tables through `$_TABLES`; never hard-code table prefixes.
- [ ] Define permissions for administration, upload, edit, schedule, publish, download management and playback of restricted content.
- [x] Define clean separation between persistent audio files, generated/cache data and plugin executable files.

Candidate logical tables:

```text
radio_media
radio_programs
radio_program_items
radio_schedule
radio_categories
radio_history
```

Optional later tables:

```text
radio_downloads
radio_play_stats
radio_external_sources
```

The final schema should be driven by stable domain objects rather than UI screens.

---

# Phase 1 — Audio media library

## Local audio management

- [x] Upload audio files from the administration interface.
- [x] Support safe drag-and-drop and batch upload.
- [x] Validate extension, detected MIME type, lightweight audio file signature and configured size limits for local uploads.
- [x] Generate filesystem-safe storage names independently from the uploaded filename.
- [x] Keep the original human filename and metadata separately when useful.
- [x] Read/write shared MP3 ID3 metadata for title, artist, collection, category, tags, description and Radio classification; embedded artwork remains open.
- [x] Let administrators correct or override extracted metadata.
- [ ] Support at least the formats that can be played reliably by current browsers; document the accepted format matrix.
- [x] Store title, author/artist, description, category, collection, tags, duration, file size, publication state and dates.
- [x] Support a media subtype such as:
  - music;
  - podcast;
  - interview;
  - show;
  - chronicle;
  - jingle;
  - announcement;
  - promo.
- [x] Support an optional cover/image.
- [ ] Support draft, published, disabled and archived states.
- [x] Support per-item download permission.
- [x] Separate media publication from independent `on_demand` and `broadcast` availability.
- [x] Separate scheduled broadcast eligibility from automatic rotation eligibility.

## Persistent storage

Follow `plugin-persistent-storage-guide.md` and `multisite-development-principles.md`.

- [x] Derive persistent storage from the current site's `$_CONF['path_data']`.
- [x] Keep uploaded media outside disposable plugin/cache directories.
- [x] Prefer storage outside the public web root where practical.
- [x] Provide controlled file delivery when ACL, logging or download rules require it.
- [x] Make storage initialization failures explicit.
- [x] Ensure cache cleanup can never delete persistent audio.
- [x] Keep all migration procedures non-destructive and idempotent.
- [x] Never scan or modify sibling multisite storage during normal requests.

---

# Phase 2 — Public audio player

- [x] Provide an HTML5-based player.
- [x] Display title, author/artist, programme and cover where available.
- [x] Expose play/pause, seek and volume controls where the playback mode permits them.
- [x] Provide a controlled download action when enabled.
- [x] Provide canonical public pages for addressable media.
- [x] Support responsive presentation.
- [ ] Provide accessible controls, labels and keyboard operation.
- [ ] Define reusable rendering helpers/autotags only after the basic content model is stable.

Radio must not reproduce the legacy architecture where PHP maintains a long-running request and manually streams an endless MP3 byte sequence.

Normal HTTP media delivery and browser range requests should be preferred.

---

# Phase 3 — Programmes, shows and playlists

- [x] Add a `programme` / `show` object independent from the underlying media files.
- [x] Allow a programme to contain ordered programme items.
- [x] Reuse the same audio item in multiple programmes without duplicating the file.
- [x] Support programme metadata:
  - title;
  - description;
  - image;
  - author/host;
  - category;
  - publication state.
- [ ] Provide an intuitive item-ordering interface.
- [x] Allow jingles, announcements and promotional items in the same sequence as music and spoken content.
- [ ] Allow a programme to reference another reusable playlist where this remains understandable for administrators.

Example:

```text
Jingle
  → introduction
  → music
  → music
  → chronicle
  → music
  → closing jingle
```

---

# Phase 4 — Scheduling

## Daily and weekly schedule

- [x] Provide day and week administration views.
- [x] Schedule a programme once or recurrently.
- [x] Support:
  - one-time broadcasts;
  - daily recurrence;
  - selected weekdays;
  - weekly recurrence;
  - active date ranges.
- [x] Detect schedule conflicts.
- [x] Expose the currently scheduled item.
- [x] Expose upcoming programmes.
- [x] Define deterministic behaviour for gaps in the schedule.
- [x] Support automatic fallback playlists when no explicit programme is scheduled.
- [ ] Keep scheduling calculations timezone-aware and based on the active Geeklog site configuration.

## Rotation rules

- [x] Prevent the same media item from replaying within the deterministic rotation cycle; expose a configurable minimum-repeat target diagnostic.
- [ ] Optionally prevent the same artist/author from repeating within a configurable number of items.
- [ ] Allow category-restricted rotations.
- [x] Allow deterministic weighted selections by media type.
- [ ] Support priority and active date ranges.
- [x] Allow periodic insertion of jingles and announcements/promos.
- [ ] Keep rotation history separate from permanent editorial content.

---

# Phase 5 — Synchronized web radio

Implement a web-radio mode without requiring a dedicated streaming server.

Conceptual flow:

```text
schedule
   ↓
Radio resolves the item that should be playing now
   ↓
start time + media duration
   ↓
browser starts the audio at the calculated position
```

- [x] Resolve `now playing` from schedule and rotation rules.
- [x] Calculate playback offset for visitors joining an already-started item.
- [x] Return enough structured data for the browser player to stay synchronized.
- [ ] Recover gracefully after pause, browser sleep or connectivity loss.
- [x] Define behaviour at programme/item transitions.
- [x] Avoid long-running PHP streaming loops.

This mode must work on a standard Geeklog web server.

---

# Phase 6 — Replay and downloads

- [x] Make completed scheduled programmes available as replay without duplicating media files.
- [x] Provide immediate time-limited replay using `default_replay_days`; delayed/per-programme/permanent policies remain for a later iteration.
- [x] Keep replay as a reference to existing media/programme objects rather than duplicate files.
- [ ] Support per-item and per-programme download policy.
- [x] Enforce Geeklog permissions before controlled downloads.
- [x] Optionally record download counts.
- [x] Keep listening statistics separate from permissions and editorial state.

---

# Phase 7 — Podcast support and content syndication

- [x] Treat podcast episodes as first-class Radio content, not as a separate storage silo.
- [x] Support series/show, season, episode and author metadata where appropriate.
- [x] Expose podcast-friendly descriptions, dates, duration, artwork and canonical URLs.
- [x] Implement Geeklog native Content Syndication callbacks: `plugin_getfeednames_radio()`, `plugin_getfeedcontent_radio()` and `plugin_feedupdatecheck_radio()`.
- [x] Provide a dedicated RSS 2.0 podcast feed with audio `enclosure` while keeping Radio media as the source of truth.
- [x] Keep feed generation permission-aware for the public podcast feed.
- [ ] Preserve canonical source attribution and enclosure/download rules.

---

# Phase 8 — External audio sources — research and prototype

External sources are intentionally separated from the first local-library implementation.

The plugin should distinguish **remote metadata**, **remote playback**, **cached remote data** and **local import**.

## Candidate source types

Study support for:

- [x] direct remote audio URLs as browser-side remote references;
- [x] podcast RSS/Atom feeds with bounded preview and explicit remote-reference import;
- [x] Icecast/Shoutcast-compatible live stream URLs as browser-side live references;
- [ ] remote playlists where the format and licensing permit it;
- [ ] public/open audio archives and media catalogues;
- [ ] provider metadata/oEmbed-like endpoints where useful;
- [ ] audio already managed by another Geeklog plugin through an exposed capability, without direct SQL coupling.

## Operating modes

### A. Remote reference / playback

```text
Remote provider → Radio metadata/reference → browser playback
```

The provider remains the source of truth.

### B. Cached metadata

```text
Remote provider → bounded cache → Radio
```

Useful for availability, latency and rate limits.

### C. Controlled import

```text
Remote provider → validation → local persistent Radio media
```

The local site becomes responsible for the imported copy.

### D. Live stream

```text
Icecast / Shoutcast / compatible source
             ↓
          Radio UI
```

Radio presents and schedules the source but does not reimplement the streaming server.

## Google Drive external provider

- [ ] Add first-class Google Drive support as an external Radio provider instead of relying on manually crafted download URLs.
- [ ] Recognize common Google Drive sharing URLs such as `https://drive.google.com/file/d/FILE_ID/view` and extract the stable Drive file ID.
- [ ] Store Drive-backed media using provider metadata such as:
  - `source_kind = external`
  - `source_provider = google-drive`
  - `source_external_id = FILE_ID`
- [ ] Retrieve and display useful Drive metadata when available, including filename, MIME type, size, ownership/download capability and modification information.
- [ ] Check whether the Drive file can actually be downloaded/streamed before exposing it as playable Radio media.
- [ ] Support public/shared Drive files in a limited mode without requiring administrator credentials when the file is genuinely accessible.
- [ ] Support private Drive files through the Google Drive API using authenticated access and `files.get(..., alt=media)`.
- [ ] Never store OAuth access tokens, refresh tokens or client secrets inside Radio media records.
- [ ] Keep Google provider credentials/site authorization in site-specific configuration or secure persistent storage, with explicit multisite isolation.
- [ ] Resolve Drive playback through a provider adapter/service layer rather than adding Google-specific conditionals directly to `RADIO_sendMedia()`.
- [ ] Handle expired authorization, revoked sharing, download-disabled files, quota errors and unavailable files with administrator-visible diagnostics.
- [ ] Preserve normal Radio ACL independently from Google Drive sharing permissions: a Drive file being public must not automatically make the Radio item public.
- [ ] Expose provider/source health through Radio services so Eclipse, Agent and Hub can report unavailable Drive-backed media.
- [ ] Document the limitations of using Google Drive as media hosting and recommend dedicated media hosting/CDN for high-volume public streaming.
- [ ] Add compatibility/security tests for Drive URL parsing, provider isolation, ACL, redirect handling and authenticated media retrieval.

## Requirements before enabling arbitrary external sources

Follow the principles in `geeklog-external-data-integration-vision-2030.md`.

- [ ] Define an allowlist/provider model rather than unrestricted editor-controlled server-side URL fetching.
- [x] Reject localhost, literal private/reserved IPs and hostnames resolving to private/reserved IPv4 before accepting remote references; no server-side remote fetching is performed in this prototype.
- [x] Validate every feed redirect before following it.
- [x] Apply bounded connection and total timeouts to feed retrieval.
- [x] Limit feed responses to 1 MB; episode imports remain remote references and do not copy audio files.
- [ ] Validate MIME and actual audio type.
- [ ] Sanitize remote metadata.
- [x] Use conditional ETag/Last-Modified feed checks and preserve the previous source state on 304 responses; broader metadata cache TTL remains optional.
- [ ] Record provenance:
  - provider;
  - source URL;
  - external id;
  - source timestamp;
  - retrieved time;
  - attribution;
  - license where known.
- [ ] Preserve copyright and redistribution rules.
- [x] Never assume that technical accessibility grants republication or download rights; remote references default to no Radio download.
- [ ] Isolate credentials, caches, rate limits and synchronization state per Geeklog site.
- [ ] Never expose provider credentials in public templates or client-side code.
- [x] Provide administrator diagnostics, per-source sync counters and a bounded synchronization history for remote feeds.

Provider-specific integrations should remain replaceable and must not become hard-coded Core requirements.

---

# Phase 9 — Geeklog content interoperability

Follow `plugin-content-interoperability-contract.md`.

## Item Info

- [x] Implement `plugin_getiteminfo_radio()`.
- [x] Expose stable normalized fields where meaningful:
  - `id`;
  - `type`;
  - `subtype`;
  - `title`;
  - `url`;
  - `canonical_url`;
  - `description` / `excerpt`;
  - `date-created`;
  - `date-modified`;
  - `uid`;
  - `author`;
  - `image`;
  - `category`;
  - `tags`;
  - `hits` when a real persisted counter exists.
- [x] Support `id='*'` collection retrieval.
- [x] Support common bounded options:
  - `since`;
  - `limit`;
  - `order`.
- [ ] Add subtype/category filtering where it solves actual consumer needs.
- [x] Keep permission checks and canonical URL construction inside Radio.

## Lifecycle

- [x] Emit `PLG_itemSaved()` after successful creation or modification of addressable content.
- [x] Emit `PLG_itemDeleted()` after successful deletion.
- [x] Cover normal administration and explicit feed imports; current Geeklog services are intentionally read-only.
- [ ] Preserve subtype information when the active Geeklog API provides it.

## URL resolution

- [x] Implement `plugin_idtourl_radio()` where supported.
- [x] Keep `url` available through Item Info as the compatibility fallback.

## Optional native Geeklog surfaces

Evaluate according to usefulness:

- [x] Geeklog search integration for published media and programmes;
- [x] What's New integration with configurable interval and limit;
- [x] XML Sitemap native collector for published media and programmes;
- [ ] native statistics callbacks;
- [ ] autotags/embedding;
- [ ] dynamic blocks;
- [x] Related-items callback using real Geeklog topic assignments when they exist.

---

# Phase 10 — Shared capability contract

Follow `plugin-capability-contract.md`.

Radio should declare capabilities once and let Agent, Hub, Eclipse and future consumers discover them.

Initial candidate declaration:

```text
roles:
  content
  service

capabilities:
  content.read
  content.collection
  content.search
  content.url.resolve
  content.lifecycle
  content.syndication
  dashboard.summary
```

Radio-specific capabilities should use stable dotted names and be backed by provider-owned functions/services.

Candidate future capabilities:

```text
radio.media.read
radio.program.read
radio.schedule.read
radio.now-playing.read
radio.upcoming.read
radio.replay.read
radio.source.status
```

Potential authorized actions, if ever exposed, must remain separate from readable capabilities:

```text
radio.media.create
radio.program.update
radio.schedule.update
radio.source.import
```

- [x] Add `plugin_getcapabilities_radio()` and expose shared content/service capabilities.
- [x] Use bounded Geeklog services for specialized data that does not naturally fit Item Info.
- [x] Do not create Agent-specific, Hub-specific or Eclipse-specific parallel APIs.
- [x] Never treat a declared capability as write authorization; Radio 0.1.9 services are read-only.

---

## Implemented Radio 0.1.9 service facade

Radio now exposes the following read-only internal Geeklog services through `PLG_invokeService()`:

| Capability | Service action | Access |
|---|---|---|
| `dashboard.summary` | `dashboard_summary` | `radio.admin` |
| `radio.now-playing.read` / `radio.current-media.read` | `now_playing` | current user ACL |
| `radio.upcoming.read` / `radio.schedule.read` | `upcoming` | current user ACL |
| `radio.replay.read` | `replays` | current user ACL |
| `radio.stats.read` | `stats` | `radio.admin` |
| `radio.source.summary` / `radio.source.feed.read` | `sources` | `radio.admin` |
| `radio.source.sync.read` | `sync_status` | `radio.admin` |

The services are read-only, reject public `gl_svc` webservice-style calls and do not trigger external feed refreshes or imports.

---

# Phase 11 — Agent and machine-readable representation

Follow `llm-agent-content-representation-contract.md`.

- [x] Make Radio content consumable without scraping theme HTML through Item Info and bounded services.
- [x] Keep stable identity as plugin + type + id + optional subtype.
- [x] Expose clean content/metadata representations for episodes, programmes, now-playing, upcoming schedule entries and replays.
- [x] Preserve canonical human URLs.
- [ ] Expose language, licensing and attribution fields when known.
- [x] Let Agent answer provider-neutral questions such as:
  - what is playing now?
  - what is next?
  - what are the latest podcast episodes?
  - what programmes are scheduled tomorrow?
  - what are the most-listened-to addressable items, if Radio maintains a real counter?
- [x] Keep discovery, resources, capabilities and authorized actions separate.
- [x] Do not expose private/restricted Radio content through machine-facing resources.

---

# Phase 12 — Eclipse dashboard integration

Radio should expose structured data; Eclipse should decide presentation.

Candidate dashboard summary:

```text
Radio
214 media
18 programmes
7 scheduled this week
Now playing: ...
External sources: 3 / 3 healthy
```

- [x] Expose `dashboard.summary` through the shared capability/service model.
- [x] Do not make Radio depend on Eclipse.
- [x] Do not let Eclipse query Radio tables directly; use `dashboard_summary`.
- [x] Expose the current dashboard summary set:
  - media count;
  - published programme count;
  - upcoming scheduled count;
  - now-playing title;
  - replay count;
  - external-source health summary.

---

# Phase 13 — Hub integration

- [ ] Let Hub consume normalized Radio identities and lifecycle events.
- [ ] Expose programme/episode relationships without making Hub understand Radio SQL.
- [ ] Allow Hub to relate stories, documents, videos, maps or other content to Radio episodes/programmes.
- [ ] Evaluate `content.related` only after the base identity/content contract is stable.
- [x] Keep Radio as the owner of Radio content and business rules.

---

# Phase 14 — Optional MediaGallery interoperability

Radio remains independent from MediaGallery.

Possible optional integrations:

- [ ] select a MediaGallery image as programme/episode artwork;
- [ ] discover reusable audio/media exposed by MediaGallery if a stable shared capability exists;
- [ ] import/copy a MediaGallery-managed audio asset into Radio only through an explicit controlled operation;
- [ ] expose Radio media to MediaGallery only if a future shared media contract makes this useful.

Rules:

- no required MediaGallery dependency;
- no direct reads of MediaGallery private SQL tables;
- no duplication of MediaGallery's gallery responsibilities inside Radio;
- integration must use shared Geeklog content/capability contracts.

---


## Manual live takeover / on-air override

Radio should eventually support an explicit manual live mode that temporarily overrides the normal schedule without turning Geeklog into a streaming server.

Target priority:

```text
1. Manual live takeover
2. Scheduled programme
3. Automatic fallback rotation
```

- [ ] Add a manual `live_override` / on-air state owned by Radio.
- [ ] Keep the live source external to Geeklog: Icecast/Shoutcast-compatible stream, studio encoder or another supported streaming backend.
- [ ] Allow an authorized administrator to start and stop a live takeover from Radio administration.
- [ ] Store only the operational state and editorial metadata needed by Radio, for example:
  - enabled;
  - source id / provider reference;
  - title;
  - host / presenter;
  - started at;
  - started by;
  - optional expected end;
  - optional public description / artwork.
- [ ] Make the manual live state take priority in `now playing`, `live.php`, Agent-facing services and Eclipse dashboard data.
- [ ] When the live takeover ends, automatically return to the currently scheduled programme if one exists, otherwise to automatic rotation.
- [ ] Never require a long-running PHP request for live audio transport.
- [ ] Keep stream credentials and encoder secrets outside public Radio media records and templates.
- [ ] Distinguish editorial "on air" state from the actual stream health state.
- [ ] Expose clear administrator diagnostics when the declared live stream is unavailable.
- [ ] Add lifecycle/audit information for live start/stop events without collecting unnecessary listener identity data.
- [ ] Ensure ACL and multisite isolation for live controls, provider credentials and site-specific on-air state.
- [ ] Keep this feature compatible with later dedicated broadcast backends and provider adapters.

Possible administration concept:

```text
LIVE

Status: Off air
Source: Main studio
Title: Special broadcast
Host: ...

[ Start live ]

When active:

LIVE — ON AIR
Started: ...
Source: ...
[ Stop live ]
```

## Browser DJ console / live mixing

After the manual live takeover and external streaming path are reliable, evaluate a browser-based DJ console for authorized Radio administrators.

Architecture principle:

```text
Geeklog Radio admin
        ↓
browser DJ console (Web Audio)
        ↓
encoded live mix
        ↓
Icecast / Shoutcast / compatible streaming backend
        ↓
/radio/live.php listeners
```

Radio must remain the editorial/orchestration layer. Geeklog/PHP must not become the long-running audio transport process.

### Stage 1 — External DJ / studio encoder

Implement this before an integrated browser mixer.

- [ ] Allow an authorized administrator to select a configured live streaming source and take over the current Radio output.
- [ ] Support common external encoders such as Mixxx, BUTT, OBS or another Icecast/Shoutcast-capable client.
- [ ] Expose a clear `Go live` / `Stop live` workflow in Radio administration.
- [ ] Show source health, connection state, presenter/DJ name, current title and start time.
- [ ] Keep encoder/server credentials site-scoped and server-side.
- [ ] Automatically return to the scheduled programme or automatic rotation when the live takeover stops.
- [ ] Keep listeners on one continuous live endpoint where practical so switching between automation and DJ mode does not require a page reload.
- [ ] Expose the live takeover state consistently to `now.php`, `/radio/`, `/radio/live.php`, Agent, Eclipse and future Hub consumers.

### Stage 2 — Integrated Web DJ console

The first integrated Studio mixer is now substantially implemented. It intentionally evolved from the existing programme/replay engine instead of introducing two unrelated deck implementations.

- [x] Add an optional browser DJ console restricted to authorized Radio administrators.
- [x] Reuse the programme queue as the principal deck, with current, next and reserve media preloading.
- [x] Provide play/pause, seek/current position, elapsed/remaining information and current media metadata.
- [x] Provide configurable transitions/crossfade between programme items.
- [x] Add live Web Audio processing for low/mid/high EQ, filter, pan, headroom and echo.
- [x] Add visual waveform/spectrum feedback for the Studio mix.
- [x] Add assignable jingle/sample pads mixed above the programme, with short programme ducking for intelligibility.
- [x] Keep the existing Studio `Broadcast` session separate from YouTube streaming state.
- [ ] Add a true independent Deck B only if cueing/manual two-deck operation proves necessary beyond the current next/reserve queue model.
- [ ] Allow optional microphone input through `getUserMedia()` with explicit browser permission.
- [ ] Provide microphone gain/mute and clear on-air state.
- [ ] Keep local monitoring / preview separate from the public on-air mix where browser capabilities permit it.
- [ ] Publish current DJ/presenter and current/next track metadata through Radio services.
- [ ] Protect against accidental double live sessions: only one authorized Studio may own a site live output at a time.
- [ ] Handle loss of browser/network connection with a deterministic timeout and safe YouTube/recording shutdown or fallback.

### Stage 3 — Master audio bus, recording and Studio YouTube Live

The first implementation pass is now in place on `develop-1.0`. Runtime validation on the target Geeklog server and long-session stability testing remain required before this is considered production-ready.

Architecture:

```text
programme / next-reserve mix ─┐
jingle pads ──────────────────┤
future microphone ────────────┤
EQ / filter / pan / echo ─────┘
                 ↓
          MASTER LIMITER
                 ↓
          MASTER AUDIO BUS
        ┌────────┼───────────┐
        ↓        ↓           ↓
 local monitor  recorder   live transport
                            ↓
                         FFmpeg
                            ↓
                      YouTube RTMPS
```

Implementation rules:

- [x] Introduce exactly one post-FX/post-limiter master node as the canonical Studio output; do not build separate audio graphs for speakers, recording and YouTube.
- [x] Keep local monitoring connected exactly as today so enabling the master bus does not change ordinary Studio sound, gain staging, crossfades, pads or effects.
- [x] Expose the master through a `MediaStreamAudioDestinationNode` (when supported) so downstream consumers receive the exact mixed signal after pads and effects.
- [x] Treat capture outputs as optional branches. With neither recording nor live output active, Studio behaviour and resource use should remain close to the current implementation.
- [x] Add an explicit `Record` / `Stop recording` control in Studio. Recording must always be a deliberate administrator action.
- [x] Record the master mix, not the source playlist. The recording therefore includes transitions, pads, effects and future microphone audio exactly as heard on air.
- [x] Prefer browser `MediaRecorder` for capture when supported, but persist completed recordings through a bounded, authenticated Radio endpoint rather than relying only on a browser download.
- [x] Store Studio recordings in Radio persistent storage, outside executable/plugin directories, with collision-safe filenames and sidecar metadata (programme, start/end, MIME/codec, operator where appropriate).
- [x] Never make a recording public automatically. Import/publish/replay must remain an explicit later editorial action.
- [x] Add a separate `Live YouTube` control; do not overload the existing `Broadcast` button.
- [x] Starting Studio YouTube Live must create one persistent encoder/RTMPS session. Track changes, crossfades, pads and FX must not restart FFmpeg or the YouTube ingest connection.
- [x] Allow YouTube to reach a ready/live state before the first track is started. Until Studio audio arrives, the server-side live output should keep valid video timing and silence rather than dropping RTMP.
- [x] Reuse the current YouTube visual templates, waveform styles, bitrate/FPS settings and diagnostics for Studio Live rather than creating a second video-rendering implementation.
- [x] Keep automatic/scheduled YouTube output and Studio YouTube Live as distinct source modes sharing one encoder/visual layer.
- [x] Never expose the YouTube stream key or encoder command to browser JavaScript.
- [x] Do not use a single long-running PHP web request as the audio transport. Browser-to-server transport must be chunked/bounded or delegated to a dedicated helper process while Geeklog/PHP remains the authenticated control plane.
- [x] Define deterministic behaviour for browser refresh, network loss and abandoned Studio sessions before calling Studio YouTube Live stable.
- [x] Surface Studio Live state and encoder health in the Studio: connection state, FPS, bitrate and realtime encoder speed.
- [x] Preserve PHP 5.6 syntax in server-side plugin code and use browser feature detection for Web Audio / MediaRecorder support.

Current implementation notes:

- recording and Studio Live use independent `MediaRecorder` consumers of the same master stream, so either output can be enabled alone or both can run together;
- **refactor step 1 complete:** Record and Studio YouTube Live control/chunk/status traffic is routed through the dedicated `admin/studio-stream.php` endpoint; playlist/search/editorial mutations remain on `admin/studio-api.php`;
- the realtime endpoint performs explicit Radio rights + programme ACL checks and returns JSON directly instead of loading Geeklog `admin/auth.inc.php`; this specifically removes the former `html_response:http_500_..._stage_after_auth` failure path from Record/YouTube Live;
- browser audio is still uploaded in short authenticated chunks during this compatibility stage;
- **refactor step 2 complete:** the detached `bin/studio-youtube-live.php` PHP CLI relay has been deleted; Studio Live now owns one detached `tail → FFmpeg` process group and no longer performs a second Geeklog bootstrap;
- **refactor step 3 in progress:** the public state already stays on the canonical `idle → starting → live → stopping → error` model; remaining helper/process details are internal compatibility fields only;
- **refactor step 4 complete:** Record/YouTube dispatch code has been removed from `studio-api.php`; realtime calls sent to the old endpoint now return `realtime_endpoint_moved` instead of maintaining a parallel implementation;
- the automatic YouTube worker explicitly stands down while a Studio Live session owns the ingest;
- recordings and Studio Live runtime state remain site-specific even when Radio media storage is shared across a multisite installation;
- end-to-end browser/server/YouTube runtime testing, long-session drift testing and failure/reconnect testing remain release gates.

### Streaming/encoding constraints

- [x] Do not attempt to stream a continuous DJ mix through a long-running PHP request.
- [x] Evaluate browser-to-stream-server transport separately from Geeklog page delivery.
- [ ] Prefer a provider/adapter boundary so Icecast, Shoutcast or another backend can be swapped without changing Radio programme logic.
- [x] Evaluate practical browser encoding/transport options before implementation, including latency, codec support, TLS, authentication and reconnect behaviour.
- [ ] Document expected latency between DJ console and listeners.
- [x] Provide a fallback path when Web Audio, microphone access or browser encoding is unavailable.
- [x] Treat this as an advanced optional feature; normal scheduled/automatic Radio operation must remain usable without a broadcast backend.

# Phase 15 — Optional dedicated broadcast backend

After the synchronized web-radio mode is stable, evaluate integration with a dedicated streaming backend.

Possible architecture:

```text
Radio schedule / automation
          ↓
broadcast adapter
          ↓
Icecast or compatible streaming infrastructure
```

- [ ] Keep the broadcast server optional.
- [ ] Keep schedule/programme ownership in Radio.
- [ ] Avoid embedding one vendor permanently in Radio.
- [ ] Expose backend health/status through a bounded capability.
- [ ] Keep credentials site-scoped and server-side.
- [ ] Document how live streams differ from synchronized web playback and on-demand replay.

---

# Phase 16 — Statistics and observability

Add only useful, privacy-conscious measurements.

Potential data:

- [x] per-item play/listen events and aggregate top-media counts;
- [x] download count;
- [ ] programme/replay popularity;
- [ ] recent playback errors;
- [ ] schedule execution diagnostics;
- [x] remote-source health and last synchronization summary;
- [x] last remote feed synchronization and imported-count summary.

If Radio persists a per-item popularity counter:

- [x] expose the persisted media page-view counter as normalized `hits`;
- [x] support `order => 'hits-desc'`;
- [x] keep aggregate listening/download statistics separate from Item Info.

Do not collect unnecessary personal listener data merely to provide a dashboard.

---

# Phase 17 — Security and permissions review

Before stable release:

- [x] Audit local audio and cover upload paths: uploaded-file checks, size limits, MIME/signature validation, generated storage names and persistent-storage boundaries.
- [x] Audit controlled download paths: published/ACL gate, download policy, basename storage resolution, single HTTP Range handling and suffix ranges.
- [x] Audit ACL for draft/private media and playlist mutations; delegated schedule users are filtered by item ACL.
- [x] Audit schedule administration permissions in both admin routes and business helpers.
- [x] Audit CSRF protection for administration actions; remote feed preview/import is token-gated before any network request.
- [ ] Audit stored and reflected metadata output.
- [x] Audit current persistent media/cover delivery path traversal protections; stored filenames are generated and resolved through `basename()`.
- [x] Audit remote-source SSRF protections for the current RSS/Atom fetch path: redirects are revalidated and cURL DNS resolution is pinned to the validated public IP.
- [ ] Audit credentials and logs.
- [x] Audit cache vs persistent storage separation for current Radio media/covers.
- [x] Ensure uninstall does not silently remove persistent user audio; uninstall removes Geeklog tables/features but does not delete the external Radio storage directory.

---

# Phase 18 — Multisite and upgrade safety

Follow `multisite-development-principles.md` and `plugin-shared-files-upgrade-safety.md`.

- [ ] Test two sites with different databases/users, URLs and local table mappings while both point to one Shared media directory. The 0.5.1 architecture no longer requires cross-database access; runtime isolation remains required.
- [ ] Confirm site A cannot read/write site B settings or database records while shared audio files and embedded MP3 metadata remain intentionally common.
- [x] Keep current feed synchronization state/database rows site-specific. Radio 0.2.0 stores no remote provider credentials yet; credential isolation must be re-audited if authenticated providers are added.
- [x] Make schema/config migrations repeatable and idempotent.
- [ ] Support staggered multisite upgrades when plugin files are shared.
- [ ] New executable code must tolerate the previous supported persisted schema until the active site completes its upgrade.
- [ ] Do not force every site sharing plugin code to run a database/files migration simultaneously.
- [ ] Log enough context to identify the active site during migrations and remote-source failures; current diagnostics are site-scoped in DB but log messages do not yet include an explicit site namespace.

---

# Release milestones

## 0.1.x — Foundation

Current development version: **0.2.5**.

Focus:

- plugin skeleton;
- installer and schema;
- persistent storage;
- upload;
- local media library;
- basic HTML5 playback;
- initial ACL;
- `plugin.json`;
- automated `dist/` archive.

## Uninstall and language bootstrap audit

- [x] `functions.inc` loads the active Radio language file with French-family and English fallback.
- [x] `functions.inc` loads `autoinstall.php`, making `plugin_autouninstall_radio()` discoverable even when Radio is disabled.
- [x] `autoinstall.php` no longer loads `functions.inc` at file scope, avoiding the circular dependency.
- [x] Geeklog core removes Radio configuration rows, tables, features, group, feeds/comments/topic assignments and plugin registration during auto-uninstall.
- [x] Persistent audio/cover storage is intentionally not deleted by uninstall.

---

## Plugin API callback contract audit

- [x] `plugin_getadminoption_radio()` uses Geeklog's positional `array(label, url, count)` contract.
- [x] `plugin_idtourl_radio()` accepts both legacy one-argument and subtype-aware two-argument calls.
- [x] `plugin_chkVersion_radio()` reports the code version through autoinstall metadata.
- [x] Syndication, Search, What's New, XML Sitemap, Related Items and Item Info signatures were compared with current Geeklog plugin implementations.
- [x] Optional callbacks such as `plugin_getstats_radio()`, `plugin_cclabel_radio()` and `plugin_geticon_radio()` are not required unless Radio implements those features.
- [x] CI regression test verifies the critical Plugin API contracts on PHP 5.6, 8.1 and 8.3 before building `dist`.

---

## Pre-release runtime gate

The executable smoke-test matrix is documented in `docs/PRE_RELEASE_TESTING.md`. CI validation does not replace the Geeklog runtime and multisite tests listed there.

---

## 0.2.x — Pre-release hardening and programmes/playlists

Focus:

- programmes/shows;
- programme items;
- ordering;
- jingles;
- categories;
- reusable media;
- basic rotation rules.

## 0.3.x — Schedule and web radio

Focus:

- day/week schedule;
- recurrence;
- now playing;
- upcoming programmes;
- fallback playlist;
- synchronized web-radio playback.

## 0.4.x — Replay and syndication

Focus:

- replay;
- controlled downloads;
- podcast metadata;
- Geeklog Content Syndication;
- search/sitemap integration where useful.

## 0.5.x — Interoperability

Focus:

- complete Item Info contract;
- collection filters;
- lifecycle events;
- URL resolution;
- shared capability declaration;
- Agent/Hub/Eclipse-facing structured services.

## 0.6.x — External-source prototype

Focus:

- direct remote audio reference;
- podcast feed ingestion prototype;
- live stream reference;
- provenance;
- caching;
- remote-source diagnostics;
- security model.

External-source importing should remain experimental until licensing, SSRF, validation, timeout, cache and multisite behaviour are all covered.

## 0.7.x+ — Broadcast, Studio master output and advanced integrations

Candidates:

- Icecast-compatible broadcast adapter;
- advanced rotation;
- richer podcast feeds;
- MediaGallery optional interoperability;
- richer Hub relations;
- listening/download statistics;
- remote provider adapters.

## Future evolution proposal — resilient YouTube Live broadcast lifecycle (design only)

**Status: proposed / not implemented.** This section documents an optional post-stabilization evolution. It does not change current Studio, manual or scheduled YouTube output behavior, and must not delay the current FFmpeg shutdown hardening.

### Product experience and lifecycle

- [ ] Define an explicit state machine: `offline → preparing/connecting → pre-live → on-air ↔ paused → outro → stopping → stopped`, plus `reconnecting`, `failover` and `error` paths. Clearly separate *encoder connected*, *YouTube ingest receiving media* and *YouTube broadcast publicly live*; YouTube's start/stop and auto-stop options may affect these independently.
- [ ] **Open live / pre-live:** establish the RTMPS connection, then deliberately start the public event when appropriate. Display a branded "Starting soon" scene, optional quiet background music, programme title and optional countdown to let viewers arrive without starting the Studio playlist.
- [ ] **Start programme:** switch to the Studio master mix on operator command, maintaining the existing broadcast/event and one persistent encoder where technically feasible.
- [ ] **Pause / intermission:** immediately replace the programme with a continuous, valid server-generated audio/video holding scene, preserving the connection. Support planned breaks and emergency interruptions, optional countdown and background music. Never represent simply starving FFmpeg of audio as a reliable pause.
- [ ] **Resume:** return to the current Studio mix with a controlled fade/crossfade, avoiding duplicate playback and unexpected jingles.
- [ ] **End show / outro:** show a configurable thanks/subscribe/next-appointment screen with optional audio and a bounded, configurable duration; only then gracefully stop the encoder and verify its termination.
- [ ] **Emergency stop:** provide a separate immediate stop command that bypasses the outro, without falsely reporting success when FFmpeg/its descendants remain alive.
- [ ] Allow reusable image/video scene templates, translated labels and optional programme-specific overrides configured in `admin/plugins/radio/youtube.php`; expose the minimal controls in `studio.php` without overloading the current live transport controls.
- [ ] Apply the same lifecycle/holding/outro concept to **Scheduled YouTube output** and manual server-side output when useful, with explicit policy for event boundaries, recurrence, overrides and next scheduled programme.

### Browser loss and continuity

- [ ] Move ownership of the YouTube ingest and fallback source to a **server-side broadcast controller**. The browser remains the preferred live audio source and operator console, not the sole continuity source. Preserve one encoder/RTMPS ingest whenever supported, but document restart/recovery behavior when it cannot be maintained.
- [ ] Add a bounded heartbeat/watchdog for the Studio source. Distinguish intentional silence from missing transport; do not depend only on `pagehide` or an unreliable unload request.
- [ ] On browser/network loss, transition to a safe server-owned intermission scene first; after a configurable grace period either remain in intermission or begin server-side programme playback according to operator policy.
- [ ] Use the existing Geeklog programme as a **possible fallback playlist**: server playback must resolve local/authorized playable media and respect programme sequencing, jingles, durations and transition rules. It must not assume that the original programme timeline matches a DJ's actual manual ordering.
- [ ] Synchronize a lightweight, authoritative programme cursor (active item, offset, order/version, transport state, transitions, last acknowledged command and update timestamp) between Studio and server; validate that cursor is still usable before fallback starts.
- [ ] On Studio reconnection, show whether server fallback/intermission is active and require a clear operator-controlled reclaim/handoff, with a fade rather than silently starting two sources.
- [ ] Consider what to do when the browser closes while pre-live, paused, during the outro or while stopped; each transition needs a deterministic timeout and operator-visible status.
- [ ] A server-produced holding feed requires encoding resources even during a pause; measure and budget shared-hosting CPU, IO and process quotas rather than promising reduced load.

### Architecture and safety gates

- [ ] Prototype a persistent audio/video source switch or mixer in front of one encoder; compare relay/FIFO, persistent FFmpeg filtergraph and dedicated service designs, emphasizing smooth transitions, backpressure, failure detection and minimal hosting requirements.
- [ ] Explicitly assess limits of shared hosting: long-lived processes, binaries, process-group signaling, cron interval, shell restrictions and blocked network egress. Use feature detection and disable unsupported capabilities with honest diagnostics.
- [ ] Preserve Studio/manual/scheduled **single-ingest ownership** across starts, stops, timeouts, overlapping cron invocations, PID reuse, crashes and rapid restarts; never launch two publishers using the same stream key.
- [ ] Separate encoder health, actual media flow and YouTube platform status; do not infer a successful public broadcast or clean shutdown from a local JSON state flag alone.
- [ ] Keep tracked PID/PGID, process identity, cleanup confirmation, bounded signal escalation, stop-result errors and last verified shutdown timestamps. Never kill unrelated or reused PIDs.
- [ ] Build operator controls with ACL + CSRF, explicit action audit, bounded uploads, protected media paths and redacted stream secrets. State changes must be idempotent; do not expose controller commands to unauthenticated clients.
- [ ] Avoid indefinite retries, unbounded buffers or sleep loops in web PHP requests. Implement cleanup/watchdog with bounded scheduled or supervisor-driven work when available.
- [ ] Provide a read-only operational health capability for Monitor and clear site-wide admin alerts for orphaned encoders and failed stops, without creating a dependency on Monitor.
- [ ] Validate transitions end-to-end (pre-live/live/pause/resume/outro/stop), browser close/crash/reconnect, 5–60-minute pauses, YouTube ingest interruption/auto-stop behavior, missing FFmpeg capabilities, scheduled takeovers, multi-site isolation and process leak/CPU tests on shared hosting and VPS.
- [ ] Keep this work in a future dedicated development branch after current Radio stabilization; implement incrementally with a demo/prototype and no breaking changes to currently published releases.

---

## 1.0.0 — Stable

Required before 1.0:

- reliable local media library;
- programmes and playlists;
- schedule;
- synchronized web-radio mode;
- replay and controlled downloads;
- clear podcast/syndication behaviour;
- ACL and security audit;
- multisite-safe storage;
- safe upgrade path;
- shared content interoperability;
- capability exposure suitable for Agent, Hub and Eclipse;
- automated installable distribution archive;
- tested supported Geeklog/PHP compatibility matrix;
- documentation for administrators and developers.

External sources or a dedicated broadcast backend do not need to block 1.0 unless they have become part of the documented stable scope.

---

# Memorandum references

Radio development should regularly re-check the current versions of:

- `plugin-content-interoperability-contract.md`;
- `plugin-capability-contract.md`;
- `llm-agent-content-representation-contract.md`;
- `geeklog-chatgpt-connector.md`;
- `agent-hub-connector-architecture.md`;
- `multisite-development-principles.md`;
- `plugin-persistent-storage-guide.md`;
- `plugin-shared-files-upgrade-safety.md`;
- `plugin-metadata-manifest.md`;
- `geeklog-external-data-integration-vision-2030.md`.

The roadmap should evolve with those shared contracts rather than duplicate them permanently inside Radio.
