# Radio

Modern audio and web radio plugin for Geeklog with media management, playlists, scheduled programming, replay, podcasts, downloads and synchronized web-radio playback.

## Media availability model

Radio 0.4.0 separates publication from the ways a media item can be used:

- **Published** — the media is active and may be used by Radio.
- **On demand** — the media may be exposed individually to visitors in the public catalogue, item pages, podcast/feed collections and public playlists.
- **Broadcast** — the media may be used in programmes and synchronized scheduled playback.
- **Automatic rotation** — the media may be selected by the automatic fallback rotation when no programme is scheduled.

These availability flags are independent. A published media item can therefore be reserved for a scheduled programme by enabling Broadcast while disabling both On demand and Automatic rotation.

Existing installations keep the availability flags enabled by default during upgrades so content is not silently removed from public, scheduled or automatic-rotation use.

## Media classification

Radio 0.4.0 adds three complementary organization layers without overloading the technical media type:

- **Category** — the primary editorial classification.
- **Collection** — a deliberate grouping such as a special programme, station package or thematic set.
- **Tags** — multiple free keywords for transversal characteristics such as genre, mood, language, season or usage.

The administration library can combine text search with type, category, collection, tag, status, source and availability filters. Batch uploads apply category, collection and tags to the whole batch.

Programme editing reuses the same classification approach: search and filter broadcast-eligible media, then append an item with a single **+** action before reordering the programme playlist if needed.

Automatic rotation stays protected during its active cycle, but administrators can explicitly rebuild it immediately from the Rotation page when they intentionally want current media or configuration changes to take effect at once.

## Compatibility

Current transition baseline:

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through PHP 8.3 syntax/runtime checks in CI

See `ROADMAP.md` and `docs/PRE_RELEASE_TESTING.md` for the current development and release checklist.
