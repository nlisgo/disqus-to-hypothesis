<?php

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use League\HTMLToMarkdown\HtmlConverter;
use Misd\Linkify\Linkify;

/**
 * @return string
 */
function add_anchor_tags_to_plain_urls($raw_message) {
    // Detect if anchor tag exists in $raw_message, no disqus comment appears to have a mix of anchored and non-anchored url's
    $output = $raw_message;
    if (true || !preg_match('~<a href=~', $output)) {
        $linkify = new Linkify();
        $output = $linkify->process($output);
    }

    return $output;
}

/**
 * @return string
 */
function convert_raw_message_to_markdown($raw_message, $formula = []) {
    $base64_lock = [];
    $base64_unlock = [];
    foreach ($formula as $f) {
        if (is_array($f)) {
            $s = key($f);
            $r = $f[$s];
        } else {
            $s = $f;
            $r = $f;
        }
        $base64_lock[$s] = base64_encode($s);
        $base64_unlock[$base64_lock[$s]] = $r;
    }
    $newline_placeholder = '∞';
    $linebreak_placeholder = '∫';
    $hash_placeholder = '¢';
    $dollar_placeholder = '§';
    $converter = new HtmlConverter(['italic_style' => '*']);
    $markdown = $raw_message;
    // Preserve formula through markdown transition.
    foreach ($base64_lock as $k => $v) {
        $markdown = str_replace($k, $v, $markdown);
    }
    // Handle instances of < that are not html and should be converted to &lt;.
    $markdown = preg_replace('~<(?!a|p|strong|em|br|iframe|b|i|u|script)([^\/])~', '&lt;$1', $markdown);
    // Preserve linebreaks <br> and \n, so they can be reinstated after markdown conversion.
    $markdown = preg_replace('~\s*<br/?>\s*~', $linebreak_placeholder, $markdown);
    $markdown = preg_replace('~(\s*\\n\s*){2,}~', $newline_placeholder, $markdown);
    $markdown = preg_replace('~(\s*\\n\s*)~', $linebreak_placeholder, $markdown);

    $markdown = preg_replace('~<(i|em)>([^<'.$linebreak_placeholder.$newline_placeholder.']+)(['.$linebreak_placeholder.$newline_placeholder.']{1,})~', '<$1>$2</$1>$3<$1>', $markdown);

    $markdown = preg_replace('~('.$linebreak_placeholder.'|'.$newline_placeholder.'){2,}~', $newline_placeholder, $markdown);
    // Where a url is repeated, remove the 2nd instance.
    $markdown = preg_replace('~(http[^\s]+)[ ]+\1~', '$1', $markdown);
    // Preserve pound (#) signs at beginning of line.
    $markdown = preg_replace('~(^|'.$newline_placeholder.'|'.$linebreak_placeholder.')\s*#~', '$1'.$hash_placeholder, $markdown);
    // Preserve dollar signs.
    $markdown = preg_replace('~\$~', $dollar_placeholder, $markdown);
    // Convert to markdown.
    $markdown = $converter->convert($markdown);
    // Decrypt formula.
    foreach ($base64_unlock as $k => $v) {
        $markdown = str_replace($k, '`'.$v.'`', $markdown);
    }

    // Detect if there is references.
    if (strpos($markdown, $linebreak_placeholder) !== false && preg_match('~('.$newline_placeholder.'[\*]*\s*references:?[\s\*]*'.$newline_placeholder.')~i', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
        $pre_references = substr($markdown, 0, $matches[0][1]);
        $references = substr($markdown, $matches[0][1]);
        $markdown = preg_replace('~('.$linebreak_placeholder.'|'.$newline_placeholder.'){1,}~', $newline_placeholder, $pre_references).$references;
    } else {
        $markdown = preg_replace('~('.$linebreak_placeholder.'|'.$newline_placeholder.'){1,}~', $newline_placeholder, $markdown);
    }

    // Escape hyphen at the start of a line.
    $markdown = preg_replace('~(^|'.$newline_placeholder.')(\-\s+)~', '$1\\\$2', $markdown);
    // Reinstate newlines.
    $markdown = preg_replace('~('.$newline_placeholder.'){1,}~', PHP_EOL.PHP_EOL, $markdown);
    // Reinstate linebreaks.
    $markdown = preg_replace('~('.$linebreak_placeholder.')~', PHP_EOL, $markdown);
    // Reinstate pound (#) signs.
    $markdown = preg_replace('~('.$hash_placeholder.')~', '\#', $markdown);
    // Reinstate dollar signs.
    $markdown = preg_replace('~('.$dollar_placeholder.'){1,}~', '$', $markdown);
    // Remove space at the beginning of a line.
    $markdown = preg_replace('~(^|\\n)[ ]+~', '$1', $markdown);
    // Detect and standardise list ordinals.
    $markdown = preg_replace('~(^|\\n)([a-z]|[1-9][0-9]*)(\\\){0,}(\\)|\.) ~', '$1($2) ', $markdown);

    return trim($markdown);
}

/**
 * @return string
 */
function convert_urls_to_markdown_links($markdown) {
    $newline_placeholder = '∞';
    // Preserve linebreaks as we perform url conversions.
    $output = preg_replace('~(\s*\\n\s*){2,}~', $newline_placeholder, $markdown);
    // Extend abbreviated links.
    $output = preg_replace('~\[(https?:\/\/[^\]\s]+)\.\.\.\]\((https?[^\)\s]+)\)~i', '<$2>', $output);
    // Link images to their files.
    $output = preg_replace('~<(https?:\/\/[^\>\s]+\.)(jpg|jpeg|png|gif)>~i', '[![]($1$2)]($1$2)', $output);

    $output = preg_replace('~<(https?:\/\/)(youtu\.be|youtube\.com|www\.youtube\.com)(\/[^\>\s]+)>~i', '$1$2$3', $output);
    // Remove duplicate youtube links.
    $output = preg_replace('~(https:\/\/youtu\.be\/|https:\/\/www\.youtube\.com\/watch\?v=)([A-z0-9\-]+)(.+)http:\/\/www\.youtube\.com\/watch\?v=\2$~i', '$1$2$3', $output);
    // Reinstate linebreaks.
    $output = preg_replace('~('.$newline_placeholder.'){1,}~', PHP_EOL.PHP_EOL, $output);

    $output = preg_replace('~([^A-z0-9\/]+)\]\((http[^\)]+)\1\)~i', ']($2)$1', $output);
    $output = preg_replace('~<(http[^>\s]+)([^A-z0-9\/]+)>~i', '<$1>$2', $output);

    return trim($output);
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
function gather_usernames($profiles_api) {
    $client = new Client();
    $response = $client->request('GET', $profiles_api.'?per-page=1');
    $usernames = [];
    $data = json_decode((string) $response->getBody());
    $limit = 100;
    for ($page = 1; (($page - 1) * $limit) <= $data->total; $page += 1) {
        $response = $client->request('GET', $profiles_api.'?page='.$page.'&per-page='.$limit);
        $list = json_decode((string) $response->getBody());
        foreach ($list->items as $item) {
            $usernames[] = $item->id;
        }
    }
    return $usernames;
}

/**
 * @return array
 */
function gather_annotation_ids_for_username($username, $hypothesis_api, $hypothesis_group, $api_token) {
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
 * @return array
 */
function gather_annotation_items_for_username($username, $hypothesis_api, $hypothesis_group, $api_token) {
    $client = new Client();
    $response = $client->request('GET', $hypothesis_api.'search?limit=1&group='.$hypothesis_group.'&user='.$username, [
        'headers' => [
            'Authorization' => 'Bearer '.$api_token,
        ],
    ]);
    $items = [];
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
            $items[] = $item->uri;
        }
    }
    return $items;
}

/**
 * @return array
 */
function gather_annotation_ids_for_group($hypothesis_api, $hypothesis_group) {
    $client = new Client();
    $response = $client->request('GET', $hypothesis_api.'search?limit=1&group='.$hypothesis_group);
    $ids = [];
    $data = json_decode((string) $response->getBody());
    $limit = 100;
    for ($offset = 0; $offset <= $data->total; $offset += $limit) {
        $response = $client->request('GET', $hypothesis_api.'search?limit='.$limit.'&offset='.$offset.'&group='.$hypothesis_group);
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
function post_annotation($annotation, $hypothesis_api, $api_token, &$error = [], $attempt = 1) {
    $client = new Client();
    try {
        $response = $client->request('POST', $hypothesis_api.'annotations', [
            'headers' => [
                'Authorization' => 'Bearer '.$api_token,
            ],
            'body' => json_encode($annotation),
        ]);
        $data = json_decode((string) $response->getBody());
        $result = get_annotation($data->id, $hypothesis_api, $attempt);
        if ($result === false) {
            debug(sprintf('Failed to verify that annotation exists on attempt %d (%s).', $attempt, $data->id));
            $failures = get_annotation_failures($data->id);
            if (!empty($failures)) {
                $failure = end($failures);
                if (isset($failure['exception']) && $failure['exception'] instanceof ClientException && $failure['exception']->getResponse()->getStatusCode() == 404) {
                    debug('Re-attempting to post annotation.');
                    return post_annotation($annotation, $hypothesis_api, $api_token, $error, $attempt+1);
                }
            }
        } else {
            debug(sprintf('Successfully verified annotation on attempt %d (%s).', $attempt, $result->id));
            return $result->id;
        }
    } catch (Exception $e) {
        $error = ['exception' => $e->getMessage()];
        return false;
    }
}

function patch_annotation($id, $annotation, $hypothesis_api, $api_token) {
    $client = new Client();
    $response = $client->request('PATCH', $hypothesis_api.'annotations/'.$id, [
        'headers' => [
            'Authorization' => 'Bearer '.$api_token,
        ],
        'body' => json_encode($annotation),
    ]);
    return json_decode((string) $response->getBody());
}

function get_annotation($id, $hypothesis_api, $attempt = 1) {
    $client = new Client();
    try {
        $response = $client->request('GET', $hypothesis_api.'annotations/'.$id);
        $data = json_decode((string) $response->getBody());
        get_annotation_results($id, $data, $attempt);
        return $data;
    } catch (Exception $e) {
        get_annotation_failures($id, ['error' => $e->getMessage(), 'exception' => $e, 'attempt' => $attempt]);
    }
    return false;
}

function get_annotation_results($id = null, $result = null, $attempt = 1) {
    static $results = [];
    if (is_null($id) && is_null($result)) {
        return $results;
    } elseif (!is_null($id) && is_null($result)) {
        return (!empty($results[$id])) ? $results[$id] : [];
    } elseif (!is_null($id) && !is_null($result)) {
        $result->attempt = $attempt;
        $results[$id][] = $result;
    }

    return false;
}

function get_annotation_failures($id = null, $failure = null) {
    static $failures = [];
    if (is_null($id) && is_null($failure)) {
        return $failures;
    } elseif (!is_null($id) && is_null($failure)) {
        return (!empty($failures[$id])) ? $failures[$id] : [];
    } elseif (!is_null($id) && !is_null($failure)) {
        $failures[$id][] = $failure;
    }

    return false;
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

function post_annotations($items, $posted, $group, $export_references, $target_map, $target_base_uri, $alternative_base_uri, $media_swap, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt, $hypothesis_api, $hypothesis_group, &$jwts = [], &$api_tokens = []) {
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
        $article_page = false;
        if (!empty($export_references[$item->id])) {
            $references = $export_references[$item->id];
            $original_target = reset($references);
            if (preg_match('~/articles/[0-9]{5}$~', $original_target)) {
                $article_page = true;
            }
            $title = $target_map[$original_target]['title'];
            $target = alternative_target_base_uri($original_target, $target_base_uri, $alternative_base_uri);
            foreach ($references as $ref) {
                $ref_dest_id = post_annotations_import_id_map($ref);
                if (!empty($ref_dest_id)) {
                    $refs[] = $ref_dest_id;
                }
            }
        } else {
            $target = false;
            $title = false;
        }

        $body = $item->body[0]->value;
        if (!empty($media_swap)) {
            foreach ($media_swap as $from => $to) {
                $body = str_replace($from, $to, $body);
            }
        }

        $annotation = [
            'created' => substr($item->created, 0, -1).'.000Z',
            'updated' => substr($item->modified, 0, -1).'.000Z',
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
            'document' => [
                'title' => $title,
                'link' => [
                    [
                        'href' => $target,
                    ],
                    [
                        'href' => $target,
                        'rel' => 'canonical',
                        'type' => '',
                    ],
                ],
            ],
            'text' => $body,
            'uri' => $target,
            'imported_id' => $item->id,
        ];
        if (!$title) {
            unset($annotation['document']);
        }
        $annotation['document']['dc'] = [
            'format' => ['text/html'],
            'language' => ['en'],
            'title' => [$title],
            'publisher' => ['eLife Sciences Publications Limited'],
        ];
        if ($article_page) {
            $doi = '10.7554/eLife.'.substr($target, -5);
            $annotation['document']['link'][] = [
                'href' => 'doi:'.$doi,
            ];
            $annotation['document']['dc']['identifier'] = [$doi];
        } elseif (preg_match('~/(?P<type>for\-the\-press|inside\-elife|interviews|labs)/(?P<id>[a-z0-9]{8})~', $target, $matches)) {
            switch ($matches['type']) {
                case 'for-the-press':
                    $identifier = 'press-package';
                    break;
                case 'inside-elife':
                    $identifier = 'blog-article';
                    break;
                case 'interviews':
                    $identifier = 'interview';
                    break;
                case 'labs':
                    $identifier = 'labs-post';
                    break;
                default:
                    $identifier = 'other';
            }
            $identifier .= '/'.$matches['id'];
            $annotation['document']['link'][] = [
                'href' => 'urn:x-dc:elifesciences.org/'.urlencode($identifier),
            ];
            $annotation['document']['dc']['identifier'] = [$identifier];
            $annotation['document']['dc']['relation.ispartof'] = ['elifesciences.org'];
        }

        $error = [];
        if (!empty($posted) && !empty($posted->{$item->id})) {
            $id = $posted->{$item->id};
        } elseif ($target !== false && !empty($body)) {
            $id = post_annotation($annotation, $hypothesis_api, $api_token, $error);
        }

        if ($target !== false && !empty($id) && !empty($body)) {
            debug(sprintf('%d of %d (in group %d) posted (%s:%s).', $co, count($items), $group, $item->id, $id));
            $sent[$id] = $item;
            post_annotations_import_id_map($item->id, $id);
            post_annotations_import_json($id);
            post_annotations_import_json_ids($username, $id);
            post_annotations_import_json_dates($id, $item->created, $item->modified);
            post_annotations_import_json_annotations($id, $annotation);
        } elseif ($target === false || empty($body) || !empty($error)) {
            if (empty($error)) {
                $error = [];
            }
            if (!$target) {
                $error += ['target' => false];
            }
            if (empty($body)) {
                $error += ['body' => false];
            }
            post_annotations_import_json_failures(['annotation' => $annotation] + $error);
        }
    }
}

function patch_annotations($items, $posted, $group, $target_base_uri, $alternative_base_uri, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt, $hypothesis_api, &$jwts = [], &$api_tokens = []) {
    $co = 0;
    foreach ($items as $id => $item) {
        $co++;
        $username = preg_replace('~acct:([^@]+)@.+~', '$1', $item['permissions']['update'][0]);
        if (!isset($jwts[$username])) {
            $jwts[$username] = fetch_jwt($username, $hypothesis_authority, $hypothesis_client_id_jwt, $hypothesis_secret_key_jwt);
        }
        $jwt = $jwts[$username];
        if (!isset($api_tokens[$jwt])) {
            $api_tokens[$jwt] = swap_jwt_for_api_token($jwt, $hypothesis_api);
        }
        $api_token = $api_tokens[$jwt];
        $target = alternative_target_base_uri($item['uri'], $target_base_uri, $alternative_base_uri);
        $item['uri'] = $target;
        $item['target'] = [['source' => $target]];
        if ($imported_id = array_search($id, $posted)) {
            $item['imported_id'] = $imported_id;
        }
        patch_annotation($id, $item, $hypothesis_api, $api_token);
        debug(sprintf('%d of %d (in group %d) patched (%s).', $co, count($items), $group, $id));
    }
}

function alternative_target_base_uri($target, $base_uri, $alternative_base_uri) {
    if (!empty($base_uri) && !empty($alternative_base_uri)) {
        return preg_replace('~^'.preg_quote($base_uri).'~', $alternative_base_uri, $target);
    }

    return $target;
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
                'id' => $id,
                'created' => $created,
                'updated' => $modified,
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

function post_annotations_import_json_failures($failure = null) {
    static $failures = [];
    if (is_null($failure)) {
        return $failures;
    } else {
        $failures[] = $failure;
    }
}
