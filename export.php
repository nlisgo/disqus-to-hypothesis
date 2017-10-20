#!/usr/bin/php
<?php

require(__DIR__.'/vendor/autoload.php');

use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\Filesystem\Filesystem;

error_reporting(E_ERROR | E_PARSE);

if (empty($GLOBALS['forum']) || empty($GLOBALS['secret_key'])) {
    throw new Exception('You must set a value for forum and secret_key in '.__DIR__.'/config.php');
}

$forum = $GLOBALS['forum'];
$secret_key = $GLOBALS['secret_key'];
$hypothesis_host = $GLOBALS['hypothesis_host'];
$media_new_swap = $GLOBALS['media_new_swap'];
$media_new_location = $GLOBALS['media_new_location'];

if ($media_new_swap && empty($media_new_location)) {
    throw new Exception('You must set media_new_location, if media_new_swap is set to true.');
}

$version = '3.0';
$disqus = new \DisqusAPI($secret_key);
$limit = 0;
$unused_char = '∞';

$export_folder = __DIR__.'/export/';
$export_json_file = $export_folder.'/export.json';
$export_html_file = $export_folder.'/export.html';
$emails_json_file = $export_folder.'/emails.json';
$api_json_file = $export_folder.'/api.json';
$media_json_file = $export_folder.'/media.json';
$media_folder = $export_folder.'/media/';
$disqus_export_file = __DIR__.'/disqus-export.xml';
$user_map_file = __DIR__.'/user-map.json';

if (!(new Filesystem)->exists($disqus_export_file)) {
    throw new Exception('Missing export file: '.$disqus_export_file);
}

$xmlstring = \eLifeIngestXsl\ConvertXML\XMLString::fromString(file_get_contents($disqus_export_file));
$convertxml = new \eLifeIngestXsl\ConvertDisqusXmlToHypothesIs($xmlstring);
$convertxml->setCreator('acct:disqus-import@'.$hypothesis_host);

if (!(new Filesystem)->exists($export_json_file)) {
    $export = $convertxml->getOutput();
    file_put_contents($export_json_file, $export);
} else {
    $export = file_get_contents($export_json_file);
}

$export_json = json_decode($export);

if (is_null($export_json)) {
    throw new Exception('Invalid json in: '.$export_json_file);
}

$export_json_for_example_html = $export_json;
$messages = [];
$user_details = [];
$emails_json = [];
$media_files = [];
$user_map = [];

if ((new Filesystem)->exists($user_map_file)) {
    $json_string = file_get_contents($user_map_file);
    $user_map = json_decode($json_string, true);
    if (is_null($user_map)) {
        throw new Exception('Invalid json in: '.$user_map_file);
    }
}

foreach ($export_json as $k => $item) {
    $post_id = preg_replace('/^disqus\-import:/', '', $item->id);
    $messages[$k] = $post_id;
    $user_details[$post_id] = ['email' => $item->email, 'display_name' => $item->name];
    if (!empty($user_map[$item->email])) {
        $user_details[$post_id]['user'] = $user_map[$item->email];
    }
}

$users = [];
$list = [];
$continue = true;
$args = ['forum' => $forum, 'version' => $version];

while ($continue) {
    $data = $disqus->posts->list($args);
    if (!empty($data->response)) {
        $list = array_merge($list, $data->response);
    }

    if ($data->cursor->hasNext && ($limit <= 0 || count($list) < $limit)) {
        $args['cursor'] = $data->cursor->next;
    } else {
        $continue = !$continue;
    }
};

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
        $list[$i]->author->email = '**empty**';
    }
    $converter = new HtmlConverter();
    $markdown = $post->raw_message;
    $markdown = preg_replace('~(\\n){2,}~', $unused_char, $markdown);
    $markdown = preg_replace('~(http[^\s]+)[ ]+\1~', '$1', $markdown);
    $markdown = $converter->convert($markdown);
    $markdown = str_replace($unused_char, PHP_EOL.PHP_EOL, $markdown);
    $markdown = preg_replace('~(^|\\n)[ ]+~', '$1', $markdown);
    $markdown = preg_replace('~(^|\\n)([a-z0-9]+)(\\\){0,}(\\)|\.) ~', '$1($2) ', $markdown);
    if (!empty($post->media)) {
        $co = 0;
        foreach ($post->media as $media) {
            if (!empty($media->resolvedUrl)) {
                $resolvedUrl = preg_replace('~^//~', 'https://', $media->resolvedUrl);
                if (strpos($media->resolvedUrl, 'https://uploads.disquscdn.com/images') !== 0) {
                    $markdown .= PHP_EOL.PHP_EOL.$resolvedUrl;
                }
                if ($media->mediaType == 2) {
                    $co++;
                    $media_files[$resolvedUrl] = sprintf('%d-%s-%s', $post->id, str_pad($co, 3, '0', STR_PAD_LEFT), basename($resolvedUrl));
                }
            }
        }
    }
    if (!empty($user)) {
        $export_json[array_search($post->id, $messages)]->creator = preg_replace('~(acct:)[^@]+~', '$1'.$user, $export_json[array_search($post->id, $messages)]->creator);
    }
    $export_json[array_search($post->id, $messages)]->body[0]->value = $markdown;
}

$export_json_flat = json_encode($export_json);
if ($media_new_swap) {
    $media_search = array_map(function($value){
        return '~'.str_replace('/', '\\\/', preg_quote($value, '~')).'~';
    }, array_keys($media_files));
    $media_replace = array_map(function($value) use ($media_new_location) {
        return str_replace('/', '\/', $media_new_location.$value);
    }, array_values($media_files));
    $export_json_flat = preg_replace($media_search, $media_replace, $export_json_flat);
}
$export_json_flat = str_replace('\n', $unused_char, $export_json_flat);
$export_json_flat = preg_replace('~( |\\t|'.$unused_char.'|[^:]\"|\"value\":\")(https?:\\\/\\\/[^\s\"'.$unused_char.']+)~', '$1[$2]($2)', $export_json_flat);
$export_json_flat = preg_replace('~\[(https?:\\\/\\\/[^\]]+\.)(jpg|jpeg|png|gif)\]~', '[![]($1$2)]', $export_json_flat);
$export_json_flat = str_replace($unused_char, '\n', $export_json_flat);
$export_json = json_decode($export_json_flat);

if ((new Filesystem)->exists($export_folder)) {
    (new Filesystem)->remove($export_folder);
}
(new Filesystem)->mkdir($export_folder);

if ((new Filesystem)->exists($media_folder)) {
    (new Filesystem)->remove($media_folder);
}
(new Filesystem)->mkdir($media_folder);

foreach ($media_files as $from => $to) {
    (new Filesystem)->copy($from, $media_folder.$to);
}

file_put_contents($emails_json_file, json_encode($emails_json));

$output = ['users' => $users, 'list' => $list];
file_put_contents($api_json_file, json_encode($output['list']));

file_put_contents($export_json_file, json_encode($export_json));

$export_tree = $convertxml->getTree($export_json);

$export_html = $convertxml->presentOutput(json_decode($export_tree));
$export_html = preg_replace('~(</title>)(</head>)~', '$1<style> img {max-width: 350px;} </style>$2', $export_html);
file_put_contents($export_html_file, $export_html);

file_put_contents($media_json_file, json_encode($media_files));
