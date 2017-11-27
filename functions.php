<?php

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * @return string
 */
function convert_raw_message_to_markdown($raw_message) {
    $newline_placeholder = '∞';
    $linebreak_placeholder = 'ª';
    $converter = new HtmlConverter();
    $markdown = $raw_message;
    // Handle a couple of known instances of <n> used to display a formula.
    $markdown = preg_replace('~<n>~', '&lt;n&gt;', $markdown);
    // Handle instances of < that are not html and should be converted to &lt;.
    $markdown = preg_replace('~<(?!a|p|strong|em|br|iframe|b|i|u|script)([^\/])~', '&lt;$1', $markdown);
    // Preserve linebreaks <br> and \n, so they can be reinstated after markdown conversion.
    $markdown = preg_replace('~<br/?>~', $linebreak_placeholder, $markdown);
    $markdown = preg_replace('~(\\n){2,}~', $newline_placeholder, $markdown);
    $markdown = preg_replace('~(\\n)~', $linebreak_placeholder, $markdown);
    // Where a url is repeated, remove the 2nd instance.
    $markdown = preg_replace('~(http[^\s]+)[ ]+\1~', '$1', $markdown);
    // Convert to markdown.
    $markdown = $converter->convert($markdown);
    // Reinstate linebreaks.
    $markdown = str_replace($newline_placeholder, PHP_EOL.PHP_EOL, $markdown);
    $markdown = str_replace($linebreak_placeholder, PHP_EOL, $markdown);
    // Remove space at the beginning of a line.
    $markdown = preg_replace('~(^|\\n)[ ]+~', '$1', $markdown);
    // Detect and standardise list ordinals.
    $markdown = preg_replace('~(^|\\n)([a-z0-9]+)(\\\){0,}(\\)|\.) ~', '$1($2) ', $markdown);

    return $markdown;
}

/**
 * @return string
 */
function convert_urls_to_markdown_links($input) {
    $linebreak_placeholder = 'ª';
    // Preserve linebreaks as we perform url conversions.
    $output = str_replace('\n', $linebreak_placeholder, $input);
    // Convert url's to markdown links.
    $output = preg_replace('~( |\\t|'.$linebreak_placeholder.'|[^:]\"|\"value\":\")(https?:\\\/\\\/[^\s\"'.$linebreak_placeholder.']+)~', '$1[$2]($2)', $output);
    // Link images to their files.
    $output = preg_replace('~\[(https?:\\\/\\\/[^\]]+\.)(jpg|jpeg|png|gif)\]~', '[![]($1$2)]', $output);
    $output = str_replace($linebreak_placeholder, '\n', $output);

    return $output;
}

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
function gather_annotation_ids_for_username($username, $hypothesis_api, $hypothesis_group) {
    $client = new Client();
    $response = $client->request('GET', $hypothesis_api.'search?limit=1&group='.$hypothesis_group.'&user='.$username);
    $ids = [];
    $data = json_decode((string) $response->getBody());
    $limit = 100;
    for ($offset = 0; $offset <= $data->total; $offset += $limit) {
        $response = $client->request('GET', $hypothesis_api.'search?limit='.$limit.'&offset='.$offset.'&group='.$hypothesis_group.'&user='.$username);
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

function post_annotations($items, $posted, $group, $export_references, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt, $hypothesis_api, $hypothesis_group, &$jwts, &$api_tokens) {
    $co = 0;
    $sent = [];
    $pre_posted = 0;
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
        if (!empty($export_references[$item->id])) {
            $references = $export_references[$item->id];
            $target = reset($references);
            foreach ($references as $ref) {
                $ref_dest_id = post_annotations_import_id_map($ref);
                if (!empty($ref_dest_id)) {
                    $refs[] = $ref_dest_id;
                }
            }
        } else {
            $target = false;
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
                ['source' => $target],
            ],
            'text' => $item->body[0]->value,
            'uri' => $target,
        ];

        $error = [];
        if (!empty($posted) && !empty($posted->{$item->id})) {
            $id = $posted->{$item->id};
            $pre_posted++;
        } else {
            $id = post_annotation($annotation, $hypothesis_api, $api_token, $error);
        }

        if ($target !== false && !empty($id)) {
            debug(sprintf('%d of %d (in group %d) posted (%s:%s).', $co, count($items), $group, $item->id, $id));
            $sent[$id] = $item;
            post_annotations_import_id_map($item->id, $id);
            post_annotations_import_json($id);
            post_annotations_import_json_ids($username, $id);
            post_annotations_import_json_dates($id, $item->created, $item->modified);
            post_annotations_import_json_annotations($id, $annotation);
        } elseif ($target !== false || !empty($error)) {
            if (empty($error)) {
                $error = ['target' => false];
            }
            post_annotations_import_json_failures(['annotation' => $annotation] + $error);
        }
    }

    debug(sprintf('Detecting missing ids in group %d.', $group));
    if ($pre_posted !== count($items)) {
        $missing = detect_missing_ids($sent, $hypothesis_api, $hypothesis_group);
    } else {
        $missing = [];
    }
    if (!empty($missing)) {
        // Remove missing id's from output records.
        foreach (array_keys($missing) as $missing_id) {
            post_annotations_import_id_map(null, $missing_id, true);
            post_annotations_import_json($missing_id, true);
            post_annotations_import_json_ids(null, $missing_id, true);
            post_annotations_import_json_dates($missing_id, null, null, true);
            post_annotations_import_json_annotations($missing_id, null, true);
        }
        debug(sprintf('- %d missing ids, retrying.', count($missing)));
        post_annotations_import_json_missing($missing);
        post_annotations(array_values($missing), $posted, $group, $export_references, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt, $hypothesis_api, $hypothesis_group, $jwts, $api_tokens);
    } else {
        debug('- 0 missing ids.');
    }
}

