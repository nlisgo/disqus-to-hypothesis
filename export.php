#!/usr/bin/php
<?php

require(__DIR__.'/vendor/autoload.php');

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Symfony\Component\Filesystem\Filesystem;

error_reporting(E_ERROR | E_PARSE);

if (empty($GLOBALS['forum']) || empty($GLOBALS['secret_key'])) {
    throw new Exception('You must set a value for forum and secret_key in '.__DIR__.'/config.php');
}

$forum = $GLOBALS['forum'];
$secret_key = $GLOBALS['secret_key'];
$hypothesis_authority = $GLOBALS['hypothesis_authority'];
$media_new_swap = $GLOBALS['media_new_swap'];
$media_new_location = $GLOBALS['media_new_location'];
$effective_uri_check = $GLOBALS['effective_uri_check'];
$formula = $GLOBALS['formula'];
$target_base_uri = $GLOBALS['target_base_uri'];
$alternative_base_uri = $GLOBALS['alternative_base_uri'];

if ($media_new_swap && empty($media_new_location)) {
    throw new Exception('You must set media_new_location, if media_new_swap is set to true.');
}

$version = '3.0';
$disqus = new \DisqusAPI($secret_key);
$limit = 0;

$export_folder = __DIR__.'/export/';
$export_json_file = $export_folder.'/export.json';
$export_json_clean_file = $export_folder.'/export-clean.json';
$export_json_tree_file = $export_folder.'/export-tree.json';
$export_html_file = $export_folder.'/export.html';
$emails_json_file = $export_folder.'/emails.json';
$media_json_file = $export_folder.'/media.json';
$rejected_json_file = $export_folder.'/rejected.json';
$media_folder = $export_folder.'/media/';
$disqus_export_file = __DIR__.'/disqus-export.xml';
$disqus_json_file = __DIR__.'/disqus-export.json';
$disqus_api_file = __DIR__.'/disqus-api.json';
$user_map_file = __DIR__.'/user-map.json';
$target_map_file = __DIR__.'/target-map.json';
$target_map_autosave = true;
$disqus_api_autosave = true;

if (!(new Filesystem)->exists($disqus_export_file)) {
    throw new Exception('Missing disqus export file: '.$disqus_export_file);
}

$xmlstring = \eLifeIngestXsl\ConvertXML\XMLString::fromString(file_get_contents($disqus_export_file));
$convertxml = new \eLifeIngestXsl\ConvertDisqusXmlToHypothesIs($xmlstring);
$convertxml->setCreator('acct:disqus-import@'.$hypothesis_authority);

// Convert Disqus XML to json structure required to import to Hypothesis.
if (!(new Filesystem)->exists($disqus_json_file)) {
    debug('Conversion from XML to import structure.');
    $export = $convertxml->getOutput();
    debug('Conversion complete.');
    // Store: conversion from XML to import structure preserved in file.
    file_put_contents($disqus_json_file, $export);
} else {
    $export = file_get_contents($disqus_json_file);
}

$export_json = json_decode($export);

if (is_null($export_json)) {
    throw new Exception('Invalid json in: '.$export_json_file);
}

$export_json_clean = [];
$messages = [];
$user_details = [];
$emails_json = [];
$media_files = [];
$user_map = [];
$target_map = [];
$rejected_annotations = [];

if ((new Filesystem)->exists($user_map_file)) {
    $json_string = file_get_contents($user_map_file);
    $user_map = json_decode($json_string, true);
    if (is_null($user_map)) {
        throw new Exception('Invalid json in: '.$user_map_file);
    }
}

if ((new Filesystem)->exists($target_map_file)) {
    $json_string = file_get_contents($target_map_file);
    $target_map = json_decode($json_string, true);
    if (is_null($target_map)) {
        throw new Exception('Invalid json in: '.$target_map_file);
    }
}

