<?php
/**
 * Staging-only schema acceptance check.
 * Run with: wp eval 'define("PARSIAN_ALLOW_FINANCE_TESTS", true); require WP_PLUGIN_DIR . "/parsian-final/tests/schema-acceptance.php";'
 */
if (!defined('WP_CLI') || !WP_CLI || !defined('PARSIAN_ALLOW_FINANCE_TESTS') || PARSIAN_ALLOW_FINANCE_TESTS !== true) {
    return;
}
if (wp_get_environment_type() === 'production') {
    WP_CLI::error('Schema acceptance tests are blocked on Production.');
}
global $wpdb;
$expected = [
    'customers','suppliers','leads','products','proformas','proforma_items','invoices','invoice_items',
    'payments','cheques','ledger_accounts','journal_entries','journal_lines','stock_movements',
    'stock_ledger','stock_reservations','events','devices','sync_ops','auth_tokens','cheque_events',
    'bank_statement_lines','bank_accounts','bank_import_batches','bank_reconciliation_matches','payment_allocations'
];
$failures = [];
foreach ($expected as $suffix) {
    $table = $wpdb->prefix . 'parsian_' . $suffix;
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($found !== $table) { $failures[] = 'missing table: ' . $suffix; }
}
$accounts = ['1100','1200','1210','1220','1300','2100','2200','3100','4100','5100'];
foreach ($accounts as $code) {
    $found = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}parsian_ledger_accounts WHERE account_code=%s AND is_active=1",
        $code
    ));
    if (!$found) { $failures[] = 'missing active ledger account: ' . $code; }
}
$requiredColumns = [
    'auth_tokens' => ['token_hash','token_type','family_uuid','user_id','device_uuid','expires_at','revoked_at'],
    'sync_ops' => ['operation_uuid','payload_sha256','status','result','processed_at'],
    'bank_statement_lines' => ['bank_account_id','import_batch_id','match_status'],
    'payments' => ['journal_entry_id','reversed_at','reversal_event_id'],
    'cheques' => ['payment_id','cleared_at','last_event_id'],
];
foreach ($requiredColumns as $suffix => $columns) {
    $table = $wpdb->prefix . 'parsian_' . $suffix;
    foreach ($columns as $column) {
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        if (!$found) { $failures[] = 'missing column: ' . $suffix . '.' . $column; }
    }
}
if ($failures) {
    foreach ($failures as $failure) { WP_CLI::warning($failure); }
    WP_CLI::error('Schema acceptance failed with ' . count($failures) . ' issue(s).');
}
WP_CLI::success('PARSIAN FINAL schema acceptance passed: ' . count($expected) . ' tables, ' . count($accounts) . ' seeded accounts and required migration columns.');
