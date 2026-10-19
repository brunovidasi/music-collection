<?php

require_once __DIR__ . '/../../includes/bootstrap.php';

cached_json_response('collection', function () {
    $rows = public_items('collection');

    return [
        'updated' => setting('last_successful_sync'),
        'count'   => count($rows),
        'items'   => array_map('item_card', $rows),
    ];
});
