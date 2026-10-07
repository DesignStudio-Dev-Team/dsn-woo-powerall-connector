<?php
namespace DSNWooPowerall;

/**
 * Local email -> Powerall relation index.
 *
 * Powerall's /relations endpoint has no email filter, so checking whether a
 * customer already exists means reading every relation. This class keeps a
 * local copy of (relation ID, email) pairs so checkout only needs one quick
 * Powerall request to pick up recent changes before looking the email up.
 *
 * - Full load: one GET to relations/export.JSON (every relation, ~30k rows).
 *   Runs the first time, and weekly as a safety net for deleted relations.
 * - Incremental: GET relations?orderBy=LastModified:desc&offset=N&limit=200,
 *   newest changes first, stopping at the first relation that is older than
 *   what the previous run already saw. Usually a single request.
 *
 * Only GET requests are made to Powerall from this class.
 */
class Relation_Cache {
    private const DB_VERSION = '1';
    private const DB_VERSION_OPTION = 'dsn_woo_powerall_relation_cache_db_version';
    private const STATE_OPTION = 'dsn_woo_powerall_relation_cache_state';
    private const FULL_SYNC_LOCK_OPTION = '_dsn_powerall_relation_cache_full_sync_lock';
    public const CRON_HOOK = 'dsn_woo_powerall_relation_cache_sync';

    // Powerall rejects limit values above 200.
    private const PAGE_SIZE = 200;
    // If this many incremental pages are all newer than the cursor, a full load is cheaper.
    private const MAX_INCREMENTAL_PAGES = 25;
    // Re-read a little before the cursor in case Powerall commits a change late.
    private const CURSOR_OVERLAP_SECONDS = 600;
    private const FULL_SYNC_MAX_AGE = WEEK_IN_SECONDS;

    /**
     * @var API_Handler
     */
    private $api_handler;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @param API_Handler $api_handler
     */
    public function __construct(API_Handler $api_handler) {
        $this->api_handler = $api_handler;
        $this->logger = new Logger();
    }

    /**
     * Daily cron entry point: incremental refresh, or a full load when the
     * cache was never built or the last full load is older than a week.
     *
     * @return array|\WP_Error Sync summary or error
     */
    public function run_daily_sync() {
        $state = $this->get_state();
        $full_synced_at = (int) ($state['full_synced_at'] ?? 0);

        if ($full_synced_at < time() - self::FULL_SYNC_MAX_AGE) {
            return $this->full_sync();
        }

        return $this->incremental_sync();
    }

    /**
     * Bring the cache up to date. Used right before a checkout lookup.
     *
     * @return array|\WP_Error Sync summary or error
     */
    public function refresh() {
        if (!$this->is_ready()) {
            $this->logger->warning('Powerall relation cache has never been fully loaded. Running a full load now.');
            return $this->full_sync();
        }

        return $this->incremental_sync();
    }

    /**
     * Whether a full load has completed at least once.
     *
     * @return bool
     */
    public function is_ready() {
        $state = $this->get_state();
        return !empty($state['full_synced_at']) && $this->table_exists($this->table_name());
    }

    /**
     * Find the relation for an email address.
     *
     * When several relations share the email, active ones win, then the oldest
     * (lowest RelationCode), so repeat customers keep landing on the same one.
     *
     * @param string $email Email address
     * @return array|null Relation with Id, RelationCode and EmailAddress, or null
     */
    public function find_by_email($email) {
        global $wpdb;

        $email = self::normalize_email($email);
        if ($email === '') {
            return null;
        }

        $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT relation_id, relation_code, email FROM {$table} WHERE email = %s ORDER BY active DESC, relation_code ASC LIMIT 1",
            $email
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return array(
            'Id' => $row['relation_id'],
            'RelationCode' => (int) $row['relation_code'],
            'EmailAddress' => $row['email'],
        );
    }

    /**
     * Store a relation, replacing whatever was cached for its ID.
     *
     * @param array $relation Powerall relation (needs Id; EmailAddress optional)
     * @return void
     */
    public function store_relation($relation) {
        global $wpdb;

        if (empty($relation['Id'])) {
            return;
        }

        $this->maybe_install_table();
        $table = $this->table_name();
        $wpdb->delete($table, array('relation_id' => (string) $relation['Id']), array('%s'));
        $this->insert_rows($table, $this->relation_to_rows($relation));
    }

