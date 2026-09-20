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

# Phase 0 — Architecture and plugin skeleton

- [ ] Define the canonical plugin id as `radio`.
- [ ] Create standard Geeklog plugin structure, installer, uninstall and upgrade paths.
- [ ] Add `plugin.json` following the memorandum metadata manifest.
- [ ] Add repository-root `README.md`, `ROADMAP.md` and changelog/release notes convention.
- [ ] Add automated packaging to `dist/radio-x.y.z.zip`.
- [ ] Ensure generated archives contain no dot-prefixed files that can break Geeklog plugin upload/install workflows.
- [ ] Define database tables through `$_TABLES`; never hard-code table prefixes.
- [ ] Define permissions for administration, upload, edit, schedule, publish, download management and playback of restricted content.
- [ ] Define clean separation between persistent audio files, generated/cache data and plugin executable files.

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

- [ ] Upload audio files from the administration interface.
- [ ] Support safe drag-and-drop upload.
- [ ] Validate extension, MIME type, actual media type and configured size limits.
- [ ] Generate filesystem-safe storage names independently from the uploaded filename.
- [ ] Keep the original human filename and metadata separately when useful.
- [ ] Extract available audio metadata such as title, artist, album, duration and embedded artwork.
- [ ] Let administrators correct or override extracted metadata.
- [ ] Support at least the formats that can be played reliably by current browsers; document the accepted format matrix.
- [ ] Store title, author/artist, description, category, tags, duration, file size, publication state and dates.
- [ ] Support a media subtype such as:
  - music;
  - podcast;
  - interview;
  - show;
  - chronicle;
  - jingle;
  - announcement;
  - promo.
- [ ] Support an optional cover/image.
- [ ] Support draft, published, disabled and archived states.
- [ ] Support per-item download permission.

## Persistent storage

Follow `plugin-persistent-storage-guide.md` and `multisite-development-principles.md`.

- [ ] Derive persistent storage from the current site's `$_CONF['path_data']`.
- [ ] Keep uploaded media outside disposable plugin/cache directories.
- [ ] Prefer storage outside the public web root where practical.
- [ ] Provide controlled file delivery when ACL, logging or download rules require it.
- [ ] Make storage initialization failures explicit.
- [ ] Ensure cache cleanup can never delete persistent audio.
- [ ] Keep all migration procedures non-destructive and idempotent.
- [ ] Never scan or modify sibling multisite storage during normal requests.

---

# Phase 2 — Public audio player

- [ ] Provide an HTML5-based player.
- [x] Display title, author/artist, programme and cover where available.
- [ ] Expose play/pause, seek and volume controls where the playback mode permits them.
- [ ] Provide a controlled download action when enabled.
- [ ] Provide canonical public pages for addressable media.
- [ ] Support responsive presentation.
- [ ] Provide accessible controls, labels and keyboard operation.
- [ ] Define reusable rendering helpers/autotags only after the basic content model is stable.

Radio must not reproduce the legacy architecture where PHP maintains a long-running request and manually streams an endless MP3 byte sequence.

Normal HTTP media delivery and browser range requests should be preferred.

---

# Phase 3 — Programmes, shows and playlists

- [ ] Add a `programme` / `show` object independent from the underlying media files.
- [ ] Allow a programme to contain ordered programme items.
- [ ] Reuse the same audio item in multiple programmes without duplicating the file.
- [ ] Support programme metadata:
  - title;
  - description;
  - image;
  - author/host;
  - category;
  - publication state.
- [ ] Provide an intuitive item-ordering interface.
- [ ] Allow jingles, announcements and promotional items in the same sequence as music and spoken content.
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

- [ ] Provide day and week administration views.
- [ ] Schedule a programme once or recurrently.
- [ ] Support:
  - one-time broadcasts;
  - daily recurrence;
  - selected weekdays;
  - weekly recurrence;
  - active date ranges.
