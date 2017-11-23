#!/usr/bin/php
<?php

use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem;

require(__DIR__.'/vendor/autoload.php');

error_reporting(E_ERROR | E_PARSE);

$required_empty = [];
foreach (['hypothesis_authority', 'hypothesis_api', 'hypothesis_group', 'hypothesis_authority', 'hypothesis_client_id_jwt', 'hypothesis_secret_key_jwt'] as $required) {
    if (empty($GLOBALS[$required])) {
        $required_empty[] = $required;
    }
}

if (!empty($required_empty)) {
    throw new Exception('You must set a value for '.implode(', ', $required_empty).' in '.__DIR__.'/config.php');
}

$hypothesis_authority = $GLOBALS['hypothesis_authority'];
$hypothesis_api = $GLOBALS['hypothesis_api'];
$hypothesis_group = $GLOBALS['hypothesis_group'];
$hypothesis_authority = $GLOBALS['hypothesis_authority'];
$hypothesis_client_id_jwt = $GLOBALS['hypothesis_client_id_jwt'];
$hypothesis_secret_key_jwt = $GLOBALS['hypothesis_secret_key_jwt'];

$limit = 0;
$offset = 0;

$export_folder = __DIR__.'/export/';
$export_json_clean_file = $export_folder.'/export-clean.json';
$export_json_tree_file = $export_folder.'/export-tree.json';
$import_folder = __DIR__.'/import/';
$import_json_file = $import_folder.'/import.jspn';
$import_json_references_file = $import_folder.'/import-references.jspn';
$import_json_annotations_file = $import_folder.'/import-annotations.jspn';
$import_json_annotation_dates_file = $import_folder.'/import-annotation-dates.jspn';
$import_json_ids_file = $import_folder.'/import-ids.jspn';
$import_json_failures_file = $import_folder.'/import-failures.jspn';

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
$jwts = [];
$api_tokens = [];
$references = [];
$annotations_json = [];
$annotations_json_dates = [];
$failures = [];

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

if ($limit > 0) {
    $export_json = array_slice($export_json, $offset, $limit);
}

foreach ($export_json as $item) {
    $strtotime = strtotime($item->created);
    $export_json_asc[$strtotime] = $item;
}

ksort($export_json_asc);

if ((count($export_json_asc) !== count($export_json))) {
    throw new Exception('Date clash');
}

$client = new Client();
$co = 0;
foreach ($export_json_asc as $item) {
    $co++;
    $item->creator = preg_replace('~(acct:disqus)\-(import)~', '$1_$2', $item->creator);
    $username = preg_replace('~acct:([^@]+)@.+~', '$1', $item->creator);
    if (!isset($jwts[$username])) {
        $jwts[$username] = fetch_jwt($username, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt);
    }
    $jwt = $jwts[$username];
    if (!isset($api_tokens[$jwt])) {
        $api_tokens[$jwt] = swap_jwt_for_api_token($jwt, $hypothesis_api);
    }
    $api_token = $api_tokens[$jwt];
    $refs = [];
    if (!empty($export_references[$item->target])) {
        foreach ($export_references[$item->target] as $target) {
            if (!empty($references[$target])) {
                $refs[] = $references[$target];
            }
        }
    }
    $annotation = [
        'group' => $hypothesis_group,
        'permissions' => [
            'read' => ['group:'.$hypothesis_group],
            'update' => [$item->creator],
            'delete' => [$item->creator],
            'admin' => [$item->creator],
        ],
        'references' => $refs,
        'tags' => [],
        'target' => [
            ['source' => $item->target],
        ],
        'text' => $item->body[0]->value,
        'uri' => $item->target,
    ];

    $error = [];
    $id = post_annotation($annotation, $hypothesis_api, $api_token, $error);

    if (!empty($id)) {
        debug(sprintf('%d of %d posted (%s:%s).', $co, count($export_json_asc), $item->id, $id));
        $references[$item->target] = $id;
        $import_json[] = $id;
        $import_json_ids[$username][] = $id;
        $annotations_json_dates[$id] = [
            'created' => $item->created,
            'modified' => $item->modified,
        ];
        $annotations_json[] = $annotation;
    } elseif (!empty($error)) {
        $failures[] = ['annotation' => $annotation] + $error;
    }
}

if (!empty($failures)) {
    echo sprintf('%d failures found.', count($failures));
}

if ((new Filesystem)->exists($import_folder)) {
    (new Filesystem)->remove($import_folder);
}
(new Filesystem)->mkdir($import_folder);

file_put_contents($import_json_failures_file, json_encode($failures));
file_put_contents($import_json_references_file, json_encode($export_references));
file_put_contents($import_json_annotation_dates_file, json_encode($annotations_json_dates));
file_put_contents($import_json_annotations_file, json_encode($annotations_json));
file_put_contents($import_json_file, json_encode($import_json));
file_put_contents($import_json_ids_file, json_encode(array_reverse($import_json_ids, true)));
