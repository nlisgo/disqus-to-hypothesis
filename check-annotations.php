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
$import_json_annotation_dates_file = $import_folder.'/import-annotation-dates.json';

foreach ([$import_json_annotations_file, $import_json_id_map, $import_json_annotation_dates_file] as $dependency) {
    if (!(new Filesystem)->exists($dependency)) {
        throw new Exception('Missing import file: '.$dependency);
    }
}

debug(sprintf('Gathering all annotation ids for the group %s', $hypothesis_group));
$ids = gather_annotation_ids_for_group($hypothesis_api, $hypothesis_group);
debug(sprintf('%d annotations found for group %s', count($ids, $hypothesis_group)));

$check_json = [];
$posted_json = [];
$annotations_json_dates = [];

$check_json = json_decode(file_get_contents($import_json_annotations_file), true);

if ((new Filesystem)->exists($import_json_id_map)) {
    $posted_json = json_decode(file_get_contents($import_json_id_map), true);
}

if ($limit > 0 || $offset > 0) {
    $check_json = array_slice($check_json, $offset, (($limit > 0) ? $limit : null), true);
}

$total = count($check_json);
$group_limit = ($group_size > 0) ? $group_size : $total;
$co = 0;
$resubmit = [];
for ($i = 0; $i < $total; $i += $group_limit) {
    $co++;
    $items = array_slice($check_json, $i, $group_limit, true);
    $co_too = 0;
    foreach ($items as $id => $item) {
        $co_too++;
        $result = get_annotation($id, $hypothesis_api);
        if ($result === false) {
            debug(sprintf('** Could not find (%s).', $id));
        } elseif (strpos($result->uri, 'https://elifesciences.org/') === 0) {
            debug(sprintf('* Errors found (%s).', $result->id));
        } elseif (!in_array($result->id, $ids)) {
            $resubmit[] = $result->id;
            debug(sprintf('Annotation existing but cannot be found in search (%s).', $result->id));
        } else {
            debug(sprintf('No errors found (%s).', $result->id));
        }
    }
}
