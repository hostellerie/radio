# Radio

Modern audio and web radio plugin for Geeklog with media management, playlists, scheduled programming, replay, podcasts, downloads and synchronized web-radio playback.

## Media availability model

Radio 0.6.2 (development) separates publication from the ways a media item can be used:

- **Published** — the media is active and may be used by Radio.
- **On demand** — the media may be exposed individually to visitors in the public catalogue, item pages, podcast/feed collections and public playlists.
- **Broadcast** — the media may be used in programmes and synchronized scheduled playback.
- **Automatic rotation** — the media may be selected by the automatic fallback rotation when no programme is scheduled.

These availability flags are independent. A published media item can therefore be reserved for a scheduled programme by enabling Broadcast while disabling both On demand and Automatic rotation.

Existing installations keep the availability flags enabled by default during upgrades so content is not silently removed from public, scheduled or automatic-rotation use.

## Media classification

Radio 0.6.2 (development) keeps three complementary organization layers without overloading the technical media type:

- **Category** — the primary editorial classification.
- **Collection** — a deliberate grouping such as a special programme, station package or thematic set.
- **Tags** — multiple free keywords for transversal characteristics such as genre, mood, language, season or usage.

The administration library can combine text search with type, category, collection, tag, status, source and availability filters. Batch uploads apply category, collection and tags to the whole batch.

Programme administration is deliberately split into two focused screens. **Programmes** manages only programme metadata, permissions and publication state. **Studio** is the single workspace for listening, searching the library, adding media with **+** or **Play next**, reordering with compact ↑/↓ controls and removing items with **−**. The Studio queue is also the chapter navigator, so the playlist is not displayed twice. Queue changes are persisted through one CSRF-protected Studio API without reloading the current audio, and Studio listening is excluded from public audience statistics.

Automatic rotation stays protected during its active cycle, but administrators can explicitly rebuild it immediately from the Rotation page when they intentionally want current media or configuration changes to take effect at once.

Each newly created rotation cycle receives a fresh shuffled order, then remains frozen for synchronization. Crossfade uses the full configured duration for music-to-music transitions and a shorter half-duration overlap for jingle-to-music transitions.

Radio also uses adaptive N+1/N+2 buffering in its live players and programme Studio. The next media item is prepared immediately, the following item is prepared once enough of N+1 is buffered, and a crossfade is delayed when the next track is not sufficiently ready.

## Multisite shared media

Radio 0.6.2 (development) keeps the default installation fully local and adds one opt-in **Shared media** mode.

- **Local** — the default. Each Geeklog site keeps its own catalogue and its own Radio storage.
- **Shared media** — every site keeps its own Radio database rows, permissions, publication state, programmes, schedules and statistics, while all sites point to the same audio directory.

No shared MySQL user or cross-database access is required.

For local MP3 files in Shared media mode, Radio uses embedded ID3v2 metadata as the common descriptive layer. Title, artist, collection, category, tags, description and Radio classification are written into the MP3 itself. When another site sees the same shared file, it can import or refresh these values into its own local catalogue.

Radio compares the shared file modification time with a local metadata cache marker instead of parsing every MP3 on every request. Administrators can also force **Sync shared media**, **Sync from file** or **Write metadata to file** from the Radio administration.

Before a site writes shared MP3 metadata, Radio re-reads the current ID3 tag and applies only the fields actually changed by that site. This prevents an older local cache from silently overwriting newer corrections made by another site.

Removing a shared local media item from one site only hides it from that site's Radio catalogue and programmes. The common audio file is preserved for the other sites.

Non-MP3 shared files remain usable in Shared media mode, but embedded cross-site metadata synchronization is currently implemented for MP3/ID3 only.

## Compatibility

Current transition baseline:

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through PHP 8.3 syntax/runtime checks in CI

See `ROADMAP.md` and `docs/PRE_RELEASE_TESTING.md` for the current development and release checklist.

## Efficient local media delivery

Radio can keep media files outside the public web root while letting the web server deliver
the audio after Geeklog has checked access.

Configuration `media_delivery_mode` supports:

- `auto` (default): uses `X-Sendfile` only when Apache support can be detected safely; otherwise PHP is used.
- `php`: always stream through PHP.
- `xsendfile`: emit `X-Sendfile` after Radio permission checks. The web server must be configured to allow the Radio storage path.
- `xaccel`: emit `X-Accel-Redirect`. Configure `x_accel_internal_prefix` and map that internal Nginx location to the Radio storage directory.

Example Nginx mapping:

```nginx
location /_radio_media/ {
    internal;
    alias /absolute/path/to/radio/media/;
}
```

Then set `x_accel_internal_prefix` to `/_radio_media/`.

