<?php

use Firebase\JWT\JWT;
use GuzzleHttp\Client;

/**
 * @return string
 */
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

/**
 * @return string
 */
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

/**
 * @return array
 */
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

/**
 * @return void
 */
function debug($output, $interupt = false) {
    $debug = true;
    if ($debug) {
        echo implode(PHP_EOL, (array) $output) . PHP_EOL;
        if ($interupt) {
            exit();
        }
    }
}

function post_annotations($items, $group, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt, $hypothesis_api, $hypothesis_group, &$jwts, &$api_tokens) {
    $co = 0;
    $sent = [];
    foreach ($items as $item) {
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
            debug(sprintf('%d of %d (in group %d) posted (%s:%s).', $co, count($items), $group, $item->id, $id));
            $sent[$id] = $item;
            post_annotations_references($item->target, $id);
            post_annotations_import_json($id);
            post_annotations_import_json_ids($username, $id);
            post_annotations_import_json_dates($id, $item->created, $item->modified);
            post_annotations_import_json_annotations($annotation);
        } elseif (!empty($error)) {
            post_annotations_import_json_failures(['annotation' => $annotation] + $error);
        }
    }
    debug(sprintf('Detecting missing ids in group %d.', $group));
    $missing = detect_missing_ids($sent, $hypothesis_api, $hypothesis_group);
    if (!empty($missing)) {
        debug(sprintf('- %d missing ids, retrying.', count($missing)));
        post_annotations_import_json_missing($missing);
        post_annotations(array_values($missing), $group, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt, $hypothesis_api, $hypothesis_group, $jwts, $api_tokens);
    } else {
        debug('- 0 missing ids.');
    }
}

function detect_missing_ids($sent, $hypothesis_api, $hypothesis_group) {
    sleep(3);
    $missing = $sent;
    $client = new Client();
    $response = $client->request('GET', $hypothesis_api.'search?limit=1&group='.$hypothesis_group);
    $data = json_decode((string) $response->getBody());
    $limit = 100;
    for ($offset = 0; $offset <= $data->total; $offset += $limit) {
        $response = $client->request('GET', $hypothesis_api.'search?limit='.$limit.'&offset='.$offset.'&group='.$hypothesis_group);
        $list = json_decode((string) $response->getBody());
        foreach ($list->rows as $item) {
            if (isset($missing[$item->id])) {
                unset($missing[$item->id]);
                if (empty($missing)) {
                    return [];
                }
            }
        }
    }
    return $missing;
}

function post_annotations_references($target = null, $id = null) {
    static $references = [];
    if (is_null($target) && is_null($id)) {
        return $references;
    } else {
        $references[$target] = $id;
    }
}

function post_annotations_import_json($id = null) {
    static $import_json = [];
    if (is_null($id)) {
        return $import_json;
    } else {
        $import_json[] = $id;
    }
}

function post_annotations_import_json_ids($username = null, $id = null) {
    static $import_json_ids = [];
    if (is_null($username) && is_null($id)) {
        return $import_json_ids;
    } else {
        $import_json_ids[$username][] = $id;
    }
}

function post_annotations_import_json_dates($id = null, $created = null, $modified = null) {
    static $import_json_dates = [];
    if (is_null($id) && is_null($created) && is_null($modified)) {
        return $import_json_dates;
    } else {
        $import_json_dates[] = [
            'imported_id' => $id,
            'created' => $created,
            'modified' => $modified,
        ];
    }
}

function post_annotations_import_json_annotations($annotation = null) {
    static $annotations = [];
    if (is_null($annotation)) {
        return $annotations;
    } else {
        $annotations[] = $annotation;
    }
}

function post_annotations_import_json_missing($missing = null) {
    static $missings = [];
    if (is_null($missing)) {
        return $missings;
    } else {
        $missings[] = $missing;
    }
}

function post_annotations_import_json_failures($failure = null) {
    static $failures = [];
    if (is_null($failure)) {
        return $failures;
    } else {
        $failures[] = $failure;
    }
}
