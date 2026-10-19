<?php

/**
 * The sync from the command line, for a host whose cron can run PHP:
 *
 *   17 4 * * * /usr/local/bin/php /path/to/cron/sync.php >> /path/to/data/sync.log 2>&1
 *
 * Also handy by hand: php cron/sync.php --budget=120
 *                     php cron/sync.php --covers
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line. Use cron_sync.php over HTTP instead.\n");
}

set_time_limit(0);

$options = getopt('', ['budget::', 'covers', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TEXT
    Sync the Discogs collection into the local database.

      --budget=SECONDS   stop after this long (default 900). The run resumes on
                         the next pass, so a short budget is safe.
      --covers           only make the covers' missing 320px copies, with no
                         Discogs calls (a sync's last phase does the same).

    TEXT);
    exit(0);
}

$log = function (string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $message\n");
};

if (isset($options['covers'])) {
    $missing = covers_missing();
    $log(count($missing) . ' covers without a small copy.');
    $done = make_cover_mids($missing, microtime(true) + (float) ($options['budget'] ?? 900));
    $log("Made {$done['made']} of the {$done['tried']} tried.");
    exit(0);
}

$state = run_full_sync('cli', $log, (float) ($options['budget'] ?? 900));

$log('finished: ' . $state['status'] . ' — ' . $state['message']);

// A failed sync fails the cron job too, so a host that mails about errors says so.
exit($state['status'] === 'error' ? 1 : 0);