$dom = new DOMDocument();
foreach ($export_json as $k => $item) {
    $post_id = preg_replace('~^disqus\-import:~', '', $item->id);
    $messages[$k] = $post_id;
    $user_details[$post_id] = ['email' => $item->email, 'display_name' => $item->name];
    if (!empty($user_map[$item->email])) {
        $user_details[$post_id]['user'] = $user_map[$item->email];
    }
    // Convert target url's to effective url's.
    if ($effective_uri_check) {
        if (!isset($target_map[$item->target]) && strpos($item->target, 'disqus-import:') !== 0) {
            try {
                $client = new Client();
                $html = $client->get($item->target, [
                    'on_stats' => function (TransferStats $stats) use (&$effective_url) {
                        $effective_url = (string) $stats->getEffectiveUri();
                    }
                ])->getBody()->getContents();
                $dom->loadHTML($html);
                $title = $dom->getElementsByTagName('title')->item(0)->textContent;
                $title = preg_split('~\s+\|\s+~', $title);
                $title = reset($title);
                $effective = [
                    'effective' => $effective_url,
                    'title' => $title,
                ];
                $target_map[$item->target] = $effective;
                $target_map[$effective_url] = $effective;
            } catch (Exception $e) {
                $rejected_annotations[] = ['reason' => $e->getMessage(), 'item' => $item];
                $target_map[$item->target] = null;
            }
        }
        if ($target_map_autosave) {
            // Store: array of target url keys with effective url values.
            file_put_contents($target_map_file, json_encode($target_map));
        }
    }
}

$users = [];
$list = [];
$continue = true;
$args = ['forum' => $forum, 'version' => $version];

// Get disqus list from API.
if (!(new Filesystem)->exists($disqus_api_file)) {
    debug('Gather comments from Disqus API.');
    while ($continue) {
        $data = $disqus->posts->list($args);
        if (!empty($data->response)) {
            $list = array_merge($list, $data->response);
            if ($disqus_api_autosave) {
                // Store: results of disqus list queries stored to file, so we can re-run subsequent operations quickly.
                file_put_contents($disqus_api_file, json_encode($list));
            }
        }

        if ($data->cursor->hasNext && ($limit <= 0 || count($list) < $limit)) {
            $args['cursor'] = $data->cursor->next;
        } else {
            $continue = !$continue;
        }
    };
    debug('Completed gathering comments from Disqus API.');
} else {
    $list = json_decode(file_get_contents($disqus_api_file));
}

foreach ($list as $i => $post) {
    $user = null;
    if (!empty($user_details[$post->id])) {
        $author = $post->author;
        $author->email = $user_details[$post->id]['email'];
        $author->display_name = $user_details[$post->id]['display_name'];
        if (!empty($user_details[$post->id]['user'])) {
            $user = $user_details[$post->id]['user'];
        }
        if (!empty($users[$post->author->id])) {
            $post_count = $users[$post->author->id]->post_count + 1;
        } else {
            $post_count = 1;
        }
        $author->post_count = $post_count;
        $users[$post->author->id] = $author;
        $list[$i]->author = $author;
        $emails_json[$author->email] = $author->display_name;
    } else {
        // This is not expected but is used as a marker in case no email is found.
        $list[$i]->author->email = '**empty**';
    }
    $raw_message = $post->raw_message;
    if (empty(trim(strip_tags($raw_message)))) {
        $raw_message = $export_json[array_search($post->id, $messages)]->body[0]->value;
    }

    $markdown = add_anchor_tags_to_plain_urls($raw_message);
    $markdown = convert_raw_message_to_markdown($markdown, $formula);
    // Append attached media files to the end of message.
    if (!empty($post->media)) {
        $co = 0;
        foreach ($post->media as $media) {
            if (!empty($media->resolvedUrl)) {
                $resolvedUrl = preg_replace('~^//~', 'https://', $media->resolvedUrl);
                if (strpos($media->resolvedUrl, 'https://uploads.disquscdn.com/images') !== 0) {
                    $markdown .= PHP_EOL.PHP_EOL.'<'.$resolvedUrl.'>';
                }
                if ($media->mediaType == 2) {
                    $co++;
                    // Add entry for disqus media files so we can upload files to alternative location.
                    $media_files[$resolvedUrl] = sprintf('%d-%s-%s', $post->id, str_pad($co, 3, '0', STR_PAD_LEFT), basename($resolvedUrl));
                }
            }
        }
    }
    if (!empty($user)) {
        $export_json[array_search($post->id, $messages)]->creator = preg_replace('~(acct:)[^@]+~', '$1'.$user, $export_json[array_search($post->id, $messages)]->creator);
    }
    $markdown = convert_urls_to_markdown_links($markdown);

    $export_json[array_search($post->id, $messages)]->body[0]->value = $markdown;
    debug(sprintf('%d of %d prepared.', $i+1, count($list)));
}

