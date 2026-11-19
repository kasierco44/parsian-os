<?php
/**
 * Controlled WordPress integration acceptance test.
 *
 * Run only on an isolated staging/test database:
 *   define('PARSIAN_ALLOW_FINANCE_TESTS', true);
 *   wp eval-file wordpress/parsian-final/tests/finance-ledger-acceptance.php
 *
 * This creates and removes synthetic ledger and invoice fixtures.
 */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Run this test through WP-CLI only.'); }
if (!defined('PARSIAN_ALLOW_FINANCE_TESTS') || PARSIAN_ALLOW_FINANCE_TESTS !== true) {
    throw new RuntimeException('Set PARSIAN_ALLOW_FINANCE_TESTS=true only on an isolated staging database.');
}
if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production') {
    throw new RuntimeException('Finance acceptance fixtures are blocked on production.');
}
global $wpdb;
$ledger = new Parsian_Final_Ledger();
$customerId = 970000000 + random_int(1, 900000);
$sourceIds = [];
$invoiceIds = [];
$assert = static function($condition, string $message): void {
    if (!$condition) { throw new RuntimeException('ASSERTION FAILED: '.$message); }
};
$createEntry = static function(int $sourceId, string $date, int $debit, int $credit) use ($wpdb, $customerId, &$sourceIds, $assert): int {
    $lines = $debit>0 ? [
        ['account_code'=>'1100','party_type'=>'customer','party_id'=>$customerId,'debit_toman'=>$debit,'credit_toman'=>0],
        ['account_code'=>'4100','party_type'=>'','party_id'=>0,'debit_toman'=>0,'credit_toman'=>$debit],
    ] : [
        ['account_code'=>'1100','party_type'=>'customer','party_id'=>$customerId,'debit_toman'=>0,'credit_toman'=>$credit],
        ['account_code'=>'1200','party_type'=>'','party_id'=>0,'debit_toman'=>$credit,'credit_toman'=>0],
    ];
    $id = Parsian_Final_Ledger::create_entry('qa_fixture', $sourceId, 'تست پذیرش دفتر تفصیلی', $lines, get_current_user_id());
    $assert($id>0, 'balanced fixture journal should be created');
    $wpdb->update($wpdb->prefix.'parsian_journal_entries',['created_at'=>$date.' 12:00:00'],['id'=>$id],['%s'],['%d']);
    $sourceIds[]=$sourceId;
    return $id;
};
try {
    $seed=random_int(100000000, 1900000000);
    $createEntry($seed+1,'2026-10-31',10000,0);
    $createEntry($seed+2,'2026-11-03',0,3000);
    $createEntry($seed+3,'2026-11-10',2000,0);

    $request=new WP_REST_Request('GET','/parsian-final/v1/ledger/party-statement');
    $request->set_query_params(['party_type'=>'customer','party_id'=>$customerId,'from_date'=>'2026-11-01','to_date'=>'2026-11-30','page'=>1,'per_page'=>50]);
    $statement=$ledger->party_statement($request);
    $data=$statement->get_data();
    $assert($statement->get_status()===200,'party statement returns HTTP 200');
    $assert((int)$data['opening_balance_toman']===10000,'opening balance equals 10,000');
    $assert((int)$data['period_debit_toman']===2000,'period debit equals 2,000');
    $assert((int)$data['period_credit_toman']===3000,'period credit equals 3,000');
    $assert((int)$data['closing_balance_toman']===9000,'closing balance equals 9,000');
    $assert(count($data['items'])===2,'period contains two journal lines');
    $assert((int)$data['items'][0]['running_balance_toman']===7000,'first running balance equals 7,000');
    $assert((int)$data['items'][1]['running_balance_toman']===9000,'last running balance equals 9,000');

    $dueDates=['2026-11-20','2026-11-10','2026-10-01','2026-08-25','2026-08-01'];
    foreach($dueDates as $index=>$due){
        $number='QA-'.wp_generate_password(12,false,false);
        $ok=$wpdb->insert($wpdb->prefix.'parsian_invoices',[
            'invoice_number'=>$number,'proforma_id'=>$seed+100+$index,'customer_id'=>$customerId,'status'=>'posted',
            'currency'=>'IRR','total_toman'=>1000,'due_date'=>$due,'journal_entry_id'=>0,
            'issued_by'=>get_current_user_id(),'issued_at'=>$due.' 09:00:00'
        ]);
        $assert((bool)$ok,'aging invoice fixture inserted');
        $invoiceIds[]=(int)$wpdb->insert_id;
    }
    $agingRequest=new WP_REST_Request('GET','/parsian-final/v1/ledger/receivables-aging');
    $agingRequest->set_query_params(['as_of_date'=>'2026-11-19']);
    $agingResponse=$ledger->receivables_aging($agingRequest);
    $aging=$agingResponse->get_data();
    $assert($agingResponse->get_status()===200,'receivables aging returns HTTP 200');
    $customerAging=null;
    foreach((array)$aging['customers'] as $customerRow){
        if((int)$customerRow['customer_id']===$customerId){$customerAging=$customerRow;break;}
    }
    $assert(is_array($customerAging),'aging report includes the synthetic customer');
    $assert((int)$customerAging['total_outstanding_toman']===5000,'synthetic customer outstanding equals 5,000');
    $expected=['not_due'=>1000,'1_30'=>1000,'31_60'=>1000,'61_90'=>1000,'over_90'=>1000];
    foreach($expected as $bucket=>$amount){
        $assert((int)$customerAging['buckets_toman'][$bucket]===$amount,'synthetic customer aging bucket '.$bucket.' equals '.$amount);
    }
    WP_CLI::success('Ledger acceptance passed: opening, period debit/credit, closing, running balance and all five aging buckets.');
} finally {
    if($invoiceIds){$ids=implode(',',array_map('absint',$invoiceIds));$wpdb->query("DELETE FROM {$wpdb->prefix}parsian_invoices WHERE id IN ({$ids})");}
    if($sourceIds){
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_journal_entries WHERE source_type=%s AND source_id IN (".implode(',',array_fill(0,count($sourceIds),'%d')).")",'qa_fixture',...$sourceIds));
        if($ids){$entryIds=implode(',',array_map('absint',$ids));$wpdb->query("DELETE FROM {$wpdb->prefix}parsian_journal_lines WHERE journal_entry_id IN ({$entryIds})");$wpdb->query("DELETE FROM {$wpdb->prefix}parsian_journal_entries WHERE id IN ({$entryIds})");}
    }
}
