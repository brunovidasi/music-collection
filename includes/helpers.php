<?php

/** Escape for HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* ---------- Requests and responses ---------- */

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** Empty strings become NULL, so "cleared in the admin" and "never set" agree. */
function nullable(string $value): ?string
{
    return $value === '' ? null : $value;
}

/** Takes a page name ('admin_items?x=1') or a path already under the app ('/admin'). */
function redirect(string $path): never
{
    $isAbsolute = str_starts_with($path, 'http') || str_starts_with($path, app_path());
    header('Location: ' . ($isAbsolute ? $path : url($path)));
    exit;
}

/** Ends the request with the site's own 404 page. */
function not_found(): never
{
    http_response_code(404);
    require __DIR__ . '/../public/404.php';
    exit;
}

/** A one-shot message shown on the next page. */
function flash(string $message, string $kind = 'ok'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'kind' => $kind];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $flashes;
}

/** The current page's name, e.g. 'admin_items'. */
function current_page(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * A request carrying the current data version (?v=, as the pages send) can be
 * kept for good: any change makes a new version, and so a new URL. Anything
 * else is kept for five minutes.
 */
function json_cache_headers(): void
{
    header('Cache-Control: ' . (query('v') === data_version()
        ? 'public, max-age=31536000, immutable'
        : 'public, max-age=300'));
}

/**
 * An API answer built once per data version and then read from a file, since
 * building hundreds of cards takes the server most of a second. The file goes
 * in data/cache/, where a newer version replaces it.
 */
function cached_json_response(string $name, callable $build): never
{
    $name = preg_replace('/[^a-z0-9_-]+/i', '-', $name);
    $dir = data_dir() . '/cache';
    $file = "$dir/$name." . data_version() . '.json';

    $json = is_file($file) ? @file_get_contents($file) : false;

    if ($json === false) {
        $json = json_encode($build(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            foreach (glob("$dir/$name.*.json") ?: [] as $old) {
                @unlink($old);
            }
            $temp = "$file." . getmypid() . '.tmp';
            if (@file_put_contents($temp, $json) === false || !@rename($temp, $file)) {
                @unlink($temp);
            }
        }
    }

    json_cache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
}

/**
 * A short token that changes whenever what a visitor sees does: a sync, an
 * edit in the admin, a setting, an artist or era, a new small cover, or a
 * change to how the answers are built. The pages put it on every API request, so a cached answer
 * is never a stale one.
 */
function data_version(): string
{
    static $version = null;

    if ($version !== null) {
        return $version;
    }

    $db = db();
    $parts = [
        setting('last_successful_sync', ''),
        $db->query('SELECT MAX(updated_at) FROM items')->fetchColumn(),
        $db->query('SELECT MAX(updated_at) FROM releases')->fetchColumn(),
        // The admin keeps its own list filters as settings; those change nothing public.
        $db->query("SELECT MAX(updated_at) FROM settings WHERE key NOT LIKE 'admin\\_%' ESCAPE '\\'")->fetchColumn(),
        // Neither table has an updated_at, and both are small.
        md5(json_encode([
            $db->query('SELECT * FROM artists ORDER BY id')->fetchAll(),
            $db->query('SELECT * FROM eras ORDER BY id')->fetchAll(),
        ])),
        // The folder changes as covers' small copies are added to it, and the cards name them.
        (int) @filemtime(covers_dir()),
    ];

    $code = [...glob(__DIR__ . '/*.php') ?: [], ...glob(__DIR__ . '/../public/api/*.php') ?: []];
    $parts[] = max(array_map(fn ($file) => (int) @filemtime($file), $code));

    return $version = substr(md5(implode('|', $parts)), 0, 10);
}

/* ---------- Text ---------- */

function slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
    if ($transliterated !== false) {
        $slug = $transliterated;
    }
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($slug));

    return trim($slug, '-') ?: 'item';
}

/** Whether a name is already a page or a folder of the site (admin, wantlist, api…), which an artist's URL can't be. */
function is_site_path(string $name): bool
{
    $public = __DIR__ . '/../public/';

    return is_file($public . $name . '.php') || is_dir($public . $name);
}

function plural(int $count, string $singular, ?string $plural = null): string
{
    return $count === 1 ? $singular : ($plural ?? $singular . 's');
}

/** Only ever hand a browser an http(s) link built from outside data. */
function safe_http_url(string $url): bool
{
    return (bool) preg_match('#^https?://#i', $url);
}

/* ---------- Discogs links ---------- */

/** The release id in a pasted Discogs release link, or a bare id. */
function discogs_release_id_from_input(string $input): ?int
{
    $input = trim($input);

    if (preg_match('#discogs\.com/(?:[a-z-]+/)?release/(\d+)#i', $input, $m)) {
        return (int) $m[1];
    }

    return ctype_digit($input) ? (int) $input : null;
}

/**
 * ['master' | 'release', id] from a pasted master or release link, "m123", or
 * a bare number (read as a master, which catches every pressing). ['', 0] if
 * it is none of those.
 */
function discogs_reference_from_input(string $input): array
{
    $input = trim($input);

    if (preg_match('#discogs\.com/(?:[a-z]{2}/)?(master|release)/(\d+)#i', $input, $m)) {
        return [strtolower($m[1]), (int) $m[2]];
    }
    if (preg_match('/^\[?m(\d+)\]?$/i', $input, $m)) {
        return ['master', (int) $m[1]];
    }
    if (ctype_digit($input)) {
        return ['master', (int) $input];
    }

    return ['', 0];
}

function discogs_release_url(int $id): string
{
    return 'https://www.discogs.com/release/' . $id;
}

/* ---------- Dates ---------- */

/**
 * SQLite's datetime('now') is UTC with no zone, so those are read as UTC;
 * anything carrying its own offset is left alone.
 */
function to_timestamp(?string $value): ?int
{
    if (!$value) {
        return null;
    }

    $bareDateTime = (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value);
    $time = strtotime($bareDateTime ? $value . ' UTC' : $value);

    return $time === false ? null : $time;
}

function format_date(?string $value, string $format = 'j M Y'): string
{
    $time = to_timestamp($value);

    return $time === null ? (string) $value : date($format, $time);
}

/** "3 minutes ago" */
function time_ago(?string $value): string
{
    $time = to_timestamp($value);
    if ($time === null) {
        return 'never';
    }

    $seconds = time() - $time;
    if ($seconds < 60) {
        return 'just now';
    }

    foreach ([[86400 * 30, 'month'], [86400 * 7, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$size, $unit]) {
        if ($seconds >= $size) {
            $count = (int) floor($seconds / $size);
            return "$count " . plural($count, $unit) . ' ago';
        }
    }

    return 'just now';
}
