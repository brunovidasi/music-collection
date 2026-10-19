<?php

require_once __DIR__ . '/../../includes/bootstrap.php';

if (!setting('show_wantlist', true)) {
    json_response(['error' => 'The wantlist is not public.'], 404);
}

cached_json_response('wantlist', fn () => [
    'updated' => setting('last_successful_sync'),
    'wanted'  => array_map('item_card', public_items('wantlist')),
    'hunting' => array_map('item_card', public_items('searching')),
]);