- [ ] Detect schedule conflicts.
- [ ] Expose the currently scheduled item.
- [ ] Expose upcoming programmes.
- [ ] Define deterministic behaviour for gaps in the schedule.
- [ ] Support automatic fallback playlists when no explicit programme is scheduled.
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

- [ ] Resolve `now playing` from schedule and rotation rules.
- [ ] Calculate playback offset for visitors joining an already-started item.
- [ ] Return enough structured data for the browser player to stay synchronized.
- [ ] Recover gracefully after pause, browser sleep or connectivity loss.
- [ ] Define behaviour at programme/item transitions.
- [ ] Avoid long-running PHP streaming loops.

This mode must work on a standard Geeklog web server.

---

# Phase 6 — Replay and downloads

- [x] Make completed scheduled programmes available as replay without duplicating media files.
- [x] Provide immediate time-limited replay using `default_replay_days`; delayed/per-programme/permanent policies remain for a later iteration.
- [x] Keep replay as a reference to existing media/programme objects rather than duplicate files.
- [ ] Support per-item and per-programme download policy.
- [ ] Enforce Geeklog permissions before controlled downloads.
- [ ] Optionally record download counts.
- [ ] Keep listening statistics separate from permissions and editorial state.

---

# Phase 7 — Podcast support and content syndication

- [x] Treat podcast episodes as first-class Radio content, not as a separate storage silo.
- [x] Support series/show, season, episode and author metadata where appropriate.
- [x] Expose podcast-friendly descriptions, dates, duration, artwork and canonical URLs.
- [x] Implement Geeklog native Content Syndication callbacks: `plugin_getfeednames_radio()`, `plugin_getfeedcontent_radio()` and `plugin_feedupdatecheck_radio()`.
- [x] Provide a dedicated RSS 2.0 podcast feed with audio `enclosure` while keeping Radio media as the source of truth.
- [ ] Keep feed generation permission-aware.
- [ ] Preserve canonical source attribution and enclosure/download rules.

---

# Phase 8 — External audio sources — research and prototype

External sources are intentionally separated from the first local-library implementation.

The plugin should distinguish **remote metadata**, **remote playback**, **cached remote data** and **local import**.

## Candidate source types

Study support for:

- [x] direct remote audio URLs as browser-side remote references;
- [ ] podcast RSS/Atom feeds with audio enclosures;
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

## Requirements before enabling arbitrary external sources

Follow the principles in `geeklog-external-data-integration-vision-2030.md`.

- [ ] Define an allowlist/provider model rather than unrestricted editor-controlled server-side URL fetching.
- [x] Reject localhost, literal private/reserved IPs and hostnames resolving to private/reserved IPv4 before accepting remote references; no server-side remote fetching is performed in this prototype.
- [ ] Validate redirects.
- [ ] Apply connection and total timeouts.
- [ ] Limit response and imported file sizes.
- [ ] Validate MIME and actual audio type.
- [ ] Sanitize remote metadata.
- [ ] Define cache TTL and stale-cache behaviour.
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
- [ ] Provide clear administrator diagnostics for remote-source failures.

Provider-specific integrations should remain replaceable and must not become hard-coded Core requirements.

---

# Phase 9 — Geeklog content interoperability

Follow `plugin-content-interoperability-contract.md`.

## Item Info

- [ ] Implement `plugin_getiteminfo_radio()`.
- [ ] Expose stable normalized fields where meaningful:
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
- [ ] Support `id='*'` collection retrieval.
- [ ] Support common bounded options:
  - `since`;
  - `limit`;
  - `order`.
- [ ] Add subtype/category filtering where it solves actual consumer needs.
- [ ] Keep permission checks and canonical URL construction inside Radio.

## Lifecycle

- [ ] Emit `PLG_itemSaved()` after successful creation or modification of addressable content.
- [ ] Emit `PLG_itemDeleted()` after successful deletion.
- [ ] Cover normal administration, imports and later service/API mutation paths.
- [ ] Preserve subtype information when the active Geeklog API provides it.

