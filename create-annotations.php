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
$media_swap = $GLOBALS['media_swap'];

$limit = 0;
$offset = 0;
$group_size = 100;

$export_folder = __DIR__.'/export/';
$export_json_clean_file = $export_folder.'/export-clean.json';
$export_json_tree_file = $export_folder.'/export-tree.json';
$import_folder = __DIR__.'/import/';
$import_json_file = $import_folder.'/import.json';
$import_json_references_file = $import_folder.'/import-references.json';
$import_json_id_map = $import_folder.'/import-id-map.json';
$import_json_annotations_file = $import_folder.'/import-annotations.json';
$import_json_annotation_dates_file = $import_folder.'/import-annotation-dates.json';
$import_json_ids_file = $import_folder.'/import-ids.json';
$import_json_failures_file = $import_folder.'/import-failures.json';
$import_json_missing_file = $import_folder.'/import-missing.json';
$skip_posted = true;

if (!(new Filesystem)->exists($export_json_clean_file)) {
    throw new Exception('Missing export file: '.$export_json_clean_file);
}

if (!(new Filesystem)->exists($export_json_tree_file)) {
    throw new Exception('Missing export file: '.$export_json_tree_file);
}

$export_json = [];
$export_json_asc = [];
$export_tree = [];
$export_references = [];
$import_json = [];
$import_json_ids = [];
$id_map_json = [];
$annotations_json = [];
$annotations_json_dates = [];
$failures_json = [];
$missing_json = [];
$posted_json = [];

if ($skip_posted && (new Filesystem)->exists($import_json_id_map)) {
    $posted_json = json_decode(file_get_contents($import_json_id_map));
}

$export_json = json_decode(file_get_contents($export_json_clean_file));
$export_tree = json_decode(file_get_contents($export_json_tree_file));

$process_branches = function ($branches, $references = []) use (&$export_references, &$process_branches) {
    $references = (!empty($references)) ? $references : [];
    foreach ($branches as $target => $branch) {
        $export_references[$target] = $references;
        if (!empty($branch)) {
            $branch_references = $references;
            $branch_references[] = $target;
            $process_branches($branch, $branch_references);
        }
    }
};

$process_branches($export_tree);

if ($limit > 0 || $offset > 0) {
    $export_json = array_slice($export_json, $offset, (($limit > 0) ? $limit : null));
}

foreach ($export_json as $item) {
    $strtotime = strtotime($item->created);
    $export_json_asc[$strtotime] = $item;
}

ksort($export_json_asc);

if ((count($export_json_asc) !== count($export_json))) {
    throw new Exception('Date clash');
}

$total = count($export_json_asc);
$group_limit = ($group_size > 0) ? $group_size : $total;
$co = 0;
for ($i = 0; $i < $total; $i += $group_limit) {
    $co++;
    $items = array_slice($export_json_asc, $i, $group_limit);
    post_annotations($items, $posted_json, $co, $export_references, $target_base_uri, $alternative_base_uri, $media_swap, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt, $hypothesis_api, $hypothesis_group);
    debug(sprintf('Posted %d - %d of %d (in all groups).', $i+1, $i+count($items), $total));
}

$id_map_json = post_annotations_import_id_map();
$import_json = post_annotations_import_json();
$import_json_ids = post_annotations_import_json_ids();
$annotations_json_dates = post_annotations_import_json_dates();
$annotations_json = post_annotations_import_json_annotations();
$missing_json = get_annotation_failures();
$failures_json = post_annotations_import_json_failures();

if (!empty($failures_json)) {
    echo sprintf('%d failures found.', count($failures_json));
}

if ((new Filesystem)->exists($import_folder)) {
    (new Filesystem)->remove($import_folder);
}
(new Filesystem)->mkdir($import_folder);

// Store: capture the annotations that were missing after appearing to be created.
file_put_contents($import_json_missing_file, json_encode($missing_json));
// Store: capture the failures to create annotations.
file_put_contents($import_json_failures_file, json_encode($failures_json));
// Store: the parents of each annotation processed.
file_put_contents($import_json_references_file, json_encode($export_references));
// Store: primary output for Hypothesis to set correct dates for annotations.
file_put_contents($import_json_annotation_dates_file, json_encode(array_values($annotations_json_dates)));
// Store: array of all annotations processed.
file_put_contents($import_json_annotations_file, json_encode($annotations_json));
// Store: a simple list of all annotation ids.
file_put_contents($import_json_file, json_encode($import_json));
// Store: annotations ids grouped by username.
file_put_contents($import_json_ids_file, json_encode(array_reverse($import_json_ids, true)));
// Store: source id to destination id map.
file_put_contents($import_json_id_map, json_encode($id_map_json));