// Replace media files in messages with paths to alternative location.
$export_json_flat = json_encode($export_json);
if ($media_new_swap) {
    debug('Convert media links.');
    $media_search = array_map(function($value){
        return '~'.str_replace('/', '\\\/', preg_quote($value, '~')).'~';
    }, array_keys($media_files));
    $media_replace = array_map(function($value) use ($media_new_location) {
        return str_replace('/', '\/', $media_new_location.$value);
    }, array_values($media_files));
    $export_json_flat = preg_replace($media_search, $media_replace, $export_json_flat);
    debug('Completed conversion of media links.');
}
$export_json = json_decode($export_json_flat);

debug('Set targets and prepare clean json.');
foreach ($export_json as $k => $item) {
    if (!empty($target_map[$item->target])) {
        $export_json[$k]->target = $target_map[$item->target]['effective'];
    } elseif (strpos($export_json[$k]->target, 'disqus-import:') !== 0) {
        $export_json[$k]->target = false;
    }
    if (!empty($export_json[$k]->target)) {
        $export_json_clean[$k] = $export_json[$k];
        unset($export_json_clean[$k]->email);
        unset($export_json_clean[$k]->name);
    }
}
debug('Completed setting targets and preparation of clean json.');

if ((new Filesystem)->exists($export_folder)) {
    (new Filesystem)->remove($export_folder);
}
(new Filesystem)->mkdir($export_folder);

if ((new Filesystem)->exists($media_folder)) {
    (new Filesystem)->remove($media_folder);
}
(new Filesystem)->mkdir($media_folder);

debug('Store media files locally.');
// Store: disqus media files to be uploaded to alternative location.
foreach ($media_files as $from => $to) {
    (new Filesystem)->copy($from, $media_folder.$to);
}
debug('Completed storage of media files locally.');

// Store: email and display name pairs for profile import.
file_put_contents($emails_json_file, json_encode($emails_json));

// Store: artifact of many steps of processing on disqus data.
file_put_contents($export_json_file, json_encode($export_json));
// Store: primary output that will be used to create annotations from.
file_put_contents($export_json_clean_file, json_encode(array_values($export_json_clean)));

// Store: secondary output that will be used to create annotations from, used to determine the parents of an annotation.
$export_tree = $convertxml->getTree($export_json);
file_put_contents($export_json_tree_file, $export_tree);

// Store: example HTML output for verification purposes.
$export_html = $convertxml->presentOutput(json_decode($export_tree));
$export_html = preg_replace('~(</title>)(</head>)~', '$1<style> img {max-width: 350px;} </style>$2', $export_html);

if (!empty($target_base_uri) && !empty($alternative_base_uri)) {
    debug('Add preview link to export.html');
    $export_html = preg_replace('~(<h2><a href=")('.preg_quote($target_base_uri).')([^\"]+)(">[^<]+</a>)(</h2>)~', '$1$2$3$4 <a href="'.$alternative_base_uri.'$3?open-sesame" target="_blank">preview</a>$5', $export_html);
}

file_put_contents($export_html_file, $export_html);

// Store: legacy url key's and new media file name values.
file_put_contents($media_json_file, json_encode($media_files));

// Store: comments that will not be migrated because we could not find effective url.
file_put_contents($rejected_json_file, json_encode($rejected_annotations));
