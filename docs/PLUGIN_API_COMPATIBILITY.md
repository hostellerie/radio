# Radio Plugin API compatibility notes

Radio targets Geeklog 2.1.1 through 2.2.2 during the transition baseline.

## Critical callbacks

### plugin_getadminoption_radio()

Geeklog expects the historical positional return value:

```php
array($label, $url, $count)
```

Do not replace this with an associative array. Geeklog 2.2.2 reads numeric indexes directly while building the administration menu.

### plugin_idtourl_radio()

Radio supports both calling conventions used by Geeklog and plugin consumers:

```php
plugin_idtourl_radio('media:12');
plugin_idtourl_radio('media', 'media:12');
```

### plugin_chkVersion_radio()

The callback returns the version from Radio's autoinstall metadata so Geeklog can compare installed data and code versions consistently.

## Audited native integrations

The following implemented callbacks have been checked against current Geeklog plugin patterns:

- plugin_getfeednames_radio()
- plugin_getfeedcontent_radio()
- plugin_feedupdatecheck_radio()
- plugin_searchtypes_radio()
- plugin_dopluginsearch_radio()
- plugin_whatsnewsupported_radio()
- plugin_getwhatsnew_radio()
- plugin_collectSitemapItems_radio()
- plugin_getrelateditems_radio()
- plugin_getiteminfo_radio()
- plugin_getcapabilities_radio()
- plugin_wsEnabled_radio()
- plugin_autoinstall_radio()
- plugin_autouninstall_radio()

Callbacks not implemented by Radio, including stats-block, command-and-control label and admin icon callbacks, are optional and must not be added merely for symmetry.

## Regression protection

`tests/plugin_api_contract.php` runs in the PHP 5.6, 8.1 and 8.3 CI matrix. The distributable build depends on that matrix.
