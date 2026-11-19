<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Ledger {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/ledger/journals',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'journals'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
        register_rest_route('parsian-final/v1','/ledger/party-statement',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'party_statement'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
        register_rest_route('parsian-final/v1','/ledger/trial-balance',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'trial_balance'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
        register_rest_route('parsian-final/v1','/ledger/receivables-aging',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'receivables_aging'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
    }
    private function valid_date($value): bool {
        if (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$value)) return false;
        return checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4));
    }
    public function journals(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $page=max(1,absint($request->get_param('page')?:1));
        $perPage=min(100,max(1,absint($request->get_param('per_page')?:30)));
        $offset=($page-1)*$perPage;
        $where="status='posted'";
        $args=[];
        $from=$request->get_param('from_date'); $to=$request->get_param('to_date');
        if($from!==null&&$from!==''&&!$this->valid_date($from))return new WP_REST_Response(['code'=>'invalid_from_date'],400);
        if($to!==null&&$to!==''&&!$this->valid_date($to))return new WP_REST_Response(['code'=>'invalid_to_date'],400);
        if($from){$where.=' AND created_at >= %s';$args[]=$from.' 00:00:00';}
        if($to){$where.=' AND created_at <= %s';$args[]=$to.' 23:59:59';}
        $countSql="SELECT COUNT(*) FROM {$wpdb->prefix}parsian_journal_entries WHERE {$where}";
        $total=(int)($args?$wpdb->get_var($wpdb->prepare($countSql,...$args)):$wpdb->get_var($countSql));
        $sql="SELECT id,entry_number,source_type,source_id,memo,debit_total_toman,credit_total_toman,created_by,created_at
              FROM {$wpdb->prefix}parsian_journal_entries WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $queryArgs=array_merge($args,[$perPage,$offset]);
        $rows=$wpdb->get_results($wpdb->prepare($sql,...$queryArgs),ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[],'page'=>$page,'per_page'=>$perPage,'total_items'=>$total,'total_pages'=>(int)ceil($total/$perPage)],200);
    }
    public function trial_balance(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $rows=$wpdb->get_results(
            "SELECT a.account_code,a.account_name,a.account_type,
                    COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit_toman ELSE 0 END),0) AS debit_toman,
                    COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit_toman ELSE 0 END),0) AS credit_toman
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
    public static function create_entry(string $sourceType, int $sourceId, string $memo, array $lines, int $actor): int {
        global $wpdb;
        if ($sourceType === '' || $sourceId < 1 || count($lines) < 2 || count($lines) > 20) return 0;
        $table = $wpdb->prefix . 'parsian_journal_entries';
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE source_type=%s AND source_id=%d",$sourceType,$sourceId));
        if ($existing) return (int) $existing;
        $debit=0;$credit=0;$clean=[];
        foreach($lines as $line){
            $accountCode=sanitize_text_field($line['account_code']??'');
            $d=filter_var($line['debit_toman']??0,FILTER_VALIDATE_INT);
            $c=filter_var($line['credit_toman']??0,FILTER_VALIDATE_INT);
            if($d===false||$c===false||$d<0||$c<0||(($d>0)===($c>0)))return 0;
            $accountId=self::account_id($accountCode);
            if(!$accountId||$d>1000000000000||$c>1000000000000)return 0;
            if($debit>PHP_INT_MAX-$d||$credit>PHP_INT_MAX-$c)return 0;
            $debit+=$d;$credit+=$c;
            $clean[]=['account_id'=>$accountId,'party_type'=>sanitize_key($line['party_type']??''),'party_id'=>absint($line['party_id']??0),'debit_toman'=>$d,'credit_toman'=>$c,'memo'=>sanitize_text_field($line['memo']??'')];
        }
        if($debit<1||$debit!==$credit)return 0;
        $now=current_time('mysql',true);
        $entryNumber='JE-'.gmdate('YmdHis').'-'.wp_rand(1000,9999);
        $ok=$wpdb->insert($table,['entry_number'=>$entryNumber,'source_type'=>$sourceType,'source_id'=>$sourceId,'memo'=>sanitize_text_field($memo),'status'=>'posted','debit_total_toman'=>$debit,'credit_total_toman'=>$credit,'created_by'=>$actor,'created_at'=>$now],['%s','%s','%d','%s','%s','%d','%d','%d','%s']);
        if(!$ok)return 0;
        $entryId=(int)$wpdb->insert_id;
        foreach($clean as $line){
            $line['journal_entry_id']=$entryId;
            if(!$wpdb->insert($wpdb->prefix.'parsian_journal_lines',$line,['%d','%s','%d','%d','%d','%s','%d']))return 0;
        }
        return $entryId;
    }
    public function party_statement(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $partyType=sanitize_key($request->get_param('party_type')??'');
        $partyId=absint($request->get_param('party_id'));
        if(!in_array($partyType,['customer','supplier'],true)||$partyId<1)return new WP_REST_Response(['code'=>'invalid_party'],400);
        $from=$request->get_param('from_date');$to=$request->get_param('to_date');
        if($from!==null&&$from!==''&&!$this->valid_date($from))return new WP_REST_Response(['code'=>'invalid_from_date'],400);
        if($to!==null&&$to!==''&&!$this->valid_date($to))return new WP_REST_Response(['code'=>'invalid_to_date'],400);
        if($from&&$to&&$from>$to)return new WP_REST_Response(['code'=>'invalid_date_range'],400);
        $page=max(1,absint($request->get_param('page')?:1));
        $perPage=min(100,max(1,absint($request->get_param('per_page')?:50)));
        $offset=($page-1)*$perPage;
        $base=" FROM {$wpdb->prefix}parsian_journal_lines l
                INNER JOIN {$wpdb->prefix}parsian_journal_entries e ON e.id=l.journal_entry_id AND e.status='posted'
                INNER JOIN {$wpdb->prefix}parsian_ledger_accounts a ON a.id=l.account_id
                WHERE l.party_type=%s AND l.party_id=%d";
        $params=[$partyType,$partyId];
        $dateWhere='';
        if($from){$dateWhere.=' AND e.created_at >= %s';$params[]=$from.' 00:00:00';}
        if($to){$dateWhere.=' AND e.created_at <= %s';$params[]=$to.' 23:59:59';}
        $opening=0;
        if($from){
            $opening=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(l.debit_toman-l.credit_toman),0) {$base} AND e.created_at < %s",
                $partyType,$partyId,$from.' 00:00:00'
            ));
        }
        $totals=$wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(l.debit_toman),0) debit_toman,COALESCE(SUM(l.credit_toman),0) credit_toman {$base}{$dateWhere}",
            ...$params
        ),ARRAY_A);
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$base}{$dateWhere}",...$params));
        $sql="SELECT e.id AS journal_entry_id,l.id AS journal_line_id,e.entry_number,e.source_type,e.source_id,e.memo,e.created_at,
                     a.account_code,a.account_name,l.debit_toman,l.credit_toman,l.memo AS line_memo
              {$base}{$dateWhere} ORDER BY e.id ASC,l.id ASC LIMIT %d OFFSET %d";
        $rows=$wpdb->get_results($wpdb->prepare($sql,...array_merge($params,[$perPage,$offset])),ARRAY_A)?:[];
        $running=$opening;
        if($rows){
            $first=$rows[0];
            $prior=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(l.debit_toman-l.credit_toman),0) {$base}{$dateWhere}
                 AND (e.id < %d OR (e.id=%d AND l.id < %d))",
                ...array_merge($params,[(int)$first['journal_entry_id'],(int)$first['journal_entry_id'],(int)$first['journal_line_id']])
            ));
            $running+=$prior;
        }
        foreach($rows as &$row){
            $running+=(int)$row['debit_toman']-(int)$row['credit_toman'];
            $row['running_balance_toman']=$running;
        }unset($row);
        $periodDebit=(int)($totals['debit_toman']??0);$periodCredit=(int)($totals['credit_toman']??0);
        $response=[
            'party_type'=>$partyType,'party_id'=>$partyId,'from_date'=>$from?:null,'to_date'=>$to?:null,
            'opening_balance_toman'=>$opening,'period_debit_toman'=>$periodDebit,'period_credit_toman'=>$periodCredit,
            'closing_balance_toman'=>$opening+$periodDebit-$periodCredit,'items'=>$rows,
            'page'=>$page,'per_page'=>$perPage,'total_items'=>$count,'total_pages'=>(int)ceil($count/$perPage)
        ];
        if($partyType==='customer')$response['receivables_aging']=$this->aging_for_customer($partyId,$to?:current_time('Y-m-d'));
        return new WP_REST_Response($response,200);
    }
    private function aging_for_customer(int $customerId,string $asOf): array {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT i.id,i.invoice_number,i.issued_at,i.due_date,i.total_toman,
                    COALESCE(SUM(CASE WHEN pa.status='active' THEN pa.amount_toman ELSE 0 END),0) allocated_toman
             FROM {$wpdb->prefix}parsian_invoices i
             LEFT JOIN {$wpdb->prefix}parsian_payment_allocations pa ON pa.invoice_id=i.id
             WHERE i.customer_id=%d AND i.status IN ('posted','fulfilled')
             GROUP BY i.id,i.invoice_number,i.issued_at,i.due_date,i.total_toman
             HAVING i.total_toman-COALESCE(SUM(CASE WHEN pa.status='active' THEN pa.amount_toman ELSE 0 END),0)>0
             ORDER BY i.issued_at ASC,i.id ASC",
            $customerId
        ),ARRAY_A)?:[];
        $buckets=['not_due'=>0,'1_30'=>0,'31_60'=>0,'61_90'=>0,'over_90'=>0];$items=[];$total=0;
        $asOfTs=strtotime($asOf.' 00:00:00');
        foreach($rows as $row){
            $balance=max(0,(int)$row['total_toman']-(int)$row['allocated_toman']);
            $issued=substr((string)$row['issued_at'],0,10);
            $due=!empty($row['due_date'])?(string)$row['due_date']:$issued;
            $days=(int)floor(($asOfTs-strtotime($due.' 00:00:00'))/86400);
            $bucket=$days<=0?'not_due':($days<=30?'1_30':($days<=60?'31_60':($days<=90?'61_90':'over_90')));
            $buckets[$bucket]+=$balance;$total+=$balance;
            $items[]=['invoice_id'=>(int)$row['id'],'invoice_number'=>$row['invoice_number'],'issued_at'=>$row['issued_at'],'due_date'=>$row['due_date']?:null,'days_past_due'=>max(0,$days),'aging_bucket'=>$bucket,'balance_toman'=>$balance];
        }
        return ['as_of_date'=>$asOf,'basis'=>'days_past_due_using_due_date_fallback_to_issue_date','buckets_toman'=>$buckets,'total_outstanding_toman'=>$total,'invoices'=>$items];
    }
    public function receivables_aging(WP_REST_Request $request): WP_REST_Response {
        $asOf=$request->get_param('as_of_date')?:current_time('Y-m-d');
        if(!$this->valid_date($asOf))return new WP_REST_Response(['code'=>'invalid_as_of_date'],400);
        global $wpdb;
        $customers=$wpdb->get_col("SELECT DISTINCT customer_id FROM {$wpdb->prefix}parsian_invoices WHERE status IN ('posted','fulfilled') ORDER BY customer_id ASC");
        $result=[];$totals=['0_30'=>0,'31_60'=>0,'61_90'=>0,'over_90'=>0];$grand=0;
        foreach((array)$customers as $customerId){
            $aging=$this->aging_for_customer((int)$customerId,$asOf);
            if($aging['total_outstanding_toman']<1)continue;
            $result[]=['customer_id'=>(int)$customerId]+$aging;
            foreach($totals as $key=>$value)$totals[$key]+=$aging['buckets_toman'][$key];
            $grand+=$aging['total_outstanding_toman'];
        }
        return new WP_REST_Response(['as_of_date'=>$asOf,'basis'=>'days_since_invoice_issue_date_not_due_date','customers'=>$result,'totals_toman'=>$totals,'total_outstanding_toman'=>$grand],200);
    }
}
