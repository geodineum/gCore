<?php
declare(strict_types=1);
/**
 * WordPressManager - Database Cloning, PII Scrubbing & Environment Management
 *
 * DATABASE SAFETY MODEL:
 * Production data is NEVER modified by PII scrubbing. The flow is:
 *   1. cloneDatabase('staging')  -- mysqldump prod → CREATE staging copy
 *   2. swapDatabase(clone_name)  -- update DB_NAME in wp-config.php
 *   3. scrubPII(true)            -- anonymize the clone (never touches prod)
 * Switching back to production restores the original DB_NAME.
 *
 * BASE tier: Security/compliance should always be available.
 * No gNode dependency: Operates on $wpdb and MySQL directly.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\WordPressManager
 * @version     1.1.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Base\WordPressManager;

use gCore\Modules\Core\Interfaces\Extensions\WordPressManagerInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 6));
}

class WordPressManager implements WordPressManagerInterface
{
    use ManagerConfigTrait;

    private static $instance = null;
    private bool $initialized = false;
    private array $config = [];

    /** @var string Option key for storing original production DB name */
    private const OPTION_PROD_DB = 'gcube_production_db_name';

    private array $defaultConfig = [
        'preserve_admin' => true,
        'scrub_comments' => true,
        'scrub_woocommerce' => true,
    ];

    /** @var array WooCommerce billing/shipping meta keys to scrub */
    private const WOOCOMMERCE_META_KEYS = [
        'billing_first_name',
        'billing_last_name',
        'billing_company',
        'billing_address_1',
        'billing_address_2',
        'billing_city',
        'billing_postcode',
        'billing_state',
        'billing_country',
        'billing_email',
        'billing_phone',
        'shipping_first_name',
        'shipping_last_name',
        'shipping_company',
        'shipping_address_1',
        'shipping_address_2',
        'shipping_city',
        'shipping_postcode',
        'shipping_state',
        'shipping_country',
        'shipping_phone',
    ];

    /** @var array Core usermeta keys containing PII */
    private const PII_META_KEYS = [
        'first_name',
        'last_name',
        'nickname',
        'description',
    ];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        // Layered config: defaultConfig → ValKey (defaults + per-site) → $config arg
        $siteId = (string)($config['site_id'] ?? 'default');
        $valkeyConfig = [];
        $storage = $this->gcoreResolveStorage($config);
        if ($storage !== null) {
            $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'WordPressManager');
        }
        $this->config = array_merge($this->defaultConfig, $valkeyConfig, $config);
        $this->initialized = true;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);

        $storage = $this->gcoreResolveStorage($this->config);
        if ($storage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($storage, $siteId, 'WordPressManager', (string)$key, $value);
            }
        }
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'environment' => $this->getDetectedEnvironment(),
            'scrub_safe' => $this->isScrubSafe(),
            'db_name' => defined('DB_NAME') ? DB_NAME : 'unknown',
            'production_db' => $this->getProductionDbName(),
        ];
    }

    // =========================================================================
    // DATABASE CLONING
    // =========================================================================

    /**
     * {@inheritdoc}
     * @api
     */
    public function cloneDatabase(string $target_env, bool $drop_existing = false): array
    {
        $result = [
            'cloned' => false,
            'source_db' => '',
            'target_db' => '',
            'tables' => 0,
            'errors' => [],
        ];

        // GC-D2.03 (Commit 1.2.a): whitelist environment name BEFORE any
        // identifier interpolation. Every DB operation in this method uses
        // backtick identifier interpolation (CREATE/DROP DATABASE, SHOW
        // TABLES FROM, SHOW CREATE TABLE, INSERT INTO) which WP's prepare()
        // does NOT cover. An unvalidated $target_env reaches the SQL via
        // getEnvironmentDbName → $base . '_' . $environment . '_db'.
        if (!self::isValidDbIdentifierPart($target_env)) {
            $result['errors'][] = 'Invalid target environment: must match [a-z0-9_]{1,32}';
            return $result;
        }

        // Source is always the production database
        $source_db = $this->getProductionDbName();
        $target_db = $this->getEnvironmentDbName($target_env);
        $result['source_db'] = $source_db;
        $result['target_db'] = $target_db;

        // Defence in depth: the source DB itself comes from WP's DB_NAME
        // define OR a stored wp_option. A corrupted option or a hostile
        // wp-config.php commit would slip past $target_env validation.
        if (!self::isValidFullDbIdentifier($source_db) || !self::isValidFullDbIdentifier($target_db)) {
            $result['errors'][] = 'Refusing operation: db identifier contains non-whitelist chars';
            return $result;
        }

        if ($source_db === $target_db) {
            $result['errors'][] = "Source and target are the same database: {$source_db}";
            return $result;
        }

        global $wpdb;
        if (!$wpdb) {
            $result['errors'][] = 'WordPress database not available';
            return $result;
        }

        // Check if clone already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s",
            $target_db
        ));

        if ($existing) {
            if (!$drop_existing) {
                $result['errors'][] = "Target database '{$target_db}' already exists. Use drop_existing=true to replace.";
                return $result;
            }
            $wpdb->query("DROP DATABASE `{$target_db}`");
        }

        // Create target database
        $created = $wpdb->query(
            "CREATE DATABASE `{$target_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        if ($created === false) {
            $result['errors'][] = 'Failed to create target database: ' . $wpdb->last_error;
            return $result;
        }

        // Get all tables from source
        $tables = $wpdb->get_col("SHOW TABLES FROM `{$source_db}`");
        if (empty($tables)) {
            $result['errors'][] = "No tables found in source database: {$source_db}";
            return $result;
        }

        // Clone each table: structure + data
        $cloned_count = 0;
        foreach ($tables as $table) {
            // Create table structure
            $create_sql = $wpdb->get_var("SHOW CREATE TABLE `{$source_db}`.`{$table}`", 1);
            if (!$create_sql) {
                $result['errors'][] = "Failed to get CREATE TABLE for: {$table}";
                continue;
            }

            // Rewrite CREATE TABLE to target database
            $create_sql = str_replace(
                "CREATE TABLE `{$table}`",
                "CREATE TABLE `{$target_db}`.`{$table}`",
                $create_sql
            );

            $wpdb->query($create_sql);

            // Copy data
            $wpdb->query(
                "INSERT INTO `{$target_db}`.`{$table}` SELECT * FROM `{$source_db}`.`{$table}`"
            );

            $cloned_count++;
        }

        $result['tables'] = $cloned_count;
        $result['cloned'] = ($cloned_count === count($tables));

        if ($result['cloned']) {
            error_log("WordPressManager: Cloned database {$source_db} -> {$target_db} ({$cloned_count} tables)");
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     * @api
     */
    public function swapDatabase(string $target_db): array
    {
        $result = [
            'switched' => false,
            'previous_db' => defined('DB_NAME') ? DB_NAME : '',
            'current_db' => $target_db,
            'errors' => [],
        ];

        // GC-D2.13 (Commit 1.2.a): validate the full DB identifier BEFORE
        // touching wp-config.php. The regex-replace at L~270 interpolates
        // $target_db directly into the replacement string; an unescaped
        // quote or backslash would corrupt wp-config.php (at best) or
        // inject PHP after the define (at worst).
        if (!self::isValidFullDbIdentifier($target_db)) {
            $result['errors'][] = 'Invalid target_db: must match [A-Za-z0-9_]{1,64}';
            return $result;
        }

        if (!defined('ABSPATH')) {
            $result['errors'][] = 'ABSPATH not defined';
            return $result;
        }

        $wp_config_path = ABSPATH . 'wp-config.php';
        if (!file_exists($wp_config_path)) {
            $result['errors'][] = "wp-config.php not found at: {$wp_config_path}";
            return $result;
        }

        if (!is_writable($wp_config_path)) {
            $result['errors'][] = "wp-config.php is not writable: {$wp_config_path}";
            return $result;
        }

        // Store original production DB name (only on first swap)
        $this->ensureProductionDbStored();

        $current_db = defined('DB_NAME') ? DB_NAME : '';

        if ($current_db === $target_db) {
            $result['switched'] = true;
            return $result;
        }

        // Read wp-config.php
        $config_contents = file_get_contents($wp_config_path);
        if ($config_contents === false) {
            $result['errors'][] = 'Failed to read wp-config.php';
            return $result;
        }

        // Replace DB_NAME define
        $pattern = "/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/";
        $replacement = "define( 'DB_NAME', '{$target_db}' )";

        $new_contents = preg_replace($pattern, $replacement, $config_contents, 1, $count);

        if ($count === 0) {
            $result['errors'][] = 'Could not find DB_NAME define in wp-config.php';
            return $result;
        }

        // Write back
        if (file_put_contents($wp_config_path, $new_contents) === false) {
            $result['errors'][] = 'Failed to write wp-config.php';
            return $result;
        }

        $result['switched'] = true;
        error_log("WordPressManager: Swapped database {$current_db} -> {$target_db}");

        return $result;
    }

    /**
     * {@inheritdoc}
     * @api
     */
    public function getProductionDbName(): string
    {
        // Try to read from wp_options (stored on first swap)
        $stored = get_option(self::OPTION_PROD_DB);
        if ($stored) {
            return $stored;
        }

        // No swap has occurred yet, current DB is production
        return defined('DB_NAME') ? DB_NAME : '';
    }

    /**
     * {@inheritdoc}
     * @api
     */
    public function getEnvironmentDbName(string $environment): string
    {
        $prod_db = $this->getProductionDbName();

        if ($environment === 'production') {
            return $prod_db;
        }

        // Strip trailing _db if present, append _{env}_db
        $base = preg_replace('/_db$/', '', $prod_db);
        return $base . '_' . $environment . '_db';
    }

    /**
     * Validate an environment-name fragment that will be interpolated into
     * a backtick-quoted SQL identifier (GC-D2.03). Lowercase-alnum-underscore
     * is the safe set; 32 char cap keeps identifier length within MySQL's
     * 64-char ceiling after prefix/suffix concatenation.
     */
    private static function isValidDbIdentifierPart(string $s): bool
    {
        return (bool) preg_match('/^[a-z0-9_]{1,32}$/', $s);
    }

    /**
     * Validate a full DB identifier before interpolation into SQL or into
     * wp-config.php's DB_NAME define (GC-D2.13). Slightly wider than
     * isValidDbIdentifierPart — mixed-case allowed because WP install
     * defaults often include camelCase (e.g., `wpMySite`).
     */
    private static function isValidFullDbIdentifier(string $s): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $s);
    }

    /**
     * Store the current DB_NAME as the production DB (idempotent, first-write wins)
     */
    private function ensureProductionDbStored(): void
    {
        $existing = get_option(self::OPTION_PROD_DB);
        if (!$existing && defined('DB_NAME')) {
            // Only store if we're currently on the production database
            // (i.e., this is the first environment switch)
            update_option(self::OPTION_PROD_DB, DB_NAME, false);
        }
    }

    // =========================================================================
    // PII SCRUBBING
    // =========================================================================

    /**
     * {@inheritdoc}
     * @api
     */
    public function scrubPII(bool $confirm = false, array $options = []): array
    {
        $result = [
            'scrubbed' => false,
            'users' => 0,
            'meta' => 0,
            'comments' => 0,
            'errors' => [],
        ];

        if (!$confirm) {
            $result['errors'][] = 'Scrub requires explicit confirmation ($confirm = true)';
            return $result;
        }

        if (!$this->isScrubSafe()) {
            $result['errors'][] = 'PII scrubbing is only allowed in non-production environments. '
                . 'Current DB: ' . (defined('DB_NAME') ? DB_NAME : 'unknown')
                . ', Production DB: ' . $this->getProductionDbName();
            return $result;
        }

        global $wpdb;
        if (!$wpdb) {
            $result['errors'][] = 'WordPress database not available';
            return $result;
        }

        $options = array_merge($this->defaultConfig, $options);
        $preserve_admin = $options['preserve_admin'] ?? true;
        $exclude_ids = $options['exclude_ids'] ?? [];

        if ($preserve_admin && !in_array(1, $exclude_ids, true)) {
            $exclude_ids[] = 1;
        }

        // Build WHERE clause for user exclusion
        $where_exclude = '';
        $where_exclude_uid = '';
        $where_exclude_comment = '';
        if (!empty($exclude_ids)) {
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '%d'));
            $where_exclude = $wpdb->prepare(" AND ID NOT IN ({$placeholders})", ...$exclude_ids);
            $where_exclude_uid = $wpdb->prepare(" AND user_id NOT IN ({$placeholders})", ...$exclude_ids);
            $where_exclude_comment = $wpdb->prepare(" AND user_id NOT IN ({$placeholders})", ...$exclude_ids);
        }

        // 1. Scrub wp_users
        $users_updated = $wpdb->query(
            "UPDATE {$wpdb->users} SET
                user_email = CONCAT('user_', ID, '@scrubbed.local'),
                display_name = CONCAT('User ', ID),
                user_nicename = CONCAT('user-', ID)
            WHERE 1=1 {$where_exclude}"
        );

        if ($users_updated === false) {
            $result['errors'][] = 'Failed to scrub wp_users: ' . $wpdb->last_error;
        } else {
            $result['users'] = (int) $users_updated;
        }

        // 2. Scrub wp_usermeta (core PII fields)
        $meta_count = 0;
        foreach (self::PII_META_KEYS as $meta_key) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->usermeta} SET meta_value = CASE
                    WHEN meta_key = 'nickname' THEN CONCAT('user-', user_id)
                    ELSE ''
                END
                WHERE meta_key = %s {$where_exclude_uid}",
                $meta_key
            ));
            if ($updated !== false) {
                $meta_count += (int) $updated;
            }
        }

        // 3. Scrub WooCommerce billing/shipping fields
        if ($options['scrub_woocommerce'] ?? true) {
            foreach (self::WOOCOMMERCE_META_KEYS as $meta_key) {
                $scrub_value = '';
                if (strpos($meta_key, '_email') !== false) {
                    $scrub_value = 'scrubbed@scrubbed.local';
                } elseif (strpos($meta_key, '_phone') !== false) {
                    $scrub_value = '000-000-0000';
                }

                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->usermeta} SET meta_value = %s
                    WHERE meta_key = %s {$where_exclude_uid}",
                    $scrub_value,
                    $meta_key
                ));
                if ($updated !== false) {
                    $meta_count += (int) $updated;
                }
            }
        }

        $result['meta'] = $meta_count;

        // 4. Scrub wp_comments
        if ($options['scrub_comments'] ?? true) {
            $comments_updated = $wpdb->query(
                "UPDATE {$wpdb->comments} SET
                    comment_author = CONCAT('Commenter ', comment_ID),
                    comment_author_email = CONCAT('commenter_', comment_ID, '@scrubbed.local'),
                    comment_author_url = '',
                    comment_author_IP = '0.0.0.0'
                WHERE 1=1 {$where_exclude_comment}"
            );
            if ($comments_updated === false) {
                $result['errors'][] = 'Failed to scrub wp_comments: ' . $wpdb->last_error;
            } else {
                $result['comments'] = (int) $comments_updated;
            }
        }

        $result['scrubbed'] = empty($result['errors']);

        if ($result['scrubbed']) {
            error_log("WordPressManager: PII scrubbed on " . DB_NAME
                . " - users:{$result['users']}, meta:{$result['meta']}, comments:{$result['comments']}");
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     * @api
     */
    public function getScrubPreview(): array
    {
        global $wpdb;

        $env = $this->getDetectedEnvironment();

        if (!$wpdb) {
            return [
                'users' => 0,
                'meta_fields' => 0,
                'comments' => 0,
                'safe' => false,
                'environment' => $env,
            ];
        }

        // Count users (excluding admin)
        $user_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID > 1"
        );

        // Count PII meta fields
        $all_meta_keys = array_merge(self::PII_META_KEYS, self::WOOCOMMERCE_META_KEYS);
        $placeholders = implode(',', array_fill(0, count($all_meta_keys), '%s'));
        $meta_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders}) AND user_id > 1",
            ...$all_meta_keys
        ));

        // Count comments
        $comment_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments}"
        );

        return [
            'users' => $user_count,
            'meta_fields' => $meta_count,
            'comments' => $comment_count,
            'safe' => $this->isScrubSafe(),
            'environment' => $env,
        ];
    }

    /**
     * {@inheritdoc}
     * @api
     */
    public function isScrubSafe(): bool
    {
        return $this->getDetectedEnvironment() !== 'production';
    }

    // =========================================================================
    // ENVIRONMENT INFO
    // =========================================================================

    /**
     * {@inheritdoc}
     * @api
     */
    public function getEnvironmentInfo(): array
    {
        global $wp_version;

        return [
            'environment' => $this->getDetectedEnvironment(),
            'wp_version' => $wp_version ?? 'unknown',
            'multisite' => is_multisite(),
            'site_url' => function_exists('get_site_url') ? get_site_url() : '',
            'db_name' => defined('DB_NAME') ? DB_NAME : 'unknown',
            'production_db' => $this->getProductionDbName(),
        ];
    }

    /**
     * Detect the current DTAP environment
     *
     * @return string Environment name
     */
    private function getDetectedEnvironment(): string
    {
        // Check gCube config first
        if (class_exists('\\gCube\\gNodeConfigLoader')) {
            $env = \gCube\gNodeConfigLoader::getEnvironment();
            if ($env) {
                return $env;
            }
        }

        // Fall back to WP_ENVIRONMENT_TYPE
        if (defined('WP_ENVIRONMENT_TYPE')) {
            return WP_ENVIRONMENT_TYPE;
        }

        return 'production';
    }
}
