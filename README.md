# Radio

Modern audio and web radio plugin for Geeklog with media management, playlists, scheduled programming, replay, podcasts, downloads and synchronized web-radio playback.

## Media availability model

Radio 0.5.1 separates publication from the ways a media item can be used:

- **Published** — the media is active and may be used by Radio.
- **On demand** — the media may be exposed individually to visitors in the public catalogue, item pages, podcast/feed collections and public playlists.
- **Broadcast** — the media may be used in programmes and synchronized scheduled playback.
- **Automatic rotation** — the media may be selected by the automatic fallback rotation when no programme is scheduled.

These availability flags are independent. A published media item can therefore be reserved for a scheduled programme by enabling Broadcast while disabling both On demand and Automatic rotation.

Existing installations keep the availability flags enabled by default during upgrades so content is not silently removed from public, scheduled or automatic-rotation use.

## Media classification

Radio 0.5.1 keeps three complementary organization layers without overloading the technical media type:

- **Category** — the primary editorial classification.
- **Collection** — a deliberate grouping such as a special programme, station package or thematic set.
- **Tags** — multiple free keywords for transversal characteristics such as genre, mood, language, season or usage.

The administration library can combine text search with type, category, collection, tag, status, source and availability filters. Batch uploads apply category, collection and tags to the whole batch.

Programme administration is deliberately split into two focused screens. **Programmes** manages only programme metadata, permissions and publication state. **Studio** is the single workspace for listening, searching the library, adding media with **+** or **Play next**, reordering with compact ↑/↓ controls and removing items with **−**. The Studio queue is also the chapter navigator, so the playlist is not displayed twice. Queue changes are persisted through one CSRF-protected Studio API without reloading the current audio, and Studio listening is excluded from public audience statistics.

Automatic rotation stays protected during its active cycle, but administrators can explicitly rebuild it immediately from the Rotation page when they intentionally want current media or configuration changes to take effect at once.

Each newly created rotation cycle receives a fresh shuffled order, then remains frozen for synchronization. Crossfade uses the full configured duration for music-to-music transitions and a shorter half-duration overlap for jingle-to-music transitions.

Radio also uses adaptive N+1/N+2 buffering in its live players and programme Studio. The next media item is prepared immediately, the following item is prepared once enough of N+1 is buffered, and a crossfade is delayed when the next track is not sufficiently ready.

## Multisite shared media

Radio 0.5.1 keeps the default installation fully local and adds one opt-in **Shared media** mode.

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
