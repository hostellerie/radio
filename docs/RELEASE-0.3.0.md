# Radio 0.3.0 — Release notes

Radio 0.3.0 introduces an explicit media availability model and prepares the plugin for cleaner separation between scheduled radio programming and public on-demand listening.

## Media availability

Every media item now has three independent editorial concepts:

- `status=published`: the media is active and usable by Radio.
- `on_demand=1`: the media can be exposed individually to visitors.
- `broadcast=1`: the media can be used in programmes and automatic rotation.

A published item can be broadcast-only, on-demand-only, both, or neither.

## Behaviour changes

- The public catalogue and individual media pages expose only published media with **On demand** enabled.
- Podcast/feed collections and public M3U content use only on-demand media.
- Programme administration offers only published media with **Broadcast** enabled.
- Existing programme items whose Broadcast flag is later disabled are ignored by programme duration and synchronized playback.
- Automatic rotation requires Broadcast to be enabled.
- Replay honours Broadcast availability.
- Programme pages no longer expose broadcast-only items as individual audio players.
- Rotation diagnostics report when Broadcast is disabled.

## Administration

Upload, batch upload, external media and media editing now expose separate **On demand** and **Broadcast** controls. The media library shows both availability states.

Batch upload applies the two availability choices to every uploaded file in the batch.

## Upgrade

The plugin version is now **0.3.0**.

The 0.2.5 → 0.3.0 upgrade adds two columns to `radio_media`:

- `on_demand`
- `broadcast`

Both default to `1`, preserving the behaviour of existing media after upgrade.

The upgrade is explicit through `plugin_upgrade_radio()` and `install_updates.php`.
