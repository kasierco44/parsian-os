<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Install {
    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'parsian_';
        $tables = [
            "CREATE TABLE {$p}events (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                event_uuid char(36) NOT NULL,
                actor_user_id bigint unsigned NOT NULL DEFAULT 0,
                event_type varchar(80) NOT NULL,
                aggregate_type varchar(60) NOT NULL,
                aggregate_id bigint unsigned NOT NULL DEFAULT 0,
                payload longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_uuid (event_uuid),
                KEY aggregate_lookup (aggregate_type,aggregate_id),
                KEY actor_created (actor_user_id,created_at)
            ) $charset;",
            "CREATE TABLE {$p}devices (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                device_uuid char(36) NOT NULL,
                user_id bigint unsigned NOT NULL,
                platform varchar(24) NOT NULL,
                label varchar(120) NOT NULL DEFAULT '',
                revoked_at datetime NULL,
                last_seen_at datetime NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY device_uuid (device_uuid),
                KEY user_revoked (user_id,revoked_at)
            ) $charset;",
            "CREATE TABLE {$p}sync_ops (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                operation_uuid char(36) NOT NULL,
                device_uuid char(36) NOT NULL,
                user_id bigint unsigned NOT NULL,
                operation_type varchar(80) NOT NULL,
                payload longtext NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'pending',
                result longtext NULL,
                created_at datetime NOT NULL,
                processed_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY operation_uuid (operation_uuid),
                KEY device_status (device_uuid,status),
                KEY user_created (user_id,created_at)
            ) $charset;"
        ];
        foreach ($tables as $sql) { dbDelta($sql); }
        update_option('parsian_final_schema_version', PARSIAN_FINAL_VERSION, false);
        Parsian_Final_Roles::register();
    }
    public static function deactivate(): void {
        // Intentionally retain business data, roles and schema on deactivation.
    }
}