function detect_missing_ids($sent, $hypothesis_api, $hypothesis_group) {
    // Wait for a few seconds to ensure that the GET request has a chance to succeed.
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

function post_annotations_import_id_map($source_id = null, $id = null, $remove = false) {
    static $map = [];
    if ($remove) {
        if (!is_null($source_id) && isset($map[$source_id])) {
            unset($map[$source_id]);
        } elseif (!is_null($id) && $key = array_search($id, $map)) {
            unset($map[$key]);
        }
    } else {
        if (is_null($source_id) && is_null($id)) {
            return $map;
        } elseif (!is_null($source_id) && isset($map[$source_id])) {
            return $map[$source_id];
        } else {
            $map[$source_id] = $id;
        }
    }
}

function post_annotations_import_json($id = null, $remove = false) {
    static $import_json = [];
    if ($remove) {
        if ($key = array_search($id, $import_json)) {
            unset($import_json[$key]);
            $import_json = array_values($import_json);
        }
    } else {
        if (is_null($id)) {
            return $import_json;
        } else {
            $import_json[] = $id;
        }
    }
}

function post_annotations_import_json_ids($username = null, $id = null, $remove = false) {
    static $import_json_ids = [];
    if ($remove) {
        if (!is_null($username) && isset($import_json_ids[$username])) {
            unset($import_json_ids[$username]);
        } elseif (!is_null($id) && $key = array_search($id, $import_json_ids)) {
            unset($import_json_ids[$key]);
        }
    } else {
        if (is_null($username) && is_null($id)) {
            return $import_json_ids;
        } else {
            $import_json_ids[$username][] = $id;
        }
    }
}

function post_annotations_import_json_dates($id = null, $created = null, $modified = null, $remove = false) {
    static $import_json_dates = [];
    if ($remove) {
        if (!is_null($id) && isset($import_json_dates[$id])) {
            unset($import_json_dates[$id]);
        }
    } else {
        if (is_null($id) && is_null($created) && is_null($modified)) {
            return $import_json_dates;
        } else {
            $import_json_dates[$id] = [
                'imported_id' => $id,
                'created' => $created,
                'modified' => $modified,
            ];
        }
    }
}

function post_annotations_import_json_annotations($id = null, $annotation = null, $remove = false) {
    static $annotations = [];
    if ($remove) {
        if (!is_null($id) && isset($annotations[$id])) {
            unset($annotations[$id]);
        }
    } else {
        if (is_null($id) && is_null($annotation)) {
            return $annotations;
        } else {
            $annotations[$id] = $annotation;
        }
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
