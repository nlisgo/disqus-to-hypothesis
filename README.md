# disqus API export for hypothes.is

## Install dependendencies

Run `composer install`.

## Configure

Prepare `./config.php` from `./example.config.php`. 

* `forum` - id for your disqus forum.
* `secret_key` - secret key for disqus API.
* `media_new_location` - base url for your new media location.
* `media_new_swap` - flag to swap the disqus media url's for new media location in messages.
* `effective_uri_check` - flag to swap target url's for effective url's as some 301's will be in place.
* `hypothesis_api` - base url for Hypothesis API.
* `hypothesis_group` - Hypothesis publisher group.
* `hypothesis_authority` - Hypothesis authority.
* `hypothesis_client_id_jwt` - Hypothesis client ID to create JWT tokens.
* `hypothesis_secret_key_jwt` - Hypothesis secret key to create JWT tokens.

## Export comments from disqus (`./export.php`)

Request an XML export from Disqus and save it as `./disqus-export.xml`.

If you want to map a `user` value for each email address then create a `./user-map.json` based on `./example.user-map.json`.

If no `./user-map.json` is prepared then all annotations will be assigned to the user `disqus_import`.

Run `./export.php`

The export fixtures can be found in the `./export/` folder.

As part of the migration you may want to move all Disqus hosted media to a new location. All Disqus media (images mainly) will be downloaded to `./export/media/` and given appropriate and unique names. You can upload those files to your new location. Set the `media_new_swap` flag to `true` in `./config.php` and in the same file set the base url for `media_new_location` (e.g. `https://cdn.mysite.com/legacy-disqus/`).

### Artifacts of `./export.php`

* Persistent (these will remain each time `./export.php` is run, they must be removed if we want them to be re-generated):
    1. `./disqus-api.json` - results of disqus list queries stored to file, so we can re-run subsequent operations quickly.
    1. `./disqus-export.json` - conversion from XML to import structure preserved in file.
    1. `./target-map.json` - array of target url keys with effective url values.
* Main:
    1. `./export/media/*` - disqus media files to be uploaded to alternative location.
    1. `./export/emails.json` - email and display name pairs for profile import.
    1. `./export/export-clean.json` - primary output that will be used to create annotations from.
    1. `./export/export-tree.json` - secondary output that will be used to create annotations from, used to determine the parents of an annotation.
* Other:
    1. `./export/export.html` - example HTML output for verification purposes.
    1. `./export/export.json` - artifact of many steps of processing on disqus data.
    1. `./export/media.json` - legacy url key's and new media file name values.
    1. `./export/rejected.json` - comments that will not be migrated because we could not find effective url.

## Create annotations on Hypothesis publisher group. (`./create-annotations.php`)

This script relies on `./export.php` having been run already as it draws from `./export/export-clean.json` and `./export/export-tree.json`.

The user's in `./user-map.json` should already existing in your Hypothesis publisher group before you create these annotations.

If you have not prepared a `./user-map.json` file then create a single `disqus_import` user, as all annotations will be assigned to that user.

See: https://h.readthedocs.io/en/latest/api-reference/#operation/createUser

Run `./create-annotations.php`

The create-annotations fixtures can be found in the `./import/` folder.

### Artifacts of `./create-annotations.php`

* Main:
    1. `./import/import-annotation-dates.json` - array of all annotations processed, could be used by Hypothesis to amend dates of created annotations.
    1. `./import/ids.json` - annotations ids grouped by username.
* Other:
    1. `./import/import.json` - a simple list of all annotation ids.
    1. `./import/import-annotations.json` - array of all annotations processed.
    1. `./import/import-failures.json` - capture the failures to create annotations.
    1. `./import/import-references.json` - the parents of each annotation processed.
    1. `./import/import-missing.json` - capture the annotations that were missing after appearing to be created.
    1. `./import/import-id-map.json` - source id to destination id map.

## Delete annotations on Hypothesis publisher group. (`./delete-annotations.php`)

This script will delete all annotations with id's that have been collected in `./import/import-ids.json`.

If you want to delete all annotations of a specific user then you can do this by manually creating `./import/import-ids.json` with the following structure:

```$json
{"username":[],"disqus_import":[]}
```
With the username as key and an empty array as value, `./delete-annotations.php` will delete all annotations for that username.

The delete-annotations fixtures can be found in the `./delete/` folder.

### Artifacts of `./delete-annotations.php`

1. `./delete/delete.json` - processed deletions grouped by username.
