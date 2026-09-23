# Radio 0.3.1

Radio 0.3.1 is a maintenance and playback-improvement release for the Geeklog Radio plugin.

## Highlights

- Adds configurable audio transition modes: hard cut, gapless and smart crossfade.
- Keeps the active automatic-rotation cycle stable so uploading new media does not interrupt playback; newly published broadcast media joins at the next rotation cycle.
- Adds a configurable music-to-music crossfade duration.
- Keeps jingles, announcements and promos on hard transitions so their opening is not clipped.
- Preloads the next media item in the public player, dynamic block and persistent detached player.
- Adds persistent detached listening across normal Geeklog page navigation.
- Improves short-jingle starts after natural track transitions.
- Cleans auto-generated imported track titles while preserving manually edited editorial titles.
- Improves responsive rendering of the dynamic Radio block in narrow theme columns.
- Validates remote audio sources before saving them.

## Upgrade from 0.3.0

The 0.3.0 → 0.3.1 update does not alter Radio database tables.

The upgrade reconciles the existing Geeklog Radio configuration and adds:

- `transition_mode` — default: `gapless`
- `crossfade_seconds` — default: `2`

After the upgrade, both settings are available under Radio → Configuration → Main Settings.

## Compatibility

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through PHP 8.3 syntax/runtime checks in CI
