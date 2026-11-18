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
        $crmTables = [
            "CREATE TABLE {$p}customers (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                display_name varchar(190) NOT NULL,
                province varchar(100) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status_name (status,display_name)
            ) $charset;",
            "CREATE TABLE {$p}leads (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                display_name varchar(190) NOT NULL,
                phone varchar(40) NOT NULL DEFAULT '',
                province varchar(100) NOT NULL DEFAULT '',
                notes text NOT NULL,
                owner_user_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'new',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY owner_status (owner_user_id,status),
                KEY phone (phone)
            ) $charset;"
        ];
        foreach ($crmTables as $sql) { dbDelta($sql); }
        $domainTables = [
            "CREATE TABLE {$p}payments (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                direction varchar(8) NOT NULL,
                amount_toman bigint unsigned NOT NULL,
                party_type varchar(30) NOT NULL DEFAULT 'other',
                party_id bigint unsigned NOT NULL DEFAULT 0,
                method varchar(20) NOT NULL DEFAULT 'cash',
                reference varchar(190) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'pending_approval',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status_date (status,created_at),
                KEY party_lookup (party_type,party_id)
            ) $charset;",
            "CREATE TABLE {$p}cheques (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                direction varchar(12) NOT NULL,
                amount_toman bigint unsigned NOT NULL,
                due_date date NOT NULL,
                bank_name varchar(120) NOT NULL DEFAULT '',
                cheque_number varchar(100) NOT NULL DEFAULT '',
                party_name varchar(190) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'pending',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY due_status (due_date,status),
                KEY cheque_number (cheque_number)
            ) $charset;",
            "CREATE TABLE {$p}products (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                sku varchar(80) NOT NULL,
                name varchar(190) NOT NULL,
                unit varchar(30) NOT NULL DEFAULT 'عدد',
                status varchar(24) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY sku (sku),
                KEY product_name (name)
            ) $charset;",
            "CREATE TABLE {$p}stock_movements (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                product_id bigint unsigned NOT NULL,
                quantity bigint NOT NULL,
                direction varchar(8) NOT NULL,
                reason varchar(190) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'pending_approval',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY product_status (product_id,status),
                KEY created_at (created_at)
            ) $charset;"
        ];
        foreach ($domainTables as $sql) { dbDelta($sql); }
        update_option('parsian_final_schema_version', PARSIAN_FINAL_VERSION, false);
        Parsian_Final_Roles::register();
    }
    public static function deactivate(): void {
        // Intentionally retain business data, roles and schema on deactivation.
    }
}
