# disqus API export for hypothes.is

Prepare `./config.php` from `./example.config.php`. And set your disqus API credentials for `forum` and `secret_key` and `hypothesis_host`.

Run `composer install`.

Request an XML export from Disqus and save it as `./disqus-export.xml`.

Run `./export.php`

The export fixtures can be found in the `./export/` folder.

If you want to map a `user` value for each email address then create a `./user-map.json` based on `./example.user-map.json`

As part of the migration you may want to move all Disqus hosted media to a new location. All Disqus media (images mainly) will be downloaded to `./export/media/` and given appropriate and unique names. You can upload those files to your new location. Set the `media_new_swap` flag to `true` in `./config.php` and in the same file set the base url for `media_new_location` (e.g. `https://cdn.mysite.com/legacy-disqus/`).
