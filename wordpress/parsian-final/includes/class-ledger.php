<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Ledger {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/ledger/journals',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'journals'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
        register_rest_route('parsian-final/v1','/ledger/trial-balance',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'trial_balance'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
    }
    public function journals(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $page=max(1,absint($request->get_param('page')?:1));
        $perPage=min(100,max(1,absint($request->get_param('per_page')?:30)));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,entry_number,source_type,source_id,memo,debit_total_toman,credit_total_toman,created_by,created_at
             FROM {$wpdb->prefix}parsian_journal_entries ORDER BY id DESC LIMIT %d OFFSET %d",
            $perPage,($page-1)*$perPage
        ),ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[],'page'=>$page,'per_page'=>$perPage],200);
    }
    public function trial_balance(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $rows=$wpdb->get_results(
            "SELECT a.account_code,a.account_name,a.account_type,
                    COALESCE(SUM(l.debit_toman),0) AS debit_toman,
                    COALESCE(SUM(l.credit_toman),0) AS credit_toman
             FROM {$wpdb->prefix}parsian_ledger_accounts a
             LEFT JOIN {$wpdb->prefix}parsian_journal_lines l ON l.account_id=a.id
             LEFT JOIN {$wpdb->prefix}parsian_journal_entries e ON e.id=l.journal_entry_id AND e.status='posted'
             WHERE a.is_active=1
             GROUP BY a.id,a.account_code,a.account_name,a.account_type
             ORDER BY a.account_code ASC",ARRAY_A
        );
        $debit=0;$credit=0;
        foreach((array)$rows as $row){$debit+=(int)$row['debit_toman'];$credit+=(int)$row['credit_toman'];}
        return new WP_REST_Response(['accounts'=>$rows?:[],'total_debit_toman'=>$debit,'total_credit_toman'=>$credit,'balanced'=>$debit===$credit],200);
    }
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
