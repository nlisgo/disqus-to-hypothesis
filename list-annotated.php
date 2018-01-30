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
$profiles_api = $GLOBALS['profiles_api'];

$usernames = gather_usernames($profiles_api);
$annotated = [];
foreach ($usernames as $username) {
    $jwt = fetch_jwt($username, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt);
    $api_token = swap_jwt_for_api_token($jwt, $hypothesis_api);
    $annotations = gather_annotation_items_for_username($username, $hypothesis_api, $hypothesis_group, $api_token);
    if (!empty($annotations)) {
        $annotated = array_merge($annotated, $annotations);
    }
}

$annotated = array_unique($annotated);
sort($annotated);

debug($annotated);
