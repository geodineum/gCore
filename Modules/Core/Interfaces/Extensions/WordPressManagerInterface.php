<?php
declare(strict_types=1);
/**
 * WordPressManager Interface
 *
 * Contract for WordPress-specific operations including safe database
 * cloning and PII scrubbing for GDPR compliance when switching
 * between DTAP environments.
 *
 * DATABASE SAFETY MODEL:
 * Production data is NEVER modified. When switching from production to
 * a non-production environment, the production database is cloned to a
 * separate database (e.g., example_com_staging_db). PII is scrubbed on
 * the clone only. wp-config.php is updated to point to the clone.
 * Switching back to production restores the original DB_NAME.
 *
 * BASE tier: Security/compliance features should always be available.
 * No gNode dependency: Operates on $wpdb and MySQL directly.
 *
 * @optional
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces\Extensions
 * @version     1.1.0
 * @since       3.0.0
 */

namespace gCore\Modules\Core\Interfaces\Extensions;

use gCore\Modules\Core\Interfaces\ModuleInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

/**
 * Interface WordPressManagerInterface
 *
 * Defines the contract for WordPress environment management,
 * safe database cloning, and PII scrubbing.
 */
interface WordPressManagerInterface extends ModuleInterface
{
    // =========================================================================
    // DATABASE CLONING
    // =========================================================================

    /**
     * Clone the current database to an environment-specific copy
     *
     * Creates {db_name}_{environment} as a full structural + data copy.
     * The production database is never modified.
     *
     * @param string $target_env Target environment name (staging, testing, etc.)
     * @param bool $drop_existing Drop existing clone if it exists (default: false)
     * @return array{cloned: bool, source_db: string, target_db: string, tables: int, errors: array}
     */
    public function cloneDatabase(string $target_env, bool $drop_existing = false): array;

    /**
     * Switch wp-config.php to use a different database
     *
     * Updates the DB_NAME constant in wp-config.php. Stores the original
     * production DB name in a wp_option so it can be restored later.
     *
     * @param string $target_db Target database name
     * @return array{switched: bool, previous_db: string, current_db: string, errors: array}
     */
    public function swapDatabase(string $target_db): array;

    /**
     * Get the original production database name
     *
     * Returns the DB name stored before the first environment switch.
     * If no switch has occurred, returns the current DB_NAME.
     *
     * @return string Production database name
     */
    public function getProductionDbName(): string;

    /**
     * Get the environment-specific database name
     *
     * @param string $environment DTAP environment
     * @return string Database name for that environment
     */
    public function getEnvironmentDbName(string $environment): string;

    // =========================================================================
    // PII SCRUBBING (operates on current database only)
    // =========================================================================

    /**
     * Scrub PII from the CURRENT database
     *
     * Replaces real user data with anonymized placeholders.
     * Targets: wp_users, wp_usermeta, wp_comments.
     *
     * SAFETY: Refuses to run on production. Intended to be called AFTER
     * cloneDatabase() + swapDatabase() have switched to a non-prod clone.
     *
     * @param bool $confirm Must be true to execute (safety guard)
     * @param array $options {
     *     @type bool   $preserve_admin    Preserve user ID 1 (default: true)
     *     @type array  $exclude_ids       User IDs to exclude from scrubbing
     *     @type bool   $scrub_comments    Scrub comment author data (default: true)
     *     @type bool   $scrub_woocommerce Scrub WooCommerce billing/shipping fields (default: true)
     * }
     * @return array{scrubbed: bool, users: int, meta: int, comments: int, errors: array}
     */
    public function scrubPII(bool $confirm = false, array $options = []): array;

    /**
     * Preview PII scrub without modifying data
     *
     * Returns row counts that would be affected in the CURRENT database.
     *
     * @return array{users: int, meta_fields: int, comments: int, safe: bool, environment: string}
     */
    public function getScrubPreview(): array;

    /**
     * Check if PII scrubbing is safe in the current environment
     *
     * Returns true only for non-production environments.
     *
     * @return bool True if current environment is NOT production
     */
    public function isScrubSafe(): bool;

    // =========================================================================
    // ENVIRONMENT INFO
    // =========================================================================

    /**
     * Get current WordPress environment info
     *
     * @return array{environment: string, wp_version: string, multisite: bool, site_url: string, db_name: string, production_db: string}
     */
    public function getEnvironmentInfo(): array;
}
