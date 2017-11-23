<?php

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

function fetch_jwt($username, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt) {
    $now = time();
    $userid = "acct:{$username}@".$hypothesis_authority;

    $payload = [
        'aud' => 'hypothes.is',
        'iss' => $hypothesis_client_id_jwt,
        'sub' => $userid,
        'nbf' => $now,
        'exp' => $now + 600,
    ];

    return JWT::encode($payload, $hypothesis_secret_key_jwt, 'HS256');
}

function swap_jwt_for_api_token($jwt, $hypothesis_api) {
    $client = new Client();
    $response = $client->request('POST', $hypothesis_api.'token', [
        'form_params' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]
    ]);
    $data = json_decode((string) $response->getBody());
    return $data->access_token;
}

function gather_annotation_ids_for_username($username, $hypothesis_api, $api_token, $hypothesis_group) {
    $client = new Client();
    $response = $client->request('GET', $hypothesis_api.'search?limit=1&group='.$hypothesis_group.'&user='.$username, [
        'headers' => [
            'Authorization' => 'Bearer '.$api_token,
        ],
    ]);
    $ids = [];
    $data = json_decode((string) $response->getBody());
    $limit = 100;
    for ($offset = 0; $offset <= $data->total; $offset += $limit) {
        $response = $client->request('GET', $hypothesis_api.'search?limit='.$limit.'&offset='.$offset.'&group='.$hypothesis_group.'&user='.$username, [
            'headers' => [
                'Authorization' => 'Bearer '.$api_token,
            ],
        ]);
        $list = json_decode((string) $response->getBody());
        foreach ($list->rows as $item) {
            $ids[] = $item->id;
        }
    }
    return $ids;
}

/**
 *
 * @return string|bool
 */
function post_annotation($annotation, $hypothesis_api, $api_token, &$error = []) {
    $client = new Client();
    try {
        $response = $client->request('POST', $hypothesis_api.'annotations', [
            'headers' => [
                'Authorization' => 'Bearer '.$api_token,
            ],
            'body' => json_encode($annotation),
        ]);
        $data = json_decode((string) $response->getBody());
        return $data->id;
    } catch (Exception $e) {
        $error = ['exception' => $e->getMessage()];
        return false;
    }
}

function debug($output, $interupt = false) {
    $debug = true;
    if ($debug) {
        echo implode(PHP_EOL, (array) $output) . PHP_EOL;
        if ($interupt) {
            exit();
        }
    }
}
