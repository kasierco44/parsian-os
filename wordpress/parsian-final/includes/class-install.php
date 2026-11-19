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
        $workflowTables = [
            "CREATE TABLE {$p}proformas (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                document_number varchar(40) NOT NULL,
                customer_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'draft',
                currency varchar(8) NOT NULL DEFAULT 'IRR',
                total_toman bigint unsigned NOT NULL DEFAULT 0,
                notes text NOT NULL,
                created_by bigint unsigned NOT NULL,
                approved_by bigint unsigned NOT NULL DEFAULT 0,
                approved_at datetime NULL,
                invoice_id bigint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY document_number (document_number),
                KEY customer_status (customer_id,status),
                KEY created_by (created_by,created_at)
            ) $charset;",
            "CREATE TABLE {$p}proforma_items (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                proforma_id bigint unsigned NOT NULL,
                product_id bigint unsigned NOT NULL,
                description varchar(190) NOT NULL DEFAULT '',
                quantity bigint unsigned NOT NULL,
                unit_price_toman bigint unsigned NOT NULL,
                line_total_toman bigint unsigned NOT NULL,
                PRIMARY KEY  (id),
                KEY proforma_id (proforma_id)
            ) $charset;",
            "CREATE TABLE {$p}invoices (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                invoice_number varchar(40) NOT NULL,
                proforma_id bigint unsigned NOT NULL,
                customer_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'posted',
                currency varchar(8) NOT NULL DEFAULT 'IRR',
                total_toman bigint unsigned NOT NULL,
                journal_entry_id bigint unsigned NOT NULL DEFAULT 0,
                issued_by bigint unsigned NOT NULL,
                issued_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY invoice_number (invoice_number),
                UNIQUE KEY proforma_id (proforma_id),
                KEY customer_issued (customer_id,issued_at)
            ) $charset;",
            "CREATE TABLE {$p}invoice_items (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                invoice_id bigint unsigned NOT NULL,
                product_id bigint unsigned NOT NULL,
                description varchar(190) NOT NULL DEFAULT '',
                quantity bigint unsigned NOT NULL,
                unit_price_toman bigint unsigned NOT NULL,
                line_total_toman bigint unsigned NOT NULL,
                PRIMARY KEY  (id),
                KEY invoice_id (invoice_id)
            ) $charset;",
            "CREATE TABLE {$p}ledger_accounts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                account_code varchar(20) NOT NULL,
                account_name varchar(190) NOT NULL,
                account_type varchar(20) NOT NULL,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY  (id),
                UNIQUE KEY account_code (account_code)
            ) $charset;",
            "CREATE TABLE {$p}journal_entries (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                entry_number varchar(40) NOT NULL,
                source_type varchar(30) NOT NULL,
                source_id bigint unsigned NOT NULL,
                memo varchar(255) NOT NULL DEFAULT '',
                status varchar(16) NOT NULL DEFAULT 'posted',
                debit_total_toman bigint unsigned NOT NULL,
                credit_total_toman bigint unsigned NOT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY entry_number (entry_number),
                UNIQUE KEY source_once (source_type,source_id),
                KEY created_at (created_at)
            ) $charset;",
            "CREATE TABLE {$p}journal_lines (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                journal_entry_id bigint unsigned NOT NULL,
                account_id bigint unsigned NOT NULL,
                party_type varchar(30) NOT NULL DEFAULT '',
                party_id bigint unsigned NOT NULL DEFAULT 0,
                debit_toman bigint unsigned NOT NULL DEFAULT 0,
                credit_toman bigint unsigned NOT NULL DEFAULT 0,
                memo varchar(190) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY entry_id (journal_entry_id),
                KEY account_party (account_id,party_type,party_id)
            ) $charset;"
        ];
        foreach ($workflowTables as $sql) { dbDelta($sql); }
        $defaultAccounts = [
            ['1100','حساب‌های دریافتنی تجاری','asset'],
            ['1200','صندوق','asset'],
            ['1210','بانک','asset'],
            ['1220','اسناد دریافتنی در جریان وصول','asset'],
            ['2100','حساب‌های پرداختنی تجاری','liability'],
            ['2200','اسناد پرداختنی','liability'],
            ['4100','فروش کالا','revenue'],
        ];
        foreach ($defaultAccounts as $account) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$p}ledger_accounts (account_code,account_name,account_type,is_active) VALUES (%s,%s,%s,1)",
                $account[0], $account[1], $account[2]
            ));
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}payments LIKE %s", 'journal_entry_id'))) {
            $wpdb->query("ALTER TABLE {$p}payments ADD COLUMN journal_entry_id bigint unsigned NOT NULL DEFAULT 0");
        }

        $inventoryTables = [
            "CREATE TABLE {$p}stock_ledger (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                product_id bigint unsigned NOT NULL,
                source_type varchar(30) NOT NULL,
                source_id bigint unsigned NOT NULL,
                quantity_delta bigint NOT NULL,
                unit_cost_toman bigint unsigned NOT NULL DEFAULT 0,
                value_delta_toman bigint NOT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_once (source_type,source_id,product_id),
                KEY product_created (product_id,created_at)
            ) $charset;",
            "CREATE TABLE {$p}stock_reservations (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                reference_type varchar(24) NOT NULL,
                reference_id bigint unsigned NOT NULL,
                product_id bigint unsigned NOT NULL,
                quantity bigint unsigned NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'reserved',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                released_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY reference_product (reference_type,reference_id,product_id),
                KEY product_status (product_id,status),
                KEY reference_lookup (reference_type,reference_id)
            ) $charset;"
        ];
        foreach ($inventoryTables as $sql) { dbDelta($sql); }
        $movementColumns = [
            'unit_cost_toman' => "ALTER TABLE {$p}stock_movements ADD COLUMN unit_cost_toman bigint unsigned NOT NULL DEFAULT 0",
            'supplier_id' => "ALTER TABLE {$p}stock_movements ADD COLUMN supplier_id bigint unsigned NOT NULL DEFAULT 0",
            'approved_by' => "ALTER TABLE {$p}stock_movements ADD COLUMN approved_by bigint unsigned NOT NULL DEFAULT 0",
            'approved_at' => "ALTER TABLE {$p}stock_movements ADD COLUMN approved_at datetime NULL",
            'ledger_entry_id' => "ALTER TABLE {$p}stock_movements ADD COLUMN ledger_entry_id bigint unsigned NOT NULL DEFAULT 0"
        ];
        foreach ($movementColumns as $column => $sql) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}stock_movements LIKE %s", $column))) {
                $wpdb->query($sql);
            }
        }
        $inventoryAccounts = [
            ['1300','موجودی کالا','asset'],
            ['5100','بهای تمام‌شده کالای فروش‌رفته','expense']
        ];
        foreach ($inventoryAccounts as $account) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$p}ledger_accounts (account_code,account_name,account_type,is_active) VALUES (%s,%s,%s,1)",
                $account[0], $account[1], $account[2]
            ));
        }

        $allocationTable = "CREATE TABLE {$p}payment_allocations (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            payment_id bigint unsigned NOT NULL,
            invoice_id bigint unsigned NOT NULL,
            customer_id bigint unsigned NOT NULL,
            amount_toman bigint unsigned NOT NULL,
            allocated_by bigint unsigned NOT NULL,
            allocated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY payment_invoice (payment_id,invoice_id),
            KEY invoice_lookup (invoice_id,customer_id),
            KEY customer_allocated (customer_id,allocated_at)
        ) $charset;";
        dbDelta($allocationTable);
        $invoiceColumns = [
            'cogs_toman' => "ALTER TABLE {$p}invoices ADD COLUMN cogs_toman bigint unsigned NOT NULL DEFAULT 0",
            'cogs_journal_entry_id' => "ALTER TABLE {$p}invoices ADD COLUMN cogs_journal_entry_id bigint unsigned NOT NULL DEFAULT 0"
        ];
        foreach ($invoiceColumns as $column => $sql) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}invoices LIKE %s", $column))) {
                $wpdb->query($sql);
            }
        }

        update_option('parsian_final_schema_version', PARSIAN_FINAL_VERSION, false);
        Parsian_Final_Roles::register();
    }
    public static function maybe_upgrade(): void {
        if (get_option('parsian_final_schema_version', 'not-installed') !== PARSIAN_FINAL_VERSION) {
            self::activate();
        }
    }
    public static function deactivate(): void {
        // Intentionally retain business data, roles and schema on deactivation.
    }
}
