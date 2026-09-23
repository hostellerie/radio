# Radio

Modern audio and web radio plugin for Geeklog with media management, playlists, scheduled programming, replay, podcasts, downloads and synchronized web-radio playback.

## Media availability model

Radio 0.3.2 separates publication from the ways a media item can be used:

- **Published** — the media is active and may be used by Radio.
- **On demand** — the media may be exposed individually to visitors in the public catalogue, item pages, podcast/feed collections and public playlists.
- **Broadcast** — the media may be used in programmes and synchronized scheduled playback.
- **Automatic rotation** — the media may be selected by the automatic fallback rotation when no programme is scheduled.

These availability flags are independent. A published media item can therefore be reserved for a scheduled programme by enabling Broadcast while disabling both On demand and Automatic rotation.

Existing installations upgraded from 0.2.5 keep both availability flags enabled so the upgrade does not silently remove existing content from public or broadcast use.

## Compatibility

Current transition baseline:

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through PHP 8.3 syntax/runtime checks in CI

See `ROADMAP.md` and `docs/PRE_RELEASE_TESTING.md` for the current development and release checklist.