    /**
     * Load every relation from relations/export.JSON into a staging table and
     * swap it in, so lookups never see a half-filled table.
     *
     * @return array|\WP_Error Sync summary or error
     */
    public function full_sync() {
        global $wpdb;

        $lock = $this->acquire_full_sync_lock();
        if (is_wp_error($lock)) {
            return $lock;
        }

        $started = microtime(true);

        try {
            $this->maybe_install_table();
            wp_raise_memory_limit('cron');

            $this->logger->info('Powerall relation cache: starting full load from relations/export.JSON');
            $response = $this->api_handler->get('relations/export.JSON');
            if (is_wp_error($response)) {
                $this->logger->error('Powerall relation cache: full load failed: ' . $response->get_error_message());
                return $response;
            }

            if (!is_array($response) || !isset($response['Data']) || !is_array($response['Data']) || empty($response['Data'])) {
                $error = new \WP_Error('invalid_relation_export', __('Powerall returned an empty or invalid relation export.', 'dsn-woo-powerall'));
                $this->logger->error('Powerall relation cache: ' . $error->get_error_message());
                return $error;
            }

            $table = $this->table_name();
            $staging = $table . '_staging';
            $old = $table . '_old';

            $wpdb->query("DROP TABLE IF EXISTS {$staging}");
            $wpdb->query("DROP TABLE IF EXISTS {$old}");
            $wpdb->query("CREATE TABLE {$staging} LIKE {$table}");

            $relation_count = 0;
            $cursor = '';
            $rows = array();
            foreach ($response['Data'] as $relation) {
                if (!is_array($relation) || empty($relation['Id'])) {
                    continue;
                }

                $relation_count++;
                $cursor = max($cursor, self::normalize_last_modified($relation['LastModified'] ?? ''));
                foreach ($this->relation_to_rows($relation) as $row) {
                    $rows[] = $row;
                }

                if (count($rows) >= 500) {
                    $this->insert_rows($staging, $rows);
                    $rows = array();
                }
            }
            $this->insert_rows($staging, $rows);
            unset($response, $rows);

            $email_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$staging}");

            // RENAME TABLE swaps both names in one atomic step.
            $wpdb->query("RENAME TABLE {$table} TO {$old}, {$staging} TO {$table}");
            $wpdb->query("DROP TABLE IF EXISTS {$old}");

            $now = time();
            $summary = array(
                'mode' => 'full',
                'relations' => $relation_count,
                'email_rows' => $email_rows,
                'cursor' => $cursor,
                'duration_seconds' => round(microtime(true) - $started, 1),
            );

            $this->update_state(array(
                'full_synced_at' => $now,
                'synced_at' => $now,
                'cursor' => $cursor,
                'last_result' => $summary,
                'last_error' => '',
            ));

            $this->logger->info('Powerall relation cache: full load finished. ' . wp_json_encode($summary));
            return $summary;
        } finally {
            $this->release_full_sync_lock($lock);
        }
    }

    /**
     * Pull relations changed since the last run, newest first, and stop as soon
     * as a page reaches relations older than the stored cursor.
     *
     * @return array|\WP_Error Sync summary or error
     */
    public function incremental_sync() {
        $state = $this->get_state();
        $cursor = (string) ($state['cursor'] ?? '');

        if (!$this->is_ready() || $cursor === '') {
            return $this->full_sync();
        }

        $started = microtime(true);
        $threshold = gmdate('Y-m-d\TH:i:s', strtotime($cursor . ' UTC') - self::CURSOR_OVERLAP_SECONDS);
        $new_cursor = $cursor;
        $updated = 0;
        $requests = 0;
        $reached_known = false;

        for ($page = 0; $page < self::MAX_INCREMENTAL_PAGES; $page++) {
            $response = $this->api_handler->get('relations', array(
                'orderBy' => 'LastModified:desc',
                'offset' => $page * self::PAGE_SIZE,
                'limit' => self::PAGE_SIZE,
            ));
            $requests++;

            if (is_wp_error($response)) {
                $this->record_error('Incremental sync failed: ' . $response->get_error_message());
                return $response;
            }

            if (!is_array($response) || !array_key_exists('Data', $response) || !is_array($response['Data'])) {
                $error = new \WP_Error('invalid_relation_page', __('Powerall returned an invalid relations page.', 'dsn-woo-powerall'));
                $this->record_error($error->get_error_message() . ' Offset: ' . ($page * self::PAGE_SIZE));
                return $error;
            }

            foreach ($response['Data'] as $relation) {
                $last_modified = self::normalize_last_modified($relation['LastModified'] ?? '');

                // Relations without LastModified sort last and have not changed since the full load.
                if ($last_modified === '' || $last_modified < $threshold) {
                    $reached_known = true;
                    break;
                }

                $this->store_relation($relation);
                $updated++;
                $new_cursor = max($new_cursor, $last_modified);
            }

            if ($reached_known || count($response['Data']) < self::PAGE_SIZE) {
                $reached_known = true;
                break;
            }
        }

        if (!$reached_known) {
            $this->logger->warning(sprintf(
                'Powerall relation cache: more than %d relations changed since %s. Running a full load instead.',
                self::MAX_INCREMENTAL_PAGES * self::PAGE_SIZE,
                $cursor
            ));
            return $this->full_sync();
        }

        $summary = array(
            'mode' => 'incremental',
            'relations_updated' => $updated,
            'requests' => $requests,
            'cursor' => $new_cursor,
            'duration_seconds' => round(microtime(true) - $started, 1),
        );

        $this->update_state(array(
            'synced_at' => time(),
            'cursor' => $new_cursor,
            'last_result' => $summary,
            'last_error' => '',
        ));

        $this->logger->info('Powerall relation cache: incremental sync finished. ' . wp_json_encode($summary));
        return $summary;
    }

    /**
     * Current sync state (timestamps, cursor, last result).
     *
     * @return array
     */
    public function get_state() {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Create or upgrade the cache table.
     *
     * @return void
     */
    public function maybe_install_table() {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION && $this->table_exists($this->table_name())) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->table_name();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  relation_id varchar(36) NOT NULL,
  email varchar(191) NOT NULL,
  relation_code bigint(20) unsigned NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  last_modified varchar(19) NOT NULL DEFAULT '',
  PRIMARY KEY  (relation_id,email),
  KEY email (email)
) {$charset_collate};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Normalize an email for case-insensitive matching.
     *
     * @param string $email Email address
     * @return string
     */
    public static function normalize_email($email) {
        $email = trim((string) $email);

        return function_exists('mb_strtolower')
            ? mb_strtolower($email, 'UTF-8')
            : strtolower($email);
    }

    /**
     * Powerall timestamps look like 2026-10-06T15:15:30; keep them sortable as strings.
     *
     * @param mixed $value LastModified value
     * @return string
     */
    private static function normalize_last_modified($value) {
        return is_string($value) ? substr(trim($value), 0, 19) : '';
    }

    /**
     * One row per email address. Some relations hold several addresses
     * separated by ";" or ",", and each of them should match.
     *
     * @param array $relation Powerall relation
     * @return array
     */
    private function relation_to_rows($relation) {
        $emails = preg_split('/[;,\s]+/', (string) ($relation['EmailAddress'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $rows = array();

        foreach ($emails as $email) {
            $email = self::normalize_email($email);
            if ($email === '' || strpos($email, '@') === false) {
                continue;
            }

            $rows[$email] = array(
                (string) $relation['Id'],
                substr($email, 0, 191),
                (int) ($relation['RelationCode'] ?? 0),
                isset($relation['Active']) && !$relation['Active'] ? 0 : 1,
                self::normalize_last_modified($relation['LastModified'] ?? ''),
            );
        }

        return array_values($rows);
    }

    /**
     * Multi-row insert.
     *
     * @param string $table Table name
     * @param array $rows Rows from relation_to_rows()
     * @return void
     */
    private function insert_rows($table, $rows) {
        global $wpdb;

        if (empty($rows)) {
            return;
        }

        $placeholders = array();
        $values = array();
        foreach ($rows as $row) {
            $placeholders[] = '(%s, %s, %d, %d, %s)';
            array_push($values, ...$row);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (relation_id, email, relation_code, active, last_modified) VALUES " . implode(', ', $placeholders),
            $values
        ));
    }

    /**
     * @return string
     */
    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dsn_powerall_relations';
    }

    /**
     * @param string $table Table name
     * @return bool
     */
    private function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * @param array $changes State keys to set
     * @return void
     */
    private function update_state($changes) {
        update_option(self::STATE_OPTION, array_merge($this->get_state(), $changes), false);
    }

    /**
     * @param string $message Error message
     * @return void
     */
    private function record_error($message) {
        $this->logger->error('Powerall relation cache: ' . $message);
        $this->update_state(array('last_error' => $message, 'last_error_at' => time()));
    }

    /**
     * Only one full load at a time. The lock expires so a crashed run cannot block forever.
     *
     * @return string|\WP_Error Lock token or error
     */
    private function acquire_full_sync_lock() {
        $token = wp_generate_uuid4();
        $expires_at = time() + 10 * MINUTE_IN_SECONDS;

        if (add_option(self::FULL_SYNC_LOCK_OPTION, $token . ':' . $expires_at, '', 'no')) {
            return $token;
        }

        $current = (string) get_option(self::FULL_SYNC_LOCK_OPTION, '');
        if ((int) substr((string) strrchr($current, ':'), 1) < time()) {
            delete_option(self::FULL_SYNC_LOCK_OPTION);
            if (add_option(self::FULL_SYNC_LOCK_OPTION, $token . ':' . $expires_at, '', 'no')) {
                return $token;
            }
        }

        return new \WP_Error(
            'relation_cache_sync_in_progress',
            __('The Powerall customer list is being loaded. Please try again in a few minutes.', 'dsn-woo-powerall')
        );
    }

    /**
     * @param string $token Lock token
     * @return void
     */
    private function release_full_sync_lock($token) {
        $current = (string) get_option(self::FULL_SYNC_LOCK_OPTION, '');
        if (str_starts_with($current, $token . ':')) {
            delete_option(self::FULL_SYNC_LOCK_OPTION);
        }
    }
}
