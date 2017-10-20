# disqus API export for hypothes.is

Prepare `./config.php` from `./example.config.php`. And set your disqus API credentials for `forum` and `secret_key`.

Run `composer install`.

Request an XML export from Disqus and save it as `./disqus-export.xml`.

Run `./export.php`

The export fixtures can be found in the `./export/` folder.