## URL resolution

- [ ] Implement `plugin_idtourl_radio()` where supported.
- [ ] Keep `url` available through Item Info as the compatibility fallback.

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
- [ ] Use bounded Geeklog services for specialized data that does not naturally fit Item Info.
- [ ] Do not create Agent-specific, Hub-specific or Eclipse-specific parallel APIs.
- [ ] Never treat a declared capability as write authorization.

---

# Phase 11 — Agent and machine-readable representation

Follow `llm-agent-content-representation-contract.md`.

- [ ] Make Radio content consumable without scraping theme HTML.
- [ ] Keep stable identity as plugin + type + id + optional subtype.
- [ ] Expose clean content/metadata representations for episodes, programmes and schedule entries where useful.
- [ ] Preserve canonical human URLs.
- [ ] Expose language, licensing and attribution fields when known.
- [ ] Let Agent answer provider-neutral questions such as:
  - what is playing now?
  - what is next?
  - what are the latest podcast episodes?
  - what programmes are scheduled tomorrow?
  - what are the most-listened-to addressable items, if Radio maintains a real counter?
- [ ] Keep discovery, resources, capabilities and authorized actions separate.
- [ ] Do not expose private/restricted Radio content through machine-facing resources.

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
- [ ] Do not make Radio depend on Eclipse.
- [ ] Do not let Eclipse query Radio tables directly.
- [ ] Consider exposing:
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
- [ ] Keep Radio as the owner of Radio content and business rules.

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

- [ ] per-item page/listen counter when it can be measured consistently;
- [ ] download count;
- [ ] programme/replay popularity;
- [ ] recent playback errors;
- [ ] schedule execution diagnostics;
- [ ] remote-source health;
- [ ] last successful remote metadata refresh/import.

If Radio persists a per-item popularity counter:

- [ ] expose it as normalized `hits`;
- [ ] support `order => 'hits-desc'`;
- [ ] keep aggregate statistics separate from Item Info.

Do not collect unnecessary personal listener data merely to provide a dashboard.

---

# Phase 17 — Security and permissions review

Before stable release:

- [ ] Audit all upload paths.
- [ ] Audit controlled download paths.
- [ ] Audit ACL for draft/private media.
- [ ] Audit schedule administration permissions.
- [ ] Audit CSRF protection for administration actions.
- [ ] Audit stored and reflected metadata output.
- [ ] Audit path traversal protections.
- [ ] Audit remote-source SSRF protections if external sources are enabled.
- [ ] Audit credentials and logs.
- [ ] Audit cache vs persistent storage separation.
- [ ] Ensure uninstall never silently removes user audio without an explicit administrator choice.

---

# Phase 18 — Multisite and upgrade safety

Follow `multisite-development-principles.md` and `plugin-shared-files-upgrade-safety.md`.

- [ ] Test two sites with different `path_data`, URLs and table mappings.
- [ ] Confirm site A cannot read/write site B Radio files, settings or database records.
- [ ] Keep remote provider credentials and caches site-specific.
- [ ] Make migrations repeatable.
- [ ] Support staggered multisite upgrades when plugin files are shared.
- [ ] New executable code must tolerate the previous supported persisted schema until the active site completes its upgrade.
- [ ] Do not force every site sharing plugin code to run a database/files migration simultaneously.
- [ ] Log enough context to identify the active site during migrations and remote-source failures.

---

# Release milestones

## 0.1.x — Foundation

Current development version: **0.1.6**.

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

## 0.2.x — Programmes and playlists

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

## 0.7.x+ — Broadcast and advanced integrations

Candidates:

- Icecast-compatible broadcast adapter;
- advanced rotation;
- richer podcast feeds;
- MediaGallery optional interoperability;
- richer Hub relations;
- listening/download statistics;
- remote provider adapters.

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
