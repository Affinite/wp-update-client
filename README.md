Affinite WP plugin update client
=======

Requirements
-----------

- PHP >= 8.0

Installation
-----------

1. Copy `AffiniteUpdater` to your plugin folder
2. Load `AffiniteUpdater` class to your plugin
3. Edit `plugin-slug` and `repository-url` (optional) in class constructor. See sample bellow
4. Done!

Sample
-----------

```
require_once __DIR__ . '/AffiniteUpdater/AffiniteUpdater.php';
new AffiniteUpdater( 'plugin-slug', 'repository-url' );
```

* `plugin-slug` (required) = your plugin folder name
* `repository-url` (optional) = update server URL eg. `https://update.affinite.io/` (default)

## License

[MIT](LICENSE)
