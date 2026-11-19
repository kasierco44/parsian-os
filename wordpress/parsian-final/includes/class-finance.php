<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Finance {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1', '/payments', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'list_payments'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_read'); },
        ]);
        register_rest_route('parsian-final/v1', '/payments', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_payment'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_write'); },
        ]);
        register_rest_route('parsian-final/v1', '/payments/(?P<id>\\d+)/approve', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'approve_payment'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_write'); },
        ]);
        register_rest_route('parsian-final/v1', '/payments/(?P<id>\\d+)/allocations', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'allocate_payment'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_write'); },
        ]);
        register_rest_route('parsian-final/v1', '/invoices/(?P<id>\\d+)/balance', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'invoice_balance'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_read'); },
        ]);
        register_rest_route('parsian-final/v1', '/customers/(?P<id>\\d+)/outstanding', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'customer_outstanding'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_read'); },
        ]);
        register_rest_route('parsian-final/v1', '/cheques', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_cheque'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_write'); },
        ]);
        register_rest_route('parsian-final/v1', '/cheques', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'list_cheques'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_read'); },
        ]);
    }
    public function list_payments(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $page = max(1, absint($request->get_param('page') ?: 1));
        $perPage = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $offset = ($page - 1) * $perPage;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,direction,amount_toman,party_type,party_id,method,reference,status,created_by,created_at,updated_at,journal_entry_id
             FROM {$wpdb->prefix}parsian_payments
             ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d",
            $perPage, $offset
        ), ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['revision'] = hash('sha256', wp_json_encode([
                'id'=>(int)$row['id'],'direction'=>(string)$row['direction'],
                'amount_toman'=>(int)$row['amount_toman'],'party_type'=>(string)$row['party_type'],
                'party_id'=>(int)$row['party_id'],'method'=>(string)$row['method'],
                'reference'=>(string)$row['reference'],'status'=>(string)$row['status'],
                'created_by'=>(int)$row['created_by'],'created_at'=>(string)$row['created_at'],
                'updated_at'=>(string)($row['updated_at']??''),'journal_entry_id'=>(int)$row['journal_entry_id']
            ], JSON_UNESCAPED_UNICODE));
        }
        unset($row);
        return new WP_REST_Response(['items'=>$rows ?: [],'page'=>$page,'per_page'=>$perPage],200);
    }

    public function create_payment(WP_REST_Request $request): WP_REST_Response {
        $p = $request->get_json_params();
        if (!is_array($p)) { return new WP_REST_Response(['code' => 'invalid_payment'], 400); }
        $amount = isset($p['amount_toman']) ? filter_var($p['amount_toman'], FILTER_VALIDATE_INT) : false;
        $directions = ['in', 'out'];
        $methods = ['cash', 'transfer', 'cheque', 'card', 'other'];
        $party_types = ['customer', 'supplier', 'employee', 'other'];
        $direction = $p['direction'] ?? '';
        $method = $p['method'] ?? 'cash';
        $party_type = $p['party_type'] ?? 'other';
        if ($amount === false || $amount < 1 || $amount > 1000000000000 ||
            !in_array($direction, $directions, true) ||
            !in_array($method, $methods, true) ||
            !in_array($party_type, $party_types, true)) {
            return new WP_REST_Response(['code'=>'invalid_payment'], 400);
        }
        $partyId=absint($p['party_id']??0);
        if($partyId<1 || ($direction==='in' && $party_type!=='customer') || ($direction==='out' && $party_type!=='supplier')) {
            return new WP_REST_Response(['code'=>'invalid_party_mapping'],400);
        }
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix.'parsian_payments', [
            'direction'=>$direction, 'amount_toman'=>$amount,
            'party_type'=>$party_type,
            'party_id'=>$partyId,
            'method'=>$method,
            'reference'=>sanitize_text_field($p['reference'] ?? ''),
            'status'=>'pending_approval','created_by'=>get_current_user_id(),
            'created_at'=>current_time('mysql', true),'updated_at'=>current_time('mysql', true)
        ], ['%s','%d','%s','%d','%s','%s','%s','%d','%s']);
        if (!$ok) return new WP_REST_Response(['code'=>'payment_create_failed'], 500);
        $id = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix.'parsian_events', [
            'event_uuid'=>wp_generate_uuid4(), 'actor_user_id'=>get_current_user_id(),
            'event_type'=>'finance.payment.created', 'aggregate_type'=>'payment',
            'aggregate_id'=>$id, 'payload'=>wp_json_encode(['payment_id'=>$id,'status'=>'pending_approval']),
            'created_at'=>current_time('mysql', true)
        ], ['%s','%d','%s','%s','%d','%s','%s']);
        return new WP_REST_Response(['id'=>$id,'status'=>'pending_approval','posted'=>false], 202);
    }
    public function create_cheque(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params();
        if (!is_array($p)) { return new WP_REST_Response(['code'=>'invalid_cheque'], 400); }
        $amount=isset($p['amount_toman'])?filter_var($p['amount_toman'],FILTER_VALIDATE_INT):false;
        $date=sanitize_text_field($p['due_date']??'');
        $direction=sanitize_key($p['direction']??'');
        $partyType=sanitize_key($p['party_type']??'');
        $partyId=absint($p['party_id']??0);
        $paymentId=absint($p['payment_id']??0);
        if($amount===false || $amount<1 || $amount>1000000000000 ||
           !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date) ||
           !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)) ||
           !in_array($direction,['received','issued'],true) ||
           ($direction==='received'&&$partyType!=='customer') ||
           ($direction==='issued'&&$partyType!=='supplier') || $partyId<1) {
            return new WP_REST_Response(['code'=>'invalid_cheque_or_party_mapping'],400);
        }
        global $wpdb;
        if($paymentId>0){
            $payment=$wpdb->get_row($wpdb->prepare("SELECT id,direction,amount_toman,method,status,party_type,party_id FROM {$wpdb->prefix}parsian_payments WHERE id=%d",$paymentId),ARRAY_A);
            $expectedDirection=$direction==='received'?'in':'out';
            if(!$payment||$payment['direction']!==$expectedDirection||(int)$payment['amount_toman']!==$amount||
                $payment['method']!=='cheque'||$payment['status']!=='posted'||
                $payment['party_type']!==$partyType||(int)$payment['party_id']!==$partyId){
                return new WP_REST_Response(['code'=>'linked_payment_mismatch_or_not_posted'],409);
            }
        }
        $ok=$wpdb->insert($wpdb->prefix.'parsian_cheques',[
            'direction'=>$direction,'amount_toman'=>$amount,'due_date'=>$date,
            'bank_name'=>sanitize_text_field($p['bank_name']??''),
            'cheque_number'=>sanitize_text_field($p['cheque_number']??''),
            'party_name'=>sanitize_text_field($p['party_name']??''),
            'party_type'=>$partyType,'party_id'=>$partyId,'payment_id'=>$paymentId,
            'status'=>'pending','created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s']);
        if(!$ok)return new WP_REST_Response(['code'=>'cheque_create_failed'],500);
        $id=(int)$wpdb->insert_id;
        $wpdb->insert($wpdb->prefix.'parsian_events',[
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>get_current_user_id(),
            'event_type'=>'finance.cheque.created','aggregate_type'=>'cheque',
            'aggregate_id'=>$id,'payload'=>wp_json_encode(['cheque_id'=>$id,'status'=>'pending','payment_id'=>$paymentId]),
            'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%d','%s','%s']);
        return new WP_REST_Response(['id'=>$id,'status'=>'pending','payment_id'=>$paymentId],201);
    }
    public function list_cheques(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $page=max(1,absint($request->get_param('page')?:1));
        $per_page=min(100,max(1,absint($request->get_param('per_page')?:50)));
        $offset=($page-1)*$per_page;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,direction,amount_toman,due_date,bank_name,party_name,status
             FROM {$wpdb->prefix}parsian_cheques ORDER BY due_date ASC,id ASC LIMIT %d OFFSET %d",
            $per_page,$offset
        ),ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[],'page'=>$page,'per_page'=>$per_page],200);
    }
    public function approve_payment(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $id=absint($request['id']);
        $wpdb->query('START TRANSACTION');
        $table=$wpdb->prefix.'parsian_payments';
        $payment=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        if(!$payment){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'payment_not_found'],404);}
        if($payment['status']==='posted' && (int)$payment['journal_entry_id']>0){
            $journalId=(int)$payment['journal_entry_id'];$wpdb->query('COMMIT');
            return new WP_REST_Response(['status'=>'already_posted','journal_entry_id'=>$journalId],200);
        }
        if($payment['status']!=='pending_approval'){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'payment_not_approvable'],409);}
        $partyType=$payment['party_type'];$partyId=(int)$payment['party_id'];
        if(!in_array($partyType,['customer','supplier'],true)||$partyId<1){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'party_mapping_required'],422);
        }
        if($payment['direction']==='in' && $partyType!=='customer'){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'incoming_payment_requires_customer'],422);
        }
        if($payment['direction']==='out' && $partyType!=='supplier'){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'outgoing_payment_requires_supplier'],422);
        }
        $method=$payment['method'];
        if(!in_array($method,['cash','transfer','card','cheque'],true)){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'payment_method_not_mapped'],422);
        }
        $assetCode=$method==='cash'?'1200':($method==='cheque'?'1220':'1210');
        if($payment['direction']==='out' && $method==='cheque')$assetCode='2200';
        $amount=(int)$payment['amount_toman'];
        if($payment['direction']==='in'){
            $lines=[
                ['account_code'=>$assetCode,'debit_toman'=>$amount,'credit_toman'=>0,'party_type'=>$partyType,'party_id'=>$partyId],
                ['account_code'=>'1100','debit_toman'=>0,'credit_toman'=>$amount,'party_type'=>$partyType,'party_id'=>$partyId]
            ];
        } else {
            $lines=[
                ['account_code'=>'2100','debit_toman'=>$amount,'credit_toman'=>0,'party_type'=>$partyType,'party_id'=>$partyId],
                ['account_code'=>$assetCode,'debit_toman'=>0,'credit_toman'=>$amount,'party_type'=>$partyType,'party_id'=>$partyId]
            ];
        }
        $journalId=Parsian_Final_Ledger::create_entry('payment',$id,'ثبت دریافت/پرداخت شماره '.$id,$lines,get_current_user_id());
        if(!$journalId){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'journal_post_failed'],500);}
        $updated=$wpdb->update($table,['status'=>'posted','journal_entry_id'=>$journalId,'updated_at'=>current_time('mysql',true)],['id'=>$id,'status'=>'pending_approval'],['%s','%d','%s'],['%d','%s']);
        if($updated!==1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'payment_state_conflict'],409);}
        $wpdb->insert($wpdb->prefix.'parsian_events',[
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>get_current_user_id(),
            'event_type'=>'finance.payment.posted','aggregate_type'=>'payment','aggregate_id'=>$id,
            'payload'=>wp_json_encode(['payment_id'=>$id,'journal_entry_id'=>$journalId,'amount_toman'=>$amount]),
            'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%d','%s','%s']);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>'posted','payment_id'=>$id,'journal_entry_id'=>$journalId,'amount_toman'=>$amount],200);
    }
    public function allocate_payment(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $paymentId=absint($request['id']); $p=$request->get_json_params();
        $invoiceId=absint($p['invoice_id']??0);
        $amount=filter_var($p['amount_toman']??null,FILTER_VALIDATE_INT);
        if($invoiceId<1||$amount===false||$amount<1||$amount>1000000000000)
            return new WP_REST_Response(['code'=>'invalid_allocation'],400);
        $wpdb->query('START TRANSACTION');
        $payment=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_payments WHERE id=%d FOR UPDATE",$paymentId),ARRAY_A);
        $invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_invoices WHERE id=%d FOR UPDATE",$invoiceId),ARRAY_A);
        if(!$payment||!$invoice){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'payment_or_invoice_not_found'],404);}
        if($payment['status']!=='posted'||$payment['direction']!=='in'||$payment['party_type']!=='customer'||(int)$payment['party_id']!==(int)$invoice['customer_id']){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'payment_invoice_party_mismatch'],409);
        }
        if(!in_array($invoice['status'],['posted','fulfilled'],true)){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invoice_not_allocatable'],409);}
        $table=$wpdb->prefix.'parsian_payment_allocations';
        $paymentAllocated=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_toman),0) FROM {$table} WHERE payment_id=%d AND status='active'",$paymentId));
        $invoiceAllocated=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_toman),0) FROM {$table} WHERE invoice_id=%d AND status='active'",$invoiceId));
        $paymentRemaining=(int)$payment['amount_toman']-$paymentAllocated;
        $invoiceRemaining=(int)$invoice['total_toman']-$invoiceAllocated;
        if($amount>$paymentRemaining||$amount>$invoiceRemaining){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'allocation_exceeds_remaining','payment_remaining_toman'=>$paymentRemaining,'invoice_remaining_toman'=>$invoiceRemaining],409);}
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,amount_toman FROM {$table} WHERE payment_id=%d AND invoice_id=%d FOR UPDATE",$paymentId,$invoiceId),ARRAY_A);
        if($existing){
            $ok=$wpdb->update($table,['amount_toman'=>(int)$existing['amount_toman']+$amount,'allocated_by'=>get_current_user_id(),'allocated_at'=>current_time('mysql',true)],['id'=>(int)$existing['id']],['%d','%d','%s'],['%d']);
        }else{
            $ok=$wpdb->insert($table,['payment_id'=>$paymentId,'invoice_id'=>$invoiceId,'customer_id'=>(int)$invoice['customer_id'],'amount_toman'=>$amount,'allocated_by'=>get_current_user_id(),'allocated_at'=>current_time('mysql',true)],['%d','%d','%d','%d','%d','%s']);
        }
        if(!$ok){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'allocation_write_failed'],409);}
        $now = current_time('mysql',true);
        $wpdb->update($wpdb->prefix.'parsian_invoices',['updated_at'=>$now],['id'=>$invoiceId],['%s'],['%d']);
        $wpdb->update($wpdb->prefix.'parsian_payments',['updated_at'=>$now],['id'=>$paymentId],['%s'],['%d']);
        $newAllocated=$invoiceAllocated+$amount; $newBalance=(int)$invoice['total_toman']-$newAllocated;
        $wpdb->insert($wpdb->prefix.'parsian_events',['event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>get_current_user_id(),'event_type'=>'finance.payment.allocated','aggregate_type'=>'invoice','aggregate_id'=>$invoiceId,'payload'=>wp_json_encode(['payment_id'=>$paymentId,'amount_toman'=>$amount,'remaining_toman'=>$newBalance]),'created_at'=>current_time('mysql',true)],['%s','%d','%s','%s','%d','%s','%s']);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>$newBalance===0?'paid':'partially_paid','payment_id'=>$paymentId,'invoice_id'=>$invoiceId,'allocated_toman'=>$newAllocated,'balance_toman'=>$newBalance],201);
    }
    public function invoice_balance(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $id=absint($request['id']);
        $invoice=$wpdb->get_row($wpdb->prepare("SELECT id,invoice_number,customer_id,total_toman,status FROM {$wpdb->prefix}parsian_invoices WHERE id=%d",$id),ARRAY_A);
        if(!$invoice)return new WP_REST_Response(['code'=>'invoice_not_found'],404);
        $paid=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_toman),0) FROM {$wpdb->prefix}parsian_payment_allocations WHERE invoice_id=%d AND status='active'",$id));
        return new WP_REST_Response(['invoice'=>$invoice,'allocated_toman'=>$paid,'balance_toman'=>max(0,(int)$invoice['total_toman']-$paid),'payment_status'=>$paid<=0?'unpaid':($paid>=(int)$invoice['total_toman']?'paid':'partially_paid')],200);
    }
    public function customer_outstanding(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $customerId=absint($request['id']);
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT i.id,i.invoice_number,i.issued_at,i.total_toman,
                    COALESCE(a.allocated_toman,0) allocated_toman,
                    GREATEST(i.total_toman-COALESCE(a.allocated_toman,0),0) balance_toman
             FROM {$wpdb->prefix}parsian_invoices i
             LEFT JOIN (SELECT invoice_id,SUM(amount_toman) allocated_toman FROM {$wpdb->prefix}parsian_payment_allocations WHERE status='active' GROUP BY invoice_id) a ON a.invoice_id=i.id
             WHERE i.customer_id=%d AND i.status IN ('posted','fulfilled')
             ORDER BY i.issued_at ASC,i.id ASC",$customerId
        ),ARRAY_A)?:[];
        $total=0;foreach($rows as &$row){$row['balance_toman']=(int)$row['balance_toman'];$total+=$row['balance_toman'];}unset($row);
        return new WP_REST_Response(['customer_id'=>$customerId,'invoices'=>$rows,'total_outstanding_toman'=>$total],200);
    }
}
