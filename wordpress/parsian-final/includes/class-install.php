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

        // Core business tables. These are the system-of-record tables used by
        // CRM, sales, finance, ledger and inventory; create them before add-column migrations.
        $coreTables = [
            "CREATE TABLE {$p}customers (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                customer_code varchar(80) NOT NULL DEFAULT '',
                display_name varchar(190) NOT NULL,
                phone varchar(40) NOT NULL DEFAULT '',
                province varchar(100) NOT NULL DEFAULT '',
                city varchar(100) NOT NULL DEFAULT '',
                address text NULL,
                credit_limit_toman bigint unsigned NOT NULL DEFAULT 0,
                notes text NULL,
                status varchar(24) NOT NULL DEFAULT 'active',
                created_by bigint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NULL,
                PRIMARY KEY  (id),
                KEY customer_code (customer_code),
                KEY customer_status (status),
                KEY customer_province (province),
                KEY customer_phone (phone)
            ) $charset;",
            "CREATE TABLE {$p}suppliers (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                supplier_code varchar(80) NOT NULL DEFAULT '',
                display_name varchar(190) NOT NULL,
                phone varchar(40) NOT NULL DEFAULT '',
                province varchar(100) NOT NULL DEFAULT '',
                address text NULL,
                notes text NULL,
                status varchar(24) NOT NULL DEFAULT 'active',
                created_by bigint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NULL,
                PRIMARY KEY  (id),
                KEY supplier_code (supplier_code),
                KEY supplier_status (status),
                KEY supplier_name (display_name)
            ) $charset;",
            "CREATE TABLE {$p}leads (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                display_name varchar(190) NOT NULL,
                phone varchar(40) NOT NULL DEFAULT '',
                province varchar(100) NOT NULL DEFAULT '',
                notes text NULL,
                owner_user_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'new',
                created_at datetime NOT NULL,
                updated_at datetime NULL,
                PRIMARY KEY  (id),
                KEY lead_owner_status (owner_user_id,status),
                KEY lead_created (created_at),
                KEY lead_phone (phone)
            ) $charset;",
            "CREATE TABLE {$p}products (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                sku varchar(80) NOT NULL DEFAULT '',
                name varchar(190) NOT NULL,
                unit varchar(24) NOT NULL DEFAULT 'عدد',
                unit_price_toman bigint unsigned NOT NULL DEFAULT 0,
                status varchar(24) NOT NULL DEFAULT 'active',
                created_by bigint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NULL,
                PRIMARY KEY  (id),
                KEY product_sku (sku),
                KEY product_status_name (status,name)
            ) $charset;",
            "CREATE TABLE {$p}proformas (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                document_number varchar(80) NOT NULL,
                customer_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'draft',
                currency varchar(8) NOT NULL DEFAULT 'IRR',
                total_toman bigint unsigned NOT NULL DEFAULT 0,
                due_date date NULL,
                notes text NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                approved_by bigint unsigned NOT NULL DEFAULT 0,
                approved_at datetime NULL,
                invoice_id bigint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY document_number (document_number),
                KEY proforma_customer_created (customer_id,created_at),
                KEY proforma_status_created (status,created_at),
                KEY proforma_creator (created_by)
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
                KEY proforma_items_parent (proforma_id,id),
                KEY proforma_items_product (product_id)
            ) $charset;",
            "CREATE TABLE {$p}invoices (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                invoice_number varchar(80) NOT NULL,
                proforma_id bigint unsigned NOT NULL,
                customer_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'posted',
                currency varchar(8) NOT NULL DEFAULT 'IRR',
                total_toman bigint unsigned NOT NULL DEFAULT 0,
                due_date date NULL,
                issued_by bigint unsigned NOT NULL,
                issued_at datetime NOT NULL,
                journal_entry_id bigint unsigned NOT NULL DEFAULT 0,
                cogs_toman bigint unsigned NOT NULL DEFAULT 0,
                cogs_journal_entry_id bigint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY invoice_number (invoice_number),
                UNIQUE KEY invoice_proforma (proforma_id),
                KEY invoice_customer_issued (customer_id,issued_at),
                KEY invoice_status_due (status,due_date)
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
                KEY invoice_items_parent (invoice_id,id),
                KEY invoice_items_product (product_id)
            ) $charset;",
            "CREATE TABLE {$p}payments (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                direction varchar(8) NOT NULL,
                amount_toman bigint unsigned NOT NULL,
                party_type varchar(24) NOT NULL,
                party_id bigint unsigned NOT NULL,
                method varchar(24) NOT NULL DEFAULT 'cash',
                reference varchar(190) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'pending_approval',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                journal_entry_id bigint unsigned NOT NULL DEFAULT 0,
                reversed_at datetime NULL,
                reversal_event_id bigint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY payment_party_status (party_type,party_id,status),
                KEY payment_created (created_at),
                KEY payment_method_status (method,status),
                KEY payment_journal (journal_entry_id)
            ) $charset;",
            "CREATE TABLE {$p}cheques (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                direction varchar(12) NOT NULL,
                amount_toman bigint unsigned NOT NULL,
                due_date date NOT NULL,
                bank_name varchar(120) NOT NULL DEFAULT '',
                cheque_number varchar(120) NOT NULL DEFAULT '',
                party_name varchar(190) NOT NULL DEFAULT '',
                party_type varchar(24) NOT NULL DEFAULT 'other',
                party_id bigint unsigned NOT NULL DEFAULT 0,
                payment_id bigint unsigned NOT NULL DEFAULT 0,
                status varchar(24) NOT NULL DEFAULT 'pending',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                cleared_at datetime NULL,
                last_event_id bigint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY cheque_due_status (due_date,status),
                KEY cheque_party (party_type,party_id),
                KEY cheque_payment (payment_id),
                KEY cheque_direction_status (direction,status)
            ) $charset;",
            "CREATE TABLE {$p}ledger_accounts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                account_code varchar(24) NOT NULL,
                account_name varchar(190) NOT NULL,
                account_type varchar(24) NOT NULL,
                parent_id bigint unsigned NOT NULL DEFAULT 0,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY account_code (account_code),
                KEY account_parent (parent_id),
                KEY account_active_type (is_active,account_type)
            ) $charset;",
            "CREATE TABLE {$p}journal_entries (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                entry_number varchar(80) NOT NULL,
                source_type varchar(60) NOT NULL,
                source_id bigint unsigned NOT NULL,
                memo varchar(190) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'posted',
                debit_total_toman bigint unsigned NOT NULL DEFAULT 0,
                credit_total_toman bigint unsigned NOT NULL DEFAULT 0,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY entry_number (entry_number),
                UNIQUE KEY journal_source (source_type,source_id),
                KEY journal_status_created (status,created_at)
            ) $charset;",
            "CREATE TABLE {$p}journal_lines (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                journal_entry_id bigint unsigned NOT NULL,
                account_id bigint unsigned NOT NULL,
                party_type varchar(24) NOT NULL DEFAULT '',
                party_id bigint unsigned NOT NULL DEFAULT 0,
                debit_toman bigint unsigned NOT NULL DEFAULT 0,
                credit_toman bigint unsigned NOT NULL DEFAULT 0,
                memo varchar(190) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY journal_line_entry (journal_entry_id,id),
                KEY journal_line_account (account_id),
                KEY journal_line_party (party_type,party_id)
            ) $charset;",
            "CREATE TABLE {$p}stock_movements (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                product_id bigint unsigned NOT NULL,
                quantity bigint unsigned NOT NULL,
                direction varchar(8) NOT NULL,
                reason varchar(190) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'pending_approval',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                unit_cost_toman bigint unsigned NOT NULL DEFAULT 0,
                supplier_id bigint unsigned NOT NULL DEFAULT 0,
                approved_by bigint unsigned NOT NULL DEFAULT 0,
                approved_at datetime NULL,
                ledger_entry_id bigint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY movement_product_created (product_id,created_at),
                KEY movement_status (status),
                KEY movement_supplier (supplier_id)
            ) $charset;",
            "CREATE TABLE {$p}stock_ledger (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                product_id bigint unsigned NOT NULL,
                source_type varchar(40) NOT NULL,
                source_id bigint unsigned NOT NULL,
                quantity_delta bigint NOT NULL,
                unit_cost_toman bigint unsigned NOT NULL DEFAULT 0,
                value_delta_toman bigint NOT NULL DEFAULT 0,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY stock_product_created (product_id,created_at),
                KEY stock_source (source_type,source_id)
            ) $charset;",
            "CREATE TABLE {$p}stock_reservations (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                reference_type varchar(40) NOT NULL,
                reference_id bigint unsigned NOT NULL,
                product_id bigint unsigned NOT NULL,
                quantity bigint unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'reserved',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                released_at datetime NULL,
                PRIMARY KEY  (id),
                KEY reservation_reference (reference_type,reference_id,product_id),
                KEY reservation_product_status (product_id,status)
            ) $charset;"
        ];
        foreach ($coreTables as $sql) { dbDelta($sql); }
        $defaultAccounts = [
            ['1100','حساب‌های دریافتنی','asset'],
            ['1200','صندوق','asset'],
            ['1210','بانک','asset'],
            ['1220','اسناد دریافتنی در جریان وصول','asset'],
            ['1300','موجودی کالا','asset'],
            ['2100','حساب‌های پرداختنی','liability'],
            ['2200','اسناد پرداختنی','liability'],
            ['3100','سرمایه','equity'],
            ['4100','فروش کالا','revenue'],
            ['5100','بهای تمام‌شده کالای فروش‌رفته','expense']
        ];
        foreach ($defaultAccounts as $account) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$p}ledger_accounts (account_code,account_name,account_type,is_active,created_at) VALUES (%s,%s,%s,1,%s)",
                $account[0],$account[1],$account[2],current_time('mysql',true)
            ));
        }

        $financeLifecycleTables = [
            "CREATE TABLE {$p}cheque_events (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                cheque_id bigint unsigned NOT NULL,
                from_status varchar(24) NOT NULL,
                to_status varchar(24) NOT NULL,
                event_note varchar(190) NOT NULL DEFAULT '',
                journal_entry_id bigint unsigned NOT NULL DEFAULT 0,
                actor_user_id bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY cheque_events (cheque_id,id),
                KEY journal_lookup (journal_entry_id)
            ) $charset;",
            "CREATE TABLE {$p}bank_statement_lines (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                bank_account_ref varchar(80) NOT NULL,
                external_line_id varchar(120) NOT NULL,
                transaction_date date NOT NULL,
                direction varchar(8) NOT NULL,
                amount_toman bigint unsigned NOT NULL,
                reference varchar(190) NOT NULL DEFAULT '',
                description varchar(190) NOT NULL DEFAULT '',
                matched_journal_entry_id bigint unsigned NOT NULL DEFAULT 0,
                match_status varchar(20) NOT NULL DEFAULT 'unmatched',
                matched_by bigint unsigned NOT NULL DEFAULT 0,
                matched_at datetime NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY external_unique (bank_account_ref,external_line_id),
                KEY match_status_date (match_status,transaction_date),
                KEY journal_match (matched_journal_entry_id)
            ) $charset;"
        ];
        foreach ($financeLifecycleTables as $sql) { dbDelta($sql); }
        $authTokenTable = "CREATE TABLE {$p}auth_tokens (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            token_hash char(64) NOT NULL,
            token_type varchar(12) NOT NULL,
            family_uuid char(36) NOT NULL,
            user_id bigint unsigned NOT NULL,
            device_uuid char(36) NOT NULL,
            expires_at datetime NOT NULL,
            revoked_at datetime NULL,
            created_at datetime NOT NULL,
            last_used_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_device (user_id,device_uuid),
            KEY family_lookup (family_uuid),
            KEY token_expiry (token_type,expires_at)
        ) $charset;";
        dbDelta($authTokenTable);
        $bankReconciliationTables = [
            "CREATE TABLE {$p}bank_accounts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                account_ref varchar(80) NOT NULL,
                bank_name varchar(120) NOT NULL DEFAULT '',
                account_title varchar(190) NOT NULL DEFAULT '',
                account_number_masked varchar(80) NOT NULL DEFAULT '',
                ledger_account_id bigint unsigned NOT NULL DEFAULT 0,
                currency varchar(8) NOT NULL DEFAULT 'IRR',
                opening_balance_toman bigint NOT NULL DEFAULT 0,
                opening_balance_date date NULL,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                created_by bigint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY account_ref (account_ref),
                KEY active_bank (is_active,bank_name)
            ) $charset;",
            "CREATE TABLE {$p}bank_import_batches (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                bank_account_id bigint unsigned NOT NULL,
                source_name varchar(190) NOT NULL DEFAULT '',
                file_sha256 char(64) NOT NULL DEFAULT '',
                row_count int unsigned NOT NULL DEFAULT 0,
                accepted_count int unsigned NOT NULL DEFAULT 0,
                duplicate_count int unsigned NOT NULL DEFAULT 0,
                rejected_count int unsigned NOT NULL DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'received',
                imported_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY account_created (bank_account_id,created_at),
                KEY file_hash (file_sha256)
            ) $charset;",
            "CREATE TABLE {$p}bank_reconciliation_matches (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                statement_line_id bigint unsigned NOT NULL,
                journal_entry_id bigint unsigned NOT NULL,
                matched_amount_toman bigint unsigned NOT NULL,
                match_group_uuid char(36) NOT NULL,
                matched_by bigint unsigned NOT NULL,
                matched_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY line_journal_once (statement_line_id,journal_entry_id),
                KEY match_group (match_group_uuid),
                KEY journal_entry (journal_entry_id)
            ) $charset;"
        ];
        foreach ($bankReconciliationTables as $sql) { dbDelta($sql); }
        $syncColumns = [
            'payload_sha256' => "ALTER TABLE {$p}sync_ops ADD COLUMN payload_sha256 char(64) NOT NULL DEFAULT ''"
        ];
        foreach ($syncColumns as $column => $sql) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}sync_ops LIKE %s", $column))) {
                $wpdb->query($sql);
            }
        }
        $bankLineColumns = [
            'bank_account_id' => "ALTER TABLE {$p}bank_statement_lines ADD COLUMN bank_account_id bigint unsigned NOT NULL DEFAULT 0",
            'import_batch_id' => "ALTER TABLE {$p}bank_statement_lines ADD COLUMN import_batch_id bigint unsigned NOT NULL DEFAULT 0"
        ];
        foreach ($bankLineColumns as $column => $sql) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}bank_statement_lines LIKE %s", $column))) {
                $wpdb->query($sql);
            }
        }
        $allocationColumns = [
            'status' => "ALTER TABLE {$p}payment_allocations ADD COLUMN status varchar(20) NOT NULL DEFAULT 'active'",
            'reversed_at' => "ALTER TABLE {$p}payment_allocations ADD COLUMN reversed_at datetime NULL",
            'reversal_event_id' => "ALTER TABLE {$p}payment_allocations ADD COLUMN reversal_event_id bigint unsigned NOT NULL DEFAULT 0"
        ];
        foreach ($allocationColumns as $column => $sql) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}payment_allocations LIKE %s", $column))) {
                $wpdb->query($sql);
            }
        }
        $paymentLifecycleColumns = [
            'reversed_at' => "ALTER TABLE {$p}payments ADD COLUMN reversed_at datetime NULL",
            'reversal_event_id' => "ALTER TABLE {$p}payments ADD COLUMN reversal_event_id bigint unsigned NOT NULL DEFAULT 0"
        ];
        foreach ($paymentLifecycleColumns as $column => $sql) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}payments LIKE %s", $column))) {
                $wpdb->query($sql);
            }
        }
        $chequeColumns = [
            'party_type' => "ALTER TABLE {$p}cheques ADD COLUMN party_type varchar(24) NOT NULL DEFAULT 'other'",
            'party_id' => "ALTER TABLE {$p}cheques ADD COLUMN party_id bigint unsigned NOT NULL DEFAULT 0",
            'payment_id' => "ALTER TABLE {$p}cheques ADD COLUMN payment_id bigint unsigned NOT NULL DEFAULT 0",
            'cleared_at' => "ALTER TABLE {$p}cheques ADD COLUMN cleared_at datetime NULL",
            'last_event_id' => "ALTER TABLE {$p}cheques ADD COLUMN last_event_id bigint unsigned NOT NULL DEFAULT 0"
        ];
        foreach ($chequeColumns as $column => $sql) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}cheques LIKE %s", $column))) {
                $wpdb->query($sql);
            }
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
        $proformaColumns = [
            'due_date' => "ALTER TABLE {$p}proformas ADD COLUMN due_date date NULL"
        ];
        foreach ($proformaColumns as $column => $sql) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}proformas LIKE %s", $column))) {
                $wpdb->query($sql);
            }
        }
        $invoiceColumns = [
            'due_date' => "ALTER TABLE {$p}invoices ADD COLUMN due_date date NULL",
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
