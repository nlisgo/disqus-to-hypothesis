<?php

// Configure: id for your disqus forum.
$GLOBALS['forum'] = '';
// Configure: secret key for disqus API.
$GLOBALS['secret_key'] = '';

// Configure: base url for your new media location.
$GLOBALS['media_new_location'] = '';
// Configure: flag to swap the disqus media url's for new media location in messages.
$GLOBALS['media_new_swap'] = false;

// Configure: flag to swap target url's for effective url's as some 301's will be in place.
$GLOBALS['effective_uri_check'] = false;

// Configure: profiles api.
$GLOBALS['profiles_api'] = 'https://api.elifesciences.org/profiles';

// Configure: base url for Hypothesis API.
$GLOBALS['hypothesis_api'] = 'https://hypothes.is/api/';
// Configure: Hypothesis publisher group.
$GLOBALS['hypothesis_group'] = '__world__';
// Configure: Hypothesis authority.
$GLOBALS['hypothesis_authority'] = 'hypothes.is';
// Configure: Hypothesis client ID to create JWT tokens.
$GLOBALS['hypothesis_client_id_jwt'] = '';
// Configure: Hypothesis secret key to create JWT tokens.
$GLOBALS['hypothesis_secret_key_jwt'] = '';

// Configure: this is used to perform a search and replace on target uri's if alternative_base_url is not empty.
$GLOBALS['target_base_uri'] = 'http://myhostname.com/';
// Configure: when importing annotations to a test authority, this will allow you to preview the annotations in a client with a different host name.
$GLOBALS['alternative_base_uri'] = '';

// Configure: formula to preserve.
$GLOBALS['formula'] = [];

// Configure: override markdown conversion.
$GLOBALS['override'] = [];

// Configure: additional comments not included in disqus export. Does not support media files currently.
$GLOBALS['additional'] = [];

// Configure: media files to swap.
$GLOBALS['media_swap'] = [];
