<?php

function db(): PDO
{
    static $db = null;

    if ($db !== null) {
        return $db;
    }

    $c = db_config();

    try {
        $db = new PDO(
            'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STATEMENT_CLASS    => [DbStatement::class],
                // an UPDATE counts the rows it matched, as SQLite did, not only the ones it changed
                (PHP_VERSION_ID >= 80400 ? Pdo\Mysql::ATTR_FOUND_ROWS : PDO::MYSQL_ATTR_FOUND_ROWS) => true,
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Database connection failed: ' . $e->getMessage());
        die('The database is unavailable.');
    }

    // Every timestamp is UTC, as UTC_TIMESTAMP() writes it, whatever the server's zone.
    $db->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    if (apply_schema($db)) {
        require_once __DIR__ . '/seed_data.php';
        seed_artists_and_eras($db);
    }

    return $db;
}

/**
 * Runs sql/schema.sql when it has changed since it last ran (its hash is kept in
 * settings), then adds any MIGRATED_COLUMNS a table is still without. True when
 * the database was empty, so the caller can seed it.
 */
function apply_schema(PDO $db): bool
{
    $file = __DIR__ . '/../sql/schema.sql';
    $hash = md5_file($file);
    $isNew = false;

    try {
        $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'schema_hash'");
        if ($stmt->fetchColumn() === json_encode($hash)) {
            return false;
        }
    } catch (PDOException) {
        $isNew = true; // no settings table yet: a new database
    }

    // one statement at a time: a batch in one exec() reports only the first one's error
    foreach (preg_split('/;\s*$/m', file_get_contents($file)) as $sql) {
        if (trim(preg_replace('/^\s*--.*$/m', '', $sql)) !== '') {
            $db->exec($sql);
        }
    }

    foreach (MIGRATED_COLUMNS as $table => $columns) {
        $stmt = $db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $name => $type) {
            if (!in_array($name, $existing, true)) {
                $db->exec("ALTER TABLE $table ADD COLUMN $name $type");
            }
        }
    }

    $db->prepare("
        INSERT INTO settings (`key`, value) VALUES ('schema_hash', ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = UTC_TIMESTAMP()
    ")->execute([json_encode($hash)]);

    return $isNew;
}

/**
 * Columns added after a table was first created. CREATE TABLE IF NOT EXISTS
 * can't add them to a database that already exists, so they go here as well as
 * into schema.sql.
 */
const MIGRATED_COLUMNS = [
    'items' => [
        'artist_locked'     => 'TINYINT NOT NULL DEFAULT 0',
        'parent_item_id'    => 'INT',          // the box a disc from a box set belongs to
        'disc_config'       => 'MEDIUMTEXT',   // JSON, see disc_config()
        'vinyl_hex'         => 'VARCHAR(16)',  // "#rrggbb", see disc_colours()
        'case_kind'         => 'VARCHAR(16)',  // 'cd' draws a DVD in a CD jewel case
        'vinyl_translucent' => 'TINYINT',      // 1, 0, or NULL for "as the colour text says"
        'labels'            => 'TEXT',         // this column and the five below: see OVERRIDE_FIELDS
        'catalog_number'    => 'TEXT',
        'formats'           => 'TEXT',
        'genres'            => 'TEXT',
        'styles'            => 'TEXT',
        'tracklist'         => 'MEDIUMTEXT',
        'sale_price'        => 'DOUBLE',       // blank means not priced yet
        'sale_currency'     => 'VARCHAR(8)',
        'ebay_url'          => 'TEXT',
        'extra_photos_json' => 'MEDIUMTEXT',   // JSON array of URLs
        'sale_condition'    => 'VARCHAR(16)',  // 'new' | 'used'
        'gallery_json'      => 'MEDIUMTEXT',   // JSON array of URLs, NULL = show everything
        'sold_at'           => 'DATETIME NULL',
    ],
    'artists' => [
        'hero_cover' => 'TEXT',
    ],
];

/**
 * Binds true and false as 1 and 0. PDO would send false as '', which a strict
 * MySQL refuses for a number column (SQLite stored whatever it was given).
 */
class DbStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $params = array_map(fn ($v) => is_bool($v) ? (int) $v : $v, $params);
        }

        return parent::execute($params);
    }
}

/** A settings row, JSON-decoded and cached for the rest of the request. */
function setting(string $key, mixed $default = null): mixed
{
    $cache = &settings_cache();

    if (!array_key_exists($key, $cache)) {
        $stmt = db()->prepare('SELECT value FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row === false ? null : json_decode($row['value'], true);
    }

    return $cache[$key] ?? $default;
}

function set_setting(string $key, mixed $value): void
{
    db()->prepare("
        INSERT INTO settings (`key`, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = UTC_TIMESTAMP()
    ")->execute([$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

    $cache = &settings_cache();
    $cache[$key] = $value;
}

function &settings_cache(): array
{
    static $cache = [];

    return $cache;
}

/** A JSON column as an array, or $default when it is empty or not an array. */
function json_column(?string $raw, array $default = []): array
{
    if ($raw === null || $raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : $default;
}
