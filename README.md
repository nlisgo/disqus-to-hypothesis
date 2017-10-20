# disqus API export for hypothes.is

Prepare `./config.php` from `./example.config.php`. And set your disqus API credentials for `forum` and `secret_key` and `hypothesis_host`.

Run `composer install`.

Request an XML export from Disqus and save it as `./disqus-export.xml`.

Run `./export.php`

The export fixtures can be found in the `./export/` folder.

If you want to map a `user` value for each email address then create a `./user-map.json` based on `./example.user-map.json`
