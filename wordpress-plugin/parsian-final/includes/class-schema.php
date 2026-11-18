<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates only plugin-owned tables. Deactivation/uninstall never drops business data.
 */
final class Parsian_Final_Schema
{
    const VERSION = '1';

    public static function install()
    {
        global $wpdb;

        $installed = get_option('parsian_final_schema_version', '0');
        if (version_compare($installed, self::VERSION, '>=')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $audit_table = $wpdb->prefix . 'parsian_final_audit';
        $sync_table = $wpdb->prefix . 'parsian_final_sync_operations';

        $sql_audit = "CREATE TABLE {$audit_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action_key VARCHAR(100) NOT NULL,
            object_type VARCHAR(80) NOT NULL DEFAULT '',
            object_id VARCHAR(100) NOT NULL DEFAULT '',
            outcome VARCHAR(20) NOT NULL DEFAULT 'success',
            details LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY actor_user_id (actor_user_id),
            KEY action_key (action_key),
            KEY created_at (created_at)
        ) {$charset};";

        $sql_sync = "CREATE TABLE {$sync_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operation_id CHAR(36) NOT NULL,
            device_id VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            resource_type VARCHAR(80) NOT NULL,
            operation_type VARCHAR(30) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            result LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY operation_id (operation_id),
            KEY device_id (device_id),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset};";

        dbDelta($sql_audit);
        dbDelta($sql_sync);
        update_option('parsian_final_schema_version', self::VERSION, false);
    }
}
