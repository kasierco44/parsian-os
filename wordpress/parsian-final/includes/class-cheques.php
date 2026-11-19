<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Cheques {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/cheques/(?P<id>\\d+)/transition',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'transition'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_write');}
        ]);
        register_rest_route('parsian-final/v1','/cheques/(?P<id>\\d+)/link-payment',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'link_payment'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_write');}
        ]);
        register_rest_route('parsian-final/v1','/cheques/(?P<id>\\d+)/history',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'history'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
        register_rest_route('parsian-final/v1','/bank/statement-lines',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'create_statement_line'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_write');}
        ]);
        register_rest_route('parsian-final/v1','/bank/statement-lines',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'list_statement_lines'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
        register_rest_route('parsian-final/v1','/bank/statement-lines/(?P<id>\\d+)/match',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'match_statement_line'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_write');}
        ]);
        register_rest_route('parsian-final/v1','/bank/reconciliation-summary',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'reconciliation_summary'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_finance_read');}
        ]);
    }
    private function event(string $type,string $aggregate,int $id,array $payload): void {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'parsian_events',[
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>get_current_user_id(),'event_type'=>$type,
            'aggregate_type'=>$aggregate,'aggregate_id'=>$id,'payload'=>wp_json_encode($payload),
            'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%d','%s','%s']);
    }
    private function transition_allowed(string $direction,string $from,string $to): bool {
        $map=[
            'received'=>['pending'=>['deposited','returned'],'deposited'=>['cleared','bounced','returned']],
            'issued'=>['pending'=>['issued','cancelled'],'issued'=>['presented','cancelled'],'presented'=>['cleared','bounced']]
        ];
        return in_array($to,$map[$direction][$from]??[],true);
    }
    public function transition(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $id=absint($request['id']); $p=$request->get_json_params();
        $to=sanitize_key($p['to_status']??''); $note=sanitize_text_field($p['note']??'');
        if($to===''||strlen($note)>190)return new WP_REST_Response(['code'=>'invalid_transition'],400);
        $wpdb->query('START TRANSACTION');
        $table=$wpdb->prefix.'parsian_cheques';
        $cheque=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        if(!$cheque){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'cheque_not_found'],404);}
        $from=(string)$cheque['status']; $direction=(string)$cheque['direction'];
        if(!$this->transition_allowed($direction,$from,$to)){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invalid_cheque_state_transition','from'=>$from,'to'=>$to],409);
        }
        $partyType=(string)$cheque['party_type']; $partyId=(int)$cheque['party_id'];
        $amount=(int)$cheque['amount_toman']; $journalId=0;
        if(in_array($to,['cleared','bounced'],true)||($direction==='issued'&&$to==='cancelled')||($direction==='received'&&$to==='returned')){
            $paymentId=(int)$cheque['payment_id'];
            $payment=$paymentId>0?$wpdb->get_row($wpdb->prepare("SELECT id,status,method,amount_toman FROM {$wpdb->prefix}parsian_payments WHERE id=%d FOR UPDATE",$paymentId),ARRAY_A):null;
            if(!$payment||$payment['status']!=='posted'||$payment['method']!=='cheque'||(int)$payment['amount_toman']!==$amount){
                $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'posted_cheque_payment_link_required'],422);
            }
        }
        $lines=null;
        if($direction==='received'&&$from==='deposited'&&$to==='cleared'){
            $lines=[
                ['account_code'=>'1210','debit_toman'=>$amount,'credit_toman'=>0,'party_type'=>$partyType,'party_id'=>$partyId],
                ['account_code'=>'1220','debit_toman'=>0,'credit_toman'=>$amount,'party_type'=>$partyType,'party_id'=>$partyId]
            ];
        } elseif($direction==='received'&&$from==='deposited'&&$to==='bounced'){
            if($partyType!=='customer'||$partyId<1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'customer_mapping_required_for_bounce'],422);}
            $lines=[
                ['account_code'=>'1100','debit_toman'=>$amount,'credit_toman'=>0,'party_type'=>'customer','party_id'=>$partyId],
                ['account_code'=>'1220','debit_toman'=>0,'credit_toman'=>$amount,'party_type'=>'customer','party_id'=>$partyId]
            ];
        } elseif($direction==='issued'&&$from==='presented'&&$to==='cleared'){
            $lines=[
                ['account_code'=>'2200','debit_toman'=>$amount,'credit_toman'=>0,'party_type'=>$partyType,'party_id'=>$partyId],
                ['account_code'=>'1210','debit_toman'=>0,'credit_toman'=>$amount,'party_type'=>$partyType,'party_id'=>$partyId]
            ];
        } elseif($direction==='issued'&&(($from==='presented'&&$to==='bounced')||$to==='cancelled')){
            if($partyType!=='supplier'||$partyId<1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'supplier_mapping_required_for_bounce'],422);}
            $lines=[
                ['account_code'=>'2200','debit_toman'=>$amount,'credit_toman'=>0,'party_type'=>'supplier','party_id'=>$partyId],
                ['account_code'=>'2100','debit_toman'=>0,'credit_toman'=>$amount,'party_type'=>'supplier','party_id'=>$partyId]
            ];
        } elseif($to==='returned'&&$direction==='received'){
            if($partyType!=='customer'||$partyId<1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'customer_mapping_required_for_return'],422);}
            $lines=[
                ['account_code'=>'1100','debit_toman'=>$amount,'credit_toman'=>0,'party_type'=>'customer','party_id'=>$partyId],
                ['account_code'=>'1220','debit_toman'=>0,'credit_toman'=>$amount,'party_type'=>'customer','party_id'=>$partyId]
            ];
        }
        if(is_array($lines)){
            $eventWritten=$wpdb->insert($wpdb->prefix.'parsian_cheque_events',[
                'cheque_id'=>$id,'from_status'=>$from,'to_status'=>$to,'event_note'=>$note,
                'actor_user_id'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
            ],['%d','%s','%s','%s','%d','%s']);
            if(!$eventWritten){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'cheque_event_failed'],500);}
            $eventId=(int)$wpdb->insert_id;
            $journalId=Parsian_Final_Ledger::create_entry('cheque_transition',$eventId,'تغییر وضعیت چک '.$id.' از '.$from.' به '.$to,$lines,get_current_user_id());
            if(!$journalId){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'cheque_journal_failed'],500);}
            $wpdb->update($wpdb->prefix.'parsian_cheque_events',['journal_entry_id'=>$journalId],['id'=>(int)$eventId],['%d'],['%d']);
        } else {
            $eventWritten=$wpdb->insert($wpdb->prefix.'parsian_cheque_events',[
                'cheque_id'=>$id,'from_status'=>$from,'to_status'=>$to,'event_note'=>$note,
                'actor_user_id'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
            ],['%d','%s','%s','%s','%d','%s']);
            if(!$eventWritten){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'cheque_event_failed'],500);}
            $eventId=(int)$wpdb->insert_id;
        }
        $data=['status'=>$to,'last_event_id'=>(int)$eventId];
        if($to==='cleared')$data['cleared_at']=current_time('mysql',true);
        $updated=$wpdb->update($table,$data,['id'=>$id,'status'=>$from]);
        if($updated!==1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'cheque_state_conflict'],409);}
        if(in_array($to,['bounced','returned','cancelled'],true) && $journalId>0 && (int)$cheque['payment_id']>0){
            $paymentUpdate=$wpdb->update($wpdb->prefix.'parsian_payments',[
                'status'=>'reversed','reversed_at'=>current_time('mysql',true),'reversal_event_id'=>(int)$eventId
            ],['id'=>(int)$cheque['payment_id'],'status'=>'posted'],['%s','%s','%d'],['%d','%s']);
            if($paymentUpdate!==1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'linked_payment_reversal_conflict'],409);}
        }
        $this->event('finance.cheque.transitioned','cheque',$id,['from'=>$from,'to'=>$to,'journal_entry_id'=>$journalId,'event_id'=>(int)$eventId]);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['cheque_id'=>$id,'from'=>$from,'status'=>$to,'event_id'=>(int)$eventId,'journal_entry_id'=>$journalId],200);
    }
    public function link_payment(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $id=absint($request['id']); $p=$request->get_json_params(); $paymentId=absint($p['payment_id']??0);
        if($paymentId<1)return new WP_REST_Response(['code'=>'payment_id_required'],400);
        $wpdb->query('START TRANSACTION');
        $cheque=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_cheques WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        $payment=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_payments WHERE id=%d FOR UPDATE",$paymentId),ARRAY_A);
        if(!$cheque||!$payment){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'cheque_or_payment_not_found'],404);}
        $expected=$cheque['direction']==='received'?'in':'out';
        if($cheque['status']!=='pending'||(int)$cheque['payment_id']>0||
            $payment['status']!=='posted'||$payment['method']!=='cheque'||$payment['direction']!==$expected||
            (int)$payment['amount_toman']!==(int)$cheque['amount_toman']||
            $payment['party_type']!==$cheque['party_type']||(int)$payment['party_id']!==(int)$cheque['party_id']){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'payment_link_mismatch_or_cheque_not_pending'],409);
        }
        $ok=$wpdb->update($wpdb->prefix.'parsian_cheques',['payment_id'=>$paymentId],['id'=>$id,'status'=>'pending','payment_id'=>0],['%d'],['%d','%s','%d']);
        if($ok!==1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'payment_link_conflict'],409);}
        $this->event('finance.cheque.payment_linked','cheque',$id,['payment_id'=>$paymentId]);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['cheque_id'=>$id,'payment_id'=>$paymentId,'status'=>'linked'],200);
    }
    public function history(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $id=absint($request['id']);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,from_status,to_status,event_note,journal_entry_id,actor_user_id,created_at FROM {$wpdb->prefix}parsian_cheque_events WHERE cheque_id=%d ORDER BY id ASC",$id),ARRAY_A);
        return new WP_REST_Response(['cheque_id'=>$id,'events'=>$rows?:[]],200);
    }
    public function create_statement_line(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params(); if(!is_array($p))return new WP_REST_Response(['code'=>'invalid_statement_line'],400);
        $account=sanitize_text_field($p['bank_account_ref']??''); $external=sanitize_text_field($p['external_line_id']??'');
        $date=sanitize_text_field($p['transaction_date']??''); $direction=sanitize_key($p['direction']??'');
        $amount=filter_var($p['amount_toman']??null,FILTER_VALIDATE_INT);
        if($account===''||strlen($account)>80||$external===''||strlen($external)>120||
            !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))||
            !in_array($direction,['in','out'],true)||$amount===false||$amount<1||$amount>1000000000000)
            return new WP_REST_Response(['code'=>'invalid_statement_line'],400);
        global $wpdb;
        $ok=$wpdb->insert($wpdb->prefix.'parsian_bank_statement_lines',[
            'bank_account_ref'=>$account,'external_line_id'=>$external,'transaction_date'=>$date,'direction'=>$direction,
            'amount_toman'=>$amount,'reference'=>sanitize_text_field($p['reference']??''),
            'description'=>sanitize_text_field($p['description']??''),'match_status'=>'unmatched',
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
        ],['%s','%s','%s','%s','%d','%s','%s','%s','%d','%s']);
        if(!$ok)return new WP_REST_Response(['code'=>'statement_line_duplicate_or_write_failed'],409);
        return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'match_status'=>'unmatched'],201);
    }
    public function list_statement_lines(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $status=sanitize_key($request->get_param('status')??'');
        $where=''; $args=[];
        if(in_array($status,['unmatched','matched'],true)){$where=' WHERE match_status=%s';$args[]=$status;}
        $sql="SELECT id,bank_account_ref,external_line_id,transaction_date,direction,amount_toman,reference,description,matched_journal_entry_id,match_status,matched_at FROM {$wpdb->prefix}parsian_bank_statement_lines{$where} ORDER BY transaction_date DESC,id DESC LIMIT 250";
        $rows=$args?$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A):$wpdb->get_results($sql,ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[]],200);
    }
    public function match_statement_line(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $id=absint($request['id']); $p=$request->get_json_params(); $journalId=absint($p['journal_entry_id']??0);
        if($journalId<1)return new WP_REST_Response(['code'=>'journal_entry_required'],400);
        $wpdb->query('START TRANSACTION');
        $line=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_bank_statement_lines WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        $entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_journal_entries WHERE id=%d AND status='posted' FOR UPDATE",$journalId),ARRAY_A);
        if(!$line||!$entry){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'statement_line_or_journal_not_found'],404);}
        if($line['match_status']==='matched'){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'statement_line_already_matched'],409);}
        $already=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}parsian_bank_statement_lines WHERE matched_journal_entry_id=%d AND match_status='matched'",$journalId));
        if($already>0){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'journal_entry_already_reconciled'],409);}
        $bankAccountId=Parsian_Final_Ledger::account_id('1210');
        $bank=$wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(debit_toman),0) debit,COALESCE(SUM(credit_toman),0) credit
             FROM {$wpdb->prefix}parsian_journal_lines WHERE journal_entry_id=%d AND account_id=%d",
            $journalId,$bankAccountId
        ),ARRAY_A);
        $expectedDebit=$line['direction']==='in'?(int)$line['amount_toman']:0;
        $expectedCredit=$line['direction']==='out'?(int)$line['amount_toman']:0;
        if((int)$bank['debit']!==$expectedDebit||(int)$bank['credit']!==$expectedCredit){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'bank_amount_or_direction_mismatch','statement_amount_toman'=>(int)$line['amount_toman'],'journal_bank_debit_toman'=>(int)$bank['debit'],'journal_bank_credit_toman'=>(int)$bank['credit']],409);
        }
        $ok=$wpdb->update($wpdb->prefix.'parsian_bank_statement_lines',[
            'matched_journal_entry_id'=>$journalId,'match_status'=>'matched',
            'matched_by'=>get_current_user_id(),'matched_at'=>current_time('mysql',true)
        ],['id'=>$id,'match_status'=>'unmatched'],['%d','%s','%d','%s'],['%d','%s']);
        if($ok!==1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'reconciliation_conflict'],409);}
        $this->event('finance.bank_line.matched','bank_statement_line',$id,['journal_entry_id'=>$journalId]);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>'matched','statement_line_id'=>$id,'journal_entry_id'=>$journalId],200);
    }
    public function reconciliation_summary(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $row=$wpdb->get_row("SELECT COUNT(*) total_lines,SUM(CASE WHEN match_status='matched' THEN 1 ELSE 0 END) matched_lines,SUM(CASE WHEN match_status='unmatched' THEN 1 ELSE 0 END) unmatched_lines,SUM(CASE WHEN match_status='unmatched' AND direction='in' THEN amount_toman ELSE 0 END) unmatched_in_toman,SUM(CASE WHEN match_status='unmatched' AND direction='out' THEN amount_toman ELSE 0 END) unmatched_out_toman FROM {$wpdb->prefix}parsian_bank_statement_lines",ARRAY_A);
        return new WP_REST_Response(['summary'=>$row?:[],'matching_policy'=>'exact_amount_direction_single_journal_entry'],200);
    }
}
