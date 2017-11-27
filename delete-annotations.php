#!/usr/bin/php
<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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

$delete_folder = __DIR__.'/delete/';
$delete_json_file = $delete_folder.'/delete.json';
$import_folder = __DIR__.'/import/';
$import_json_ids_file = $import_folder.'/import-ids.json';
$import_json_id_map = $import_folder.'/import-id-map.json';

if (!(new Filesystem)->exists($import_json_ids_file)) {
    throw new Exception('Missing import file: '.$import_json_ids_file);
}

$delete_json = [];
$import_json_ids = [];

$import_json_ids = json_decode(file_get_contents($import_json_ids_file));

$client = new Client();
foreach ($import_json_ids as $username => $ids) {
    $jwt = fetch_jwt($username, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt);
    $api_token = swap_jwt_for_api_token($jwt, $hypothesis_api);
    if (empty($ids)) {
        $ids = gather_annotation_ids_for_username($username, $hypothesis_api, $hypothesis_group);
    }
    $co = 0;
    foreach ($ids as $id) {
        $co++;
        try {
            $response = $client->request('DELETE', $hypothesis_api.'annotations/'.$id, [
                'headers' => [
                    'Authorization' => 'Bearer '.$api_token,
                ],
            ]);
        } catch (ClientException $e) {
            if (404 !== $e->getResponse()->getStatusCode()) {
                throw $e;
            }
        }
        $delete_json[$username][] = $id;
        debug(sprintf('%d of %d deleted for "%s" (%s).', $co, count($ids), $username, $id));
    }
}

// We must delete the id map file after deletions.
if ((new Filesystem)->exists($import_json_id_map)) {
    (new Filesystem)->remove($import_json_id_map);
}

if ((new Filesystem)->exists($delete_folder)) {
    (new Filesystem)->remove($delete_folder);
}
(new Filesystem)->mkdir($delete_folder);

// Store: processed deletions grouped by username.
file_put_contents($delete_json_file, json_encode($delete_json));
