-- The Collection — MySQL / MariaDB schema.
--
-- Applied by includes/db.php whenever this file changes (it keeps a hash of it
-- in settings), so every statement is CREATE ... IF NOT EXISTS. Columns added to
-- a table later go in MIGRATED_COLUMNS in includes/db.php as well, since this
-- can't add them to a table that already exists.
--
-- Every table is utf8mb4_bin: names and keys compare and sort byte by byte, as
-- they did in SQLite; a query that wants case-insensitive order sorts on
-- LOWER(column). Timestamps are UTC (the connection's time zone is +00:00);
-- the ones Discogs sends (date_added, date_changed) and missing_since are kept
-- as the text they arrive as, offset and all.
--
-- The shape follows Discogs' own: a `releases` row is the record as Discogs
-- knows it (shared by everyone who owns a copy), and an `items` row is one
-- physical copy on Bruno's shelf — or one line on the wantlist. Everything
-- Discogs sends lives in `releases` and is overwritten on every sync;
-- everything typed by hand lives in `items` and is never touched by a sync.
-- That split is what makes "sync whenever I want" safe.

CREATE TABLE IF NOT EXISTS users (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(191) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    last_login_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Small JSON blobs keyed by name: which Discogs fields the drawer shows, when
-- the last sync ran, and so on. Anything that is configuration rather than data.
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(191) NOT NULL PRIMARY KEY,
    value       MEDIUMTEXT NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- A release as Discogs describes it. Overwritten wholesale on each sync.
--
-- The columns down to cover_image come from the collection listing itself
-- ("basic information"), which arrives 100 at a time. Everything below
-- basic_synced_at is only on the per-release endpoint, one HTTP call each, so
-- it is filled in progressively and detail_fetched_at records when.
CREATE TABLE IF NOT EXISTS releases (
    discogs_id          BIGINT NOT NULL PRIMARY KEY,
    master_id           BIGINT,
    title               TEXT NOT NULL DEFAULT '',
    artists_text        TEXT NOT NULL DEFAULT '',
    primary_artist      VARCHAR(255) NOT NULL DEFAULT '',
    artists_json        MEDIUMTEXT,
    year                INT,
    labels_json         MEDIUMTEXT,
    formats_json        MEDIUMTEXT,
    formats_text        TEXT NOT NULL DEFAULT '',
    genres_json         MEDIUMTEXT,
    styles_json         MEDIUMTEXT,
    thumb               TEXT,
    cover_image         TEXT,
    basic_synced_at     DATETIME NULL,

    country             VARCHAR(255),
    released            VARCHAR(32),
    released_formatted  VARCHAR(64),
    uri                 TEXT,
    data_quality        VARCHAR(64),
    release_notes       MEDIUMTEXT,
    estimated_weight    DOUBLE,
    num_for_sale        INT,
    lowest_price        DOUBLE,
    barcode             VARCHAR(255),
    community_json      MEDIUMTEXT,
    images_json         MEDIUMTEXT,
    tracklist_json      MEDIUMTEXT,
    identifiers_json    MEDIUMTEXT,
    companies_json      MEDIUMTEXT,
    extraartists_json   MEDIUMTEXT,
    videos_json         MEDIUMTEXT,
    series_json         MEDIUMTEXT,
    date_changed        VARCHAR(32),
    detail_fetched_at   DATETIME NULL,

    updated_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),

    KEY idx_releases_master (master_id),
    KEY idx_releases_artist (primary_artist),
    KEY idx_releases_detail (detail_fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- The artists that get their own page (Lady Gaga, Anitta, RBD, Beyoncé…).
-- match_names holds the Discogs spellings that belong to this page, as a JSON
-- array, so "RBD" can also catch "Rebelde" without a second page.
CREATE TABLE IF NOT EXISTS artists (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(191) NOT NULL UNIQUE,
    name            VARCHAR(255) NOT NULL,
    match_names     TEXT,
    discogs_artist_id BIGINT,
    tagline         TEXT,
    intro           TEXT,
    accent          VARCHAR(32),
    position        INT NOT NULL DEFAULT 0,
    is_published    TINYINT NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    hero_cover      TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- One era of an artist's career: The Fame, Born This Way, Mayhem…
CREATE TABLE IF NOT EXISTS eras (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    artist_id   INT NOT NULL,
    slug        VARCHAR(191) NOT NULL,
    name        VARCHAR(255) NOT NULL,
    years       VARCHAR(64),
    tagline     TEXT,
    position    INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    UNIQUE (artist_id, slug),
    FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- How a release finds its era without anyone assigning it by hand.
--
-- A master id catches every pressing of an album at once — CD, reissue, coloured
-- vinyl, the lot — which is why the hand-built Lady Gaga page was already
-- written this way. Releases with no master (most promos) are matched by their
-- own id. `rank` is also the display order inside the era: album first, then
-- singles, in the order they were added here.
CREATE TABLE IF NOT EXISTS era_rules (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    era_id      INT NOT NULL,
    kind        VARCHAR(16) NOT NULL,       -- 'master' | 'release'
    discogs_id  BIGINT NOT NULL,
    `rank`      INT NOT NULL DEFAULT 0,
    UNIQUE (kind, discogs_id),
    KEY idx_era_rules_era (era_id),
    FOREIGN KEY (era_id) REFERENCES eras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- One physical copy on the shelf, one line on the wantlist, or one record still
-- being hunted for.
--
-- Discogs' own key for a copy is instance_id (a release owned twice has two
-- instances), so that is what a collection row is matched on. Wantlist rows have
-- no instance, so they are keyed by release_id instead: wantlist_release_id is
-- release_id on a wantlist row and NULL on any other, which makes its unique key
-- one per release on the wantlist only. It is INVISIBLE, so SELECT * leaves it out.
--
-- Columns from `media_kind` down are Bruno's, not Discogs': a sync never writes
-- them. Where one is blank, the reader falls back to the Discogs value (see
-- item_field_value() in includes/fields.php), so filling one in is an override
-- rather than a duplicate.
CREATE TABLE IF NOT EXISTS items (
    id                  INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source              VARCHAR(32) NOT NULL DEFAULT 'collection', -- collection | wantlist | searching | for_sale
    instance_id         BIGINT,
    release_id          BIGINT,
    folder_id           BIGINT,
    date_added          VARCHAR(32),
    rating              INT,
    discogs_fields_json MEDIUMTEXT,                                 -- the collection's own custom fields

    -- Free-text stand-ins for a 'searching' row that isn't on Discogs at all.
    manual_title        TEXT,
    manual_artist       TEXT,

    media_kind          VARCHAR(16) NOT NULL DEFAULT 'other',       -- vinyl | cd | dvd | bluray | other
    media_kind_locked   TINYINT NOT NULL DEFAULT 0,
    artist_id           INT,
    era_id              INT,
    era_locked          TINYINT NOT NULL DEFAULT 0,
    -- Position inside the era, copied from the matching era_rules row at sync
    -- time (album first, then its singles). sort_rank below overrides it when
    -- an order is set by hand.
    era_rank            INT NOT NULL DEFAULT 1000,

    -- The fields Bruno edits at the top of the form.
    barcode             VARCHAR(255),
    release_date        VARCHAR(64),
    region              VARCHAR(255),
    media               VARCHAR(255),
    item_type           VARCHAR(32),     -- album | single | promo | ep | compilation | other
    vinyl_size          VARCHAR(32),     -- 7" | 10" | 12" …
    vinyl_color         VARCHAR(255),    -- colour / variant, e.g. "Pink Opaque"
    notes               TEXT,

    -- Chosen from the release's Discogs images in the admin.
    cover_url           TEXT,
    disc_url            TEXT,

    is_visible          TINYINT NOT NULL DEFAULT 1,
    is_featured         TINYINT NOT NULL DEFAULT 0,
    sort_rank           INT NOT NULL DEFAULT 0,

    -- Set when a sync no longer finds this copy on Discogs. The row is kept, not
    -- deleted, because it carries hand-typed notes — the admin lists it as gone
    -- and offers to remove it.
    missing_since       VARCHAR(32),

    created_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),

    artist_locked       TINYINT NOT NULL DEFAULT 0,
    labels              TEXT,            -- this column and the five below: see OVERRIDE_FIELDS
    catalog_number      TEXT,
    formats             TEXT,
    genres              TEXT,
    styles              TEXT,
    tracklist           MEDIUMTEXT,
    parent_item_id      INT,             -- the box a disc from a box set belongs to
    disc_config         MEDIUMTEXT,      -- JSON, see disc_config()
    vinyl_hex           VARCHAR(16),     -- "#rrggbb", see disc_colours()
    vinyl_translucent   TINYINT,         -- 1, 0, or NULL for "as the colour text says"
    case_kind           VARCHAR(16),     -- 'cd' draws a DVD in a CD jewel case
    sale_price          DOUBLE,          -- blank means not priced yet
    sale_currency       VARCHAR(8),
    ebay_url            TEXT,
    extra_photos_json   MEDIUMTEXT,      -- JSON array of URLs
    sold_at             DATETIME NULL,
    sale_condition      VARCHAR(16),     -- 'new' | 'used'
    gallery_json        MEDIUMTEXT,      -- JSON array of URLs, NULL = show everything

    -- (VIRTUAL: MariaDB won't store a column computed from one a foreign key can set NULL)
    wantlist_release_id BIGINT AS (IF(source = 'wantlist', release_id, NULL)) VIRTUAL INVISIBLE,

    UNIQUE KEY idx_items_instance (instance_id),
    UNIQUE KEY idx_items_wantlist (wantlist_release_id),
    KEY idx_items_release (release_id),
    KEY idx_items_artist (artist_id),
    KEY idx_items_era (era_id),
    KEY idx_items_source (source),
    FOREIGN KEY (release_id) REFERENCES releases(discogs_id) ON DELETE SET NULL,
    FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE SET NULL,
    FOREIGN KEY (era_id) REFERENCES eras(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- One row per sync, so the admin can say what happened and when, and so a run
-- that dies halfway is visibly unfinished rather than silently lost.
CREATE TABLE IF NOT EXISTS sync_runs (
    id                  INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trigger_source      VARCHAR(32) NOT NULL DEFAULT 'admin',   -- admin | cron | cli
    status              VARCHAR(32) NOT NULL DEFAULT 'running', -- running | ok | partial | error
    phase               VARCHAR(32),                            -- collection | wantlist | details | covers | done
    started_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    finished_at         DATETIME NULL,
    collection_seen     INT NOT NULL DEFAULT 0,
    wantlist_seen       INT NOT NULL DEFAULT 0,
    items_added         INT NOT NULL DEFAULT 0,
    items_removed       INT NOT NULL DEFAULT 0,
    details_fetched     INT NOT NULL DEFAULT 0,
    details_pending     INT NOT NULL DEFAULT 0,
    api_calls           INT NOT NULL DEFAULT 0,
    message             TEXT,
    log                 MEDIUMTEXT,
    KEY idx_sync_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Failed sign-ins by address, so the wait after five wrong passwords can't be
-- skipped by dropping the session cookie. A successful sign-in clears its row.
CREATE TABLE IF NOT EXISTS login_failures (
    ip          VARCHAR(64) NOT NULL PRIMARY KEY,
    fails       INT NOT NULL DEFAULT 0,
    last_fail   BIGINT NOT NULL DEFAULT 0                -- unix time
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
