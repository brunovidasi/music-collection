<?php

/**
 * Mid-sized copies of the covers, made on this server. Discogs offers a 150px
 * thumbnail and a 600px image and nothing between, and a sleeve on a phone is
 * about 300 pixels across: so each cover gets a 320px copy in public/covers/,
 * which the shelves offer beside Discogs' two (srcset) for the browser to pick
 * from. They are made by the sync's last phase and when a cover is picked in
 * the admin; a cover without one yet simply goes without.
 */

const COVER_MID_WIDTH = 320;

/** How many covers download at once. */
const COVER_BATCH = 6;

function covers_dir(): string
{
    return dirname(__DIR__) . '/public/covers';
}

/** WebP where this PHP can write it, else JPEG. */
function cover_mid_extension(): string
{
    return function_exists('imagewebp') ? 'webp' : 'jpg';
}

/** The copy's file name: the picture's address, hashed, so a new picture is a new file. */
function cover_mid_name(string $src): string
{
    return substr(sha1($src), 0, 24) . '-' . COVER_MID_WIDTH . '.' . cover_mid_extension();
}

/** The copy's URL, or '' while there isn't one. */
function cover_mid_url(string $src): string
{
    if ($src === '') {
        return '';
    }

    $name = cover_mid_name($src);

    return is_file(covers_dir() . "/$name") ? url("covers/$name") : '';
}

/** The width Discogs wrote into an image's address (…/w:600/…), or null. */
function discogs_image_width(string $url): ?int
{
    return preg_match('#/w:(\d+)/#', $url, $m) ? (int) $m[1] : null;
}

/**
 * Whether a cover gets a copy: only Discogs' own pictures (nothing else is
 * ever downloaded), and only those a good deal wider than the copy would be.
 */
function cover_wants_mid(string $src): bool
{
    if (!str_starts_with($src, 'https://i.discogs.com/')) {
        return false;
    }

    $width = discogs_image_width($src);

    return $width === null || $width > COVER_MID_WIDTH * 1.25;
}

/** Every cover a visitor can see that still has no copy. */
function covers_missing(): array
{
    $missing = [];

    foreach (['collection', 'wantlist', 'searching', 'for_sale'] as $source) {
        foreach (public_items($source) as $row) {
            $src = item_cover($row);
            if (cover_wants_mid($src) && !is_file(covers_dir() . '/' . cover_mid_name($src))) {
                $missing[$src] = true;
            }
        }
    }

    return array_keys($missing);
}

/**
 * Makes the copies, a few downloads at a time, until they are done or the
 * deadline passes.
 *
 * @return array{made: int, tried: int}
 */
function make_cover_mids(array $sources, float $deadline): array
{
    $made = $tried = 0;

    if (!function_exists('imagecreatefromstring') || !covers_dir_ready()) {
        return ['made' => 0, 'tried' => count($sources)];
    }

    foreach (array_chunk($sources, COVER_BATCH) as $batch) {
        if (microtime(true) >= $deadline) {
            break;
        }
        foreach (download_images($batch) as $src => $bytes) {
            $tried++;
            if ($bytes !== null && write_cover_mid($src, $bytes)) {
                $made++;
            }
        }
    }

    return ['made' => $made, 'tried' => $tried];
}

/** One cover's copy, when it is picked in the admin. Never throws: a missing copy only costs bytes. */
function make_cover_mid(string $src): void
{
    if (!cover_wants_mid($src) || is_file(covers_dir() . '/' . cover_mid_name($src))) {
        return;
    }

    try {
        make_cover_mids([$src], microtime(true) + 20);
    } catch (Throwable $e) {
        error_log('Cover copy failed: ' . $e->getMessage());
    }
}

function covers_dir_ready(): bool
{
    $dir = covers_dir();

    return (is_dir($dir) || @mkdir($dir, 0755, true)) && is_writable($dir);
}

/**
 * Fetches several pictures side by side.
 *
 * @return array<string, ?string> each address's bytes, or null where it failed
 */
function download_images(array $urls): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($urls as $url) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => discogs_config()['user_agent'],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$url] = $handle;
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $url => $handle) {
        $ok = curl_errno($handle) === 0 && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
        $results[$url] = $ok ? (string) curl_multi_getcontent($handle) : null;
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);

    return $results;
}

/** Scales a downloaded picture to the copy's width and writes it, whole or not at all. */
function write_cover_mid(string $src, string $bytes): bool
{
    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $toWidth = min(COVER_MID_WIDTH, $width);
    $toHeight = max(1, (int) round($height * $toWidth / $width));

    $copy = imagecreatetruecolor($toWidth, $toHeight);
    imagecopyresampled($copy, $image, 0, 0, 0, 0, $toWidth, $toHeight, $width, $height);

    $file = covers_dir() . '/' . cover_mid_name($src);
    $temp = $file . '.' . getmypid() . '.tmp';
    $written = cover_mid_extension() === 'webp' ? imagewebp($copy, $temp, 78) : imagejpeg($copy, $temp, 80);

    if (!$written || !@rename($temp, $file)) {
        @unlink($temp);
        return false;
    }

    return true;
}
