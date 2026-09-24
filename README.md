# Radio

Modern audio and web radio plugin for Geeklog with media management, playlists, scheduled programming, replay, podcasts, downloads and synchronized web-radio playback.

## Media availability model

Radio 0.5.0 separates publication from the ways a media item can be used:

- **Published** — the media is active and may be used by Radio.
- **On demand** — the media may be exposed individually to visitors in the public catalogue, item pages, podcast/feed collections and public playlists.
- **Broadcast** — the media may be used in programmes and synchronized scheduled playback.
- **Automatic rotation** — the media may be selected by the automatic fallback rotation when no programme is scheduled.

These availability flags are independent. A published media item can therefore be reserved for a scheduled programme by enabling Broadcast while disabling both On demand and Automatic rotation.

Existing installations keep the availability flags enabled by default during upgrades so content is not silently removed from public, scheduled or automatic-rotation use.

## Media classification

Radio 0.5.0 keeps three complementary organization layers without overloading the technical media type:

- **Category** — the primary editorial classification.
- **Collection** — a deliberate grouping such as a special programme, station package or thematic set.
- **Tags** — multiple free keywords for transversal characteristics such as genre, mood, language, season or usage.

The administration library can combine text search with type, category, collection, tag, status, source and availability filters. Batch uploads apply category, collection and tags to the whole batch.

Programme administration is deliberately split into two focused screens. **Programmes** manages only programme metadata, permissions and publication state. **Studio** is the single workspace for listening, searching the library, adding media with **+** or **Play next**, reordering with compact ↑/↓ controls and removing items with **−**. The Studio queue is also the chapter navigator, so the playlist is not displayed twice. Queue changes are persisted through one CSRF-protected Studio API without reloading the current audio, and Studio listening is excluded from public audience statistics.

Automatic rotation stays protected during its active cycle, but administrators can explicitly rebuild it immediately from the Rotation page when they intentionally want current media or configuration changes to take effect at once.

Each newly created rotation cycle receives a fresh shuffled order, then remains frozen for synchronization. Crossfade uses the full configured duration for music-to-music transitions and a shorter half-duration overlap for jingle-to-music transitions.

Radio also uses adaptive N+1/N+2 buffering in its live players and programme Studio. The next media item is prepared immediately, the following item is prepared once enough of N+1 is buffered, and a crossfade is delayed when the next track is not sufficiently ready.

## Multisite media libraries

Radio 0.5.0 keeps the default installation fully local and adds two opt-in multisite modes:

- **Local** — the default. Each Geeklog site keeps its own catalogue and its own Radio storage.
- **Shared storage** — sites keep independent catalogue rows while audio files are stored in one common directory.
- **Shared library** — sites use one common media catalogue and common storage while programmes, schedules, statistics and configuration remain local to each Geeklog site.

Shared-library mode uses a local `radio_site_media` table for site-specific media state. Publication, on-demand use, broadcast use, automatic rotation, download permission and Geeklog ACL values can therefore differ by site without duplicating the media catalogue or audio files. A media item disabled on one site remains available to other sites.

The shared catalogue table is configured explicitly with **Shared media catalogue table** and is validated as a database table identifier. The shared storage directory is configured with **Shared media storage path**. Sites that do not enable either shared mode continue to use the existing local table and storage path with no behavioural change.

When shared-library mode is active, deleting a media item from one site disables it for that site instead of deleting the shared catalogue row or physical audio file. Global media metadata such as title, author, duration, category, collection and tags remains attached to the shared catalogue.

## Compatibility

Current transition baseline:

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through PHP 8.3 syntax/runtime checks in CI

See `ROADMAP.md` and `docs/PRE_RELEASE_TESTING.md` for the current development and release checklist.
