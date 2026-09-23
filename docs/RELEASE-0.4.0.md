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