When server offload is enabled, PHP performs authentication/authorization and immediately
hands the file transfer to Apache/Nginx instead of staying busy for the complete audio
request. The PHP fallback uses larger chunks, and statistics retention cleanup is no
longer run for every listener event.


## YouTube Live — server broadcasts and experimental Studio mode

**Status:** development branch `develop-1.0`, plugin version **0.6.2** (`RADIO_RELEASE_STATUS=development`). Do not assume that changes in the branch have been included in an installed `dist` ZIP. Runtime behaviour depends on the installed build.

Radio currently provides **two separate YouTube output paths**:

| Capability | Manual/scheduled server YouTube | Browser-fed Studio YouTube |
| --- | --- | --- |
| Audio source | Local programme files read on the server | Post-FX browser master mix uploaded in chunks |
| FFmpeg encoder | Detached server encoder controlled by CLI worker/cron | Detached encoder fed by a browser-to-server HTTP relay |
| Browser may disconnect | Yes, server programme continues | No guaranteed continuity |
| Programme/track graphics | FFmpeg `subtitles`/ASS timeline and artwork | `drawtext` where available; ASS timeline fallback when `subtitles` is available |
| Live DJ reordering reflected in titles | Not applicable to the fixed server timeline | **Not yet reliably synchronized** with manually reordered tracks |
| Seamless switching between the two paths | **Not implemented** | **Not implemented** |

### Server YouTube operation

From **Radio → YouTube Live** (`admin/plugins/radio/youtube.php`), authorized administrators can configure an RTMP/RTMPS destination, select manual programmes and eligible scheduled slots, and control a manual live. Programmes for server YouTube currently require playable **local media files**.

The CLI worker (`bin/youtube-live.php`) reconciles the manual request or selected schedule with FFmpeg. It uses a per-site control lock shared with manual Start/Stop and Studio server-live controls. A manual programme has a finite FFmpeg output duration; its completion and disappearance of FFmpeg processes **were confirmed by the operator on o2switch in October 2026**. This is a targeted live result, not comprehensive regression testing.

Manual stop waits briefly for a busy worker lock and confirms process termination before clearing the request. Starting a new manual programme is rejected while another server live, a pending manual request or a Studio live is active. Stopping a scheduled occurrence is separate from stopping a manual programme.

The global administration health banner is hidden in a **healthy idle state**. It remains visible for live output, unconfirmed process state, unexpected FFmpeg processes and unavailable process checks. Messages are supplied by the Radio language files, including English and French France, rather than hard-coded in the banner.

### Studio YouTube operation

The Studio provides an interactive browser DJ mixer with recording and browser-fed YouTube Live, which currently relies on frequent authenticated HTTP uploads. This experimental path can still be interrupted by browser/network loss or host security filtering. A reliable server fallback, a persistent audio switch, and an uninterrupted YouTube event during takeover **are not yet implemented**.

Studio also exposes **Server YouTube** and **Stop server live** controls for requesting a manual server programme through the existing cron worker, with server status polling approximately every 10 seconds. Those controls do **not** yet route the DJ mix into the server programme encoder. A server start may remain pending until the next cron run.

### FFmpeg and operational notes

- FFmpeg requires H.264/AAC encoding and RTMP(S) output support. For the manual programme video templates, `subtitles` (libass), `overlay` and `showwaves` support provide the full visual treatment.
- On the tested o2switch FFmpeg build, `overlay` and `showwaves` are available but `drawtext` is missing. Studio has an ASS-based rendering fallback; fixed programme timelines do not necessarily match live DJ overrides.
- Monitor the process identity, the stream state and the YouTube ingestion separately. An FFmpeg process being alive does not prove that YouTube is broadcasting publicly.
- Do not disable hosting WAF protections permanently to accommodate Studio chunk uploads; use a narrow hosting exception after validation.
- A stream key is a credential and may be exposed in process arguments; never include it in diagnostics.

See [YouTube Live operating notes](docs/YOUTUBE-LIVE.md), [development roadmap](ROADMAP.md), and [pre-release testing](docs/PRE_RELEASE_TESTING.md). Some older operating and test documents retain historical version references and should be reconciled before a stable release.

## Release and validation status

**Confirmed on the operator's o2switch installation:** end of a manual programme automatically stops its stream and FFmpeg processes disappear.

**Code present but not fully validated end to end:** first-click manual stop, cron/admin concurrency, prevention of overlapping server/Studio encoders, Studio CSRF and WAF handling, and the localized/idle-hidden global banner.

**Pending before stable release:** run PHP syntax and packaging checks against the built archive, test Geeklog 2.1.1 / 2.2.2 and supported PHP versions, validate both manual and scheduled broadcasts, and perform a short Studio live regression. No claim is made here that a new distribution archive has been generated or deployed.
