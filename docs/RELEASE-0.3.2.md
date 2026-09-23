# Radio 0.3.2

Radio 0.3.2 separates scheduled programme playback from automatic rotation.

## Highlights

- Adds an independent `automatic_rotation` availability flag to every media item.
- Keeps `broadcast` for programme and scheduled playback.
- Allows media to be prepared for a special programme without exposing it on demand or placing it in automatic rotation.
- Existing media keep automatic rotation enabled during upgrade, preserving current behaviour.
- The active rotation cycle remains protected: changing the new flag takes effect at the next cycle, while deleting a media item or returning it to draft still removes it from the active cycle immediately.
- Shows Automatic rotation in upload, remote-media and edit forms and in the media-library availability badges.
- Adds an explicit automatic-rotation exclusion reason to rotation diagnostics.

## Special programme workflow

For media that must only be heard during a scheduled programme:

- Published: yes
- On demand: no
- Broadcast: yes
- Automatic rotation: no

The item remains selectable in a programme but is excluded from automatic fallback rotation.

## Upgrade from 0.3.1

The 0.3.1 → 0.3.2 migration adds:

`automatic_rotation tinyint(1) unsigned NOT NULL default '1'`

No existing media are removed from rotation by the upgrade.

## Compatibility

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through PHP 8.3 syntax/runtime checks in CI
