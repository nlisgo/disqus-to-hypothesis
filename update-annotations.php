#!/usr/bin/php
<?php

use Symfony\Component\Filesystem\Filesystem;

require(__DIR__.'/vendor/autoload.php');

error_reporting(E_ERROR | E_PARSE);

$required_empty = [];
foreach (['hypothesis_api', 'hypothesis_authority', 'hypothesis_group', 'hypothesis_client_id_jwt', 'hypothesis_secret_key_jwt'] as $required) {
    if (empty($GLOBALS[$required])) {
        $required_empty[] = $required;
    }
}

if (!empty($required_empty)) {
    throw new Exception('You must set a value for '.implode(', ', $required_empty).' in '.__DIR__.'/config.php');
}

$hypothesis_api = $GLOBALS['hypothesis_api'];
$hypothesis_authority = $GLOBALS['hypothesis_authority'];
$hypothesis_group = $GLOBALS['hypothesis_group'];
$hypothesis_client_id_jwt = $GLOBALS['hypothesis_client_id_jwt'];
$hypothesis_secret_key_jwt = $GLOBALS['hypothesis_secret_key_jwt'];
$target_base_uri = $GLOBALS['target_base_uri'];
$alternative_base_uri = $GLOBALS['alternative_base_uri'];

$limit = 0;
$offset = 0;
$group_size = 100;

$import_folder = __DIR__.'/import/';
$import_json_annotations_file = $import_folder.'/import-annotations.json';
$import_json_id_map = $import_folder.'/import-id-map.json';

if (!(new Filesystem)->exists($import_json_annotations_file)) {
    throw new Exception('Missing import file: '.$import_json_annotations_file);
}

if (!(new Filesystem)->exists($import_json_id_map)) {
    throw new Exception('Missing import file: '.$import_json_id_map);
}

$update_json = [];
$posted_json = [];

$update_json = json_decode(file_get_contents($import_json_annotations_file), true);

if ((new Filesystem)->exists($import_json_id_map)) {
    $posted_json = json_decode(file_get_contents($import_json_id_map), true);
}

if ($limit > 0 || $offset > 0) {
    $update_json = array_slice($update_json, $offset, (($limit > 0) ? $limit : null), true);
}

$total = count($update_json);
$group_limit = ($group_size > 0) ? $group_size : $total;
$co = 0;
for ($i = 0; $i < $total; $i += $group_limit) {
    $co++;
    $items = array_slice($update_json, $i, $group_limit);
    patch_annotations($items, $posted_json, $co, $target_base_uri, $alternative_base_uri, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt, $hypothesis_api);
    debug(sprintf('Patched %d - %d of %d (in all groups).', $i+1, $i+count($items), $total));
}
