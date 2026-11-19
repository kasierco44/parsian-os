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
