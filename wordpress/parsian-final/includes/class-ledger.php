<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Ledger {
    public static function account_id(string $code): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}parsian_ledger_accounts WHERE account_code=%s AND is_active=1",
            $code
        ));
    }
    /**
     * Insert one immutable, balanced journal entry inside the caller's transaction.
     * Each line: account_code, debit_toman, credit_toman, optional party_type/party_id/memo.
     */
    public static function create_entry(string $sourceType, int $sourceId, string $memo, array $lines, int $actor): int {
        global $wpdb;
        if ($sourceType === '' || $sourceId < 1 || count($lines) < 2 || count($lines) > 20) {
            return 0;
        }
        $table = $wpdb->prefix . 'parsian_journal_entries';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE source_type=%s AND source_id=%d",
            $sourceType, $sourceId
        ));
        if ($existing) { return (int) $existing; }
        $debit = 0; $credit = 0; $clean = [];
        foreach ($lines as $line) {
            $accountCode = sanitize_text_field($line['account_code'] ?? '');
            $d = filter_var($line['debit_toman'] ?? 0, FILTER_VALIDATE_INT);
            $c = filter_var($line['credit_toman'] ?? 0, FILTER_VALIDATE_INT);
            if ($d === false || $c === false || $d < 0 || $c < 0 || (($d > 0) === ($c > 0))) { return 0; }
            $accountId = self::account_id($accountCode);
            if (!$accountId || $d > 1000000000000 || $c > 1000000000000) { return 0; }
            if ($debit > PHP_INT_MAX - $d || $credit > PHP_INT_MAX - $c) { return 0; }
            $debit += $d; $credit += $c;
            $clean[] = [
                'account_id'=>$accountId,'party_type'=>sanitize_key($line['party_type'] ?? ''),
                'party_id'=>absint($line['party_id'] ?? 0),'debit_toman'=>$d,'credit_toman'=>$c,
                'memo'=>sanitize_text_field($line['memo'] ?? '')
            ];
        }
        if ($debit < 1 || $debit !== $credit) { return 0; }
        $now = current_time('mysql', true);
        $entryNumber = 'JE-' . gmdate('YmdHis') . '-' . wp_rand(1000,9999);
        $ok = $wpdb->insert($table, [
            'entry_number'=>$entryNumber,'source_type'=>$sourceType,'source_id'=>$sourceId,
            'memo'=>sanitize_text_field($memo),'status'=>'posted',
            'debit_total_toman'=>$debit,'credit_total_toman'=>$credit,
            'created_by'=>$actor,'created_at'=>$now
        ], ['%s','%s','%d','%s','%s','%d','%d','%d','%s']);
        if (!$ok) { return 0; }
        $entryId = (int) $wpdb->insert_id;
        foreach ($clean as $line) {
            $line['journal_entry_id'] = $entryId;
            $ok = $wpdb->insert($wpdb->prefix . 'parsian_journal_lines', $line,
                ['%d','%s','%d','%d','%d','%s','%d']);
            if (!$ok) { return 0; }
        }
        return $entryId;
    }
}
