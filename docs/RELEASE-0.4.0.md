# Radio 0.4.0

Radio 0.4.0 makes large audio libraries substantially easier to organize and search.

## Media classification

Each media item now supports:

- `category` — primary editorial classification;
- `collection_name` — editorial grouping or folder-like collection;
- `tags` — up to 50 normalized free tags.

Category and collection are indexed in MySQL. Tags are normalized, de-duplicated and stored as an exact comma-separated set for reliable filtering.

## Programme media picker

Programme editing no longer uses a long media select. It now provides the same classification-oriented search workflow as the media library:

- text search;
- media type;
- category;
- collection;
- tag;
- compact broadcast-eligible results;
- one-click **+** append action;
- search state preserved after each addition.

The existing programme playlist remains available underneath for reordering and removal.

## Live programme Studio

The private programme preview now doubles as a live editorial Studio for programme owners/editors:

- keep the current audio item playing while the upcoming queue changes;
- search the broadcast-eligible library without reloading the page;
- add a result to the end of the programme with **+**;
- insert a result immediately after the currently playing programme item with **Play next**;
- poll the real programme playlist every few seconds so edits made in another administration tab appear automatically;
- update chapters and total duration without resetting the active audio element;
- exclude Studio listening from public playback/listening statistics.

The Studio uses the real `radio_program_items` order, so the playlist saved while mixing remains the programme order used later for scheduled broadcast and replay.

## Private programme preview

Programme editors can now open **Listen to programme** from the administration page before any broadcast. The private preview:

- reuses the continuous chaptered programme player;
- follows the exact programme item order;
- includes broadcast-eligible media even when on-demand listening is disabled;
- provides global progress and chapter navigation;
- remains restricted to authenticated Radio scheduling/editing access.

## Rotation order and transitions

Automatic rotation now generates a fresh shuffled order for every new protected cycle rather than reusing one date-based order throughout the day. Once created, the cycle remains frozen until it ends, preserving synchronized playback. **Rebuild rotation now** also produces a new order immediately.

Crossfade timing is now transition-aware:

- music → music uses the full configured crossfade;
- jingle → music starts the music early using approximately half of the configured crossfade;
- music → jingle remains un-overlapped so the jingle attack is preserved;
- other editorial transitions remain non-overlapped unless explicitly supported.

## Adaptive audio buffering

The live home player, dynamic block player and detached persistent player now prepare two upcoming media items:

- N+1 starts preloading immediately;
- the player measures the browser's real buffered time with `HTMLMediaElement.buffered`;
- N+2 begins preloading after N+1 has accumulated a useful buffer;
- a crossfade waits for sufficient N+1 buffer instead of starting blindly;
- if the connection is too slow for the planned overlap, playback falls back to a continuous non-overlapped handoff rather than forcing a fragile mix.

Programme preview and Studio use the same N+1/N+2 strategy. Studio displays whether the next track is still loading, ready, or whether the reserve track is also prepared.

## Manual rotation rebuild

Automatic rotation cycles remain protected from normal media and configuration changes until the next cycle. Administrators can now explicitly choose **Rebuild rotation now** from the Rotation page. This CSRF-protected action invalidates the protected snapshot and starts a fresh cycle immediately using current media and settings.

## Media library filters

The administration library now combines:

- free-text search across title, artist, series, category, collection, tags and original filename;
- media type;
- category;
- collection;
- tag;
- publication status;
- source type;
- on-demand availability;
- broadcast availability;
- automatic-rotation availability.

Filters remain active when table sorting changes.

## Upload and editing

Category, collection and tags are available on:

- local single uploads;
- batch uploads;
- remote media;
- media editing.

Batch uploads apply the shared classification values to every uploaded file.

## Upgrade from 0.3.2

The migration adds the following fields to `radio_media`:

- `category varchar(128)`;
- `collection_name varchar(255)`;
- `tags text`.

It also creates indexes for category and collection. Existing media remain valid with empty classification fields.

## Compatibility

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through PHP 8.3 syntax/runtime checks in CI
