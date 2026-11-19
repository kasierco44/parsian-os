<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Inventory {
    public function register_routes(): void {
        $read = static function(): bool { return is_user_logged_in() && current_user_can('parsian_inventory_read'); };
        $write = static function(): bool { return is_user_logged_in() && current_user_can('parsian_inventory_write'); };
        register_rest_route('parsian-final/v1','/inventory/items',['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'items'],'permission_callback'=>$read]);
        register_rest_route('parsian-final/v1','/inventory/movements',['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'movement'],'permission_callback'=>$write]);
        register_rest_route('parsian-final/v1','/inventory/movements/(?P<id>\\d+)/approve',['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'approve_movement'],'permission_callback'=>$write]);
        register_rest_route('parsian-final/v1','/inventory/reservations',['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'reserve'],'permission_callback'=>$write]);
        register_rest_route('parsian-final/v1','/inventory/reservations/(?P<id>\\d+)/release',['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'release_reservation'],'permission_callback'=>$write]);
        register_rest_route('parsian-final/v1','/inventory/invoices/(?P<id>\\d+)/fulfill',['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'fulfill_invoice'],'permission_callback'=>$write]);
        register_rest_route('parsian-final/v1','/inventory/valuation',['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'valuation'],'permission_callback'=>$read]);
    }
    private function event(string $type,string $aggregate,int $id,array $payload): void {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'parsian_events',[
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>get_current_user_id(),'event_type'=>$type,
            'aggregate_type'=>$aggregate,'aggregate_id'=>$id,'payload'=>wp_json_encode($payload),'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%d','%s','%s']);
    }
    private function stock(int $productId, bool $lock=false): array {
        global $wpdb;
        $ledger=$wpdb->prefix.'parsian_stock_ledger';
        $reservation=$wpdb->prefix.'parsian_stock_reservations';
        if($lock) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_products WHERE id=%d FOR UPDATE",$productId));
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity_delta),0) qty, COALESCE(SUM(value_delta_toman),0) value
             FROM {$ledger} WHERE product_id=%d",$productId
        ),ARRAY_A);
        $reserved=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity),0) FROM {$reservation} WHERE product_id=%d AND status='reserved'",$productId
        ));
        $qty=(int)($row['qty']??0); $value=(int)($row['value']??0);
        return ['on_hand'=>$qty,'reserved'=>$reserved,'available'=>$qty-$reserved,'inventory_value_toman'=>$value,
            'average_cost_toman'=>$qty>0?(int)floor($value/$qty):0];
    }
    public function items(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $page=max(1,absint($request->get_param('page')?:1)); $per=min(100,max(1,absint($request->get_param('per_page')?:50)));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,sku,name,unit,status FROM {$wpdb->prefix}parsian_products WHERE status='active' ORDER BY name ASC LIMIT %d OFFSET %d",
            $per,($page-1)*$per
        ),ARRAY_A)?:[];
        foreach($rows as &$row){$row['stock']=$this->stock((int)$row['id']);} unset($row);
        return new WP_REST_Response(['items'=>$rows,'page'=>$page,'per_page'=>$per],200);
    }
    public function movement(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params(); if(!is_array($p))return new WP_REST_Response(['code'=>'invalid_movement'],400);
        $qty=filter_var($p['quantity']??null,FILTER_VALIDATE_INT); $pid=absint($p['product_id']??0);
        $direction=sanitize_key($p['direction']??''); $cost=filter_var($p['unit_cost_toman']??0,FILTER_VALIDATE_INT);
        $supplier=absint($p['supplier_id']??0);
        if($qty===false||$qty<1||$qty>100000000||$pid<1||!in_array($direction,['in','out'],true)||$cost===false||$cost<0||$cost>1000000000000)
            return new WP_REST_Response(['code'=>'invalid_movement'],400);
        if($direction==='in'&&($cost<1||$supplier<1))return new WP_REST_Response(['code'=>'inbound_requires_cost_and_supplier'],400);
        if($direction==='out'&&$cost!==0)return new WP_REST_Response(['code'=>'outbound_cost_is_calculated'],400);
        global $wpdb;
        $product=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_products WHERE id=%d AND status='active'",$pid));
        if(!$product)return new WP_REST_Response(['code'=>'product_not_found'],404);
        $reason=sanitize_text_field($p['reason']??''); if(strlen($reason)>190)return new WP_REST_Response(['code'=>'reason_too_long'],400);
        $ok=$wpdb->insert($wpdb->prefix.'parsian_stock_movements',[
            'product_id'=>$pid,'quantity'=>$qty,'direction'=>$direction,'reason'=>$reason,'status'=>'pending_approval',
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'unit_cost_toman'=>$cost,'supplier_id'=>$supplier
        ],['%d','%d','%s','%s','%s','%d','%s','%d','%d']);
        if(!$ok)return new WP_REST_Response(['code'=>'movement_create_failed'],500);
        $id=(int)$wpdb->insert_id; $this->event('inventory.movement.requested','stock_movement',$id,['product_id'=>$pid,'quantity'=>$qty,'direction'=>$direction]);
        return new WP_REST_Response(['id'=>$id,'status'=>'pending_approval','stock_posted'=>false],202);
    }
    public function approve_movement(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $id=absint($request['id']); $table=$wpdb->prefix.'parsian_stock_movements';
        $wpdb->query('START TRANSACTION');
        $m=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        if(!$m){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'movement_not_found'],404);}
        if($m['status']==='approved'){$wpdb->query('COMMIT');return new WP_REST_Response(['status'=>'already_approved','ledger_entry_id'=>(int)$m['ledger_entry_id']],200);}
        if($m['status']!=='pending_approval'){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'movement_not_approvable'],409);}
        $pid=(int)$m['product_id']; $s=$this->stock($pid,true); $qty=(int)$m['quantity'];
        if($m['direction']==='out'&&$qty>$s['available']){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'insufficient_available_stock','available'=>$s['available']],409);}
        $unit=$m['direction']==='in'?(int)$m['unit_cost_toman']:$s['average_cost_toman'];
        if($m['direction']==='out'&&$unit<1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'missing_inventory_cost'],409);}
        $value=$qty*$unit; if($value>PHP_INT_MAX){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'value_overflow'],400);}
        $delta=$m['direction']==='in'?$qty:-$qty; $valueDelta=$m['direction']==='in'?$value:-$value;
        $ok=$wpdb->insert($wpdb->prefix.'parsian_stock_ledger',[
            'product_id'=>$pid,'source_type'=>'movement','source_id'=>$id,'quantity_delta'=>$delta,
            'unit_cost_toman'=>$unit,'value_delta_toman'=>$valueDelta,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
        ],['%d','%s','%d','%d','%d','%d','%d','%s']);
        if(!$ok){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'stock_ledger_write_failed'],500);}
        $journalLines = $m['direction']==='in' ? [
            ['account_code'=>'1300','debit_toman'=>$value,'credit_toman'=>0],
            ['account_code'=>'2100','debit_toman'=>0,'credit_toman'=>$value,'party_type'=>'supplier','party_id'=>(int)$m['supplier_id']]
        ] : [
            ['account_code'=>'5100','debit_toman'=>$value,'credit_toman'=>0],
            ['account_code'=>'1300','debit_toman'=>0,'credit_toman'=>$value]
        ];
        $journalId=Parsian_Final_Ledger::create_entry('stock_movement',$id,'ثبت گردش انبار '.$id,$journalLines,get_current_user_id());
        if(!$journalId){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'inventory_journal_failed'],500);}
        $updated=$wpdb->update($table,['status'=>'approved','approved_by'=>get_current_user_id(),'approved_at'=>current_time('mysql',true),'ledger_entry_id'=>$journalId],['id'=>$id,'status'=>'pending_approval'],['%s','%d','%s','%d'],['%d','%s']);
        if($updated!==1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'movement_state_conflict'],409);}
        $this->event('inventory.movement.posted','stock_movement',$id,['product_id'=>$pid,'quantity_delta'=>$delta,'unit_cost_toman'=>$unit,'value_delta_toman'=>$valueDelta,'journal_entry_id'=>$journalId]);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>'approved','quantity_delta'=>$delta,'unit_cost_toman'=>$unit,'value_delta_toman'=>$valueDelta,'stock'=>$this->stock($pid)],201);
    }
    public function reserve(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params(); $invoice=absint($p['invoice_id']??0); $pid=absint($p['product_id']??0);
        $qty=filter_var($p['quantity']??null,FILTER_VALIDATE_INT);
        if($invoice<1||$pid<1||$qty===false||$qty<1)return new WP_REST_Response(['code'=>'invalid_reservation'],400);
        global $wpdb; $wpdb->query('START TRANSACTION');
        $inv=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$wpdb->prefix}parsian_invoices WHERE id=%d FOR UPDATE",$invoice),ARRAY_A);
        if(!$inv||$inv['status']!=='posted'){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invoice_not_reservable'],404);}
        $itemQty=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}parsian_invoice_items WHERE invoice_id=%d AND product_id=%d",$invoice,$pid));
        if($itemQty<1||$qty>$itemQty){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'reservation_exceeds_invoice_quantity'],409);}
        $s=$this->stock($pid,true);
        $old=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}parsian_stock_reservations WHERE reference_type='invoice' AND reference_id=%d AND product_id=%d AND status='reserved'",$invoice,$pid));
        if($old+$qty>$itemQty||$qty>$s['available']){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'insufficient_stock_or_invoice_qty','available'=>$s['available'],'already_reserved'=>$old],409);}
        $ok=$wpdb->insert($wpdb->prefix.'parsian_stock_reservations',[
            'reference_type'=>'invoice','reference_id'=>$invoice,'product_id'=>$pid,'quantity'=>$qty,'status'=>'reserved',
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
        ],['%s','%d','%d','%d','%s','%d','%s']);
        if(!$ok){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'reservation_conflict'],409);}
        $rid=(int)$wpdb->insert_id; $this->event('inventory.stock.reserved','invoice',$invoice,['reservation_id'=>$rid,'product_id'=>$pid,'quantity'=>$qty]);
        $wpdb->query('COMMIT'); return new WP_REST_Response(['reservation_id'=>$rid,'status'=>'reserved','stock'=>$this->stock($pid)],201);
    }
    public function release_reservation(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $id=absint($request['id']); $wpdb->query('START TRANSACTION');
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_stock_reservations WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        if(!$r){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'reservation_not_found'],404);}
        if($r['status']==='released'){$wpdb->query('COMMIT');return new WP_REST_Response(['status'=>'already_released'],200);}
        if($r['status']!=='reserved'){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'reservation_not_releasable'],409);}
        $wpdb->update($wpdb->prefix.'parsian_stock_reservations',['status'=>'released','released_at'=>current_time('mysql',true)],['id'=>$id],['%s','%s'],['%d']);
        $this->event('inventory.stock.released','invoice',(int)$r['reference_id'],['reservation_id'=>$id]);
        $wpdb->query('COMMIT'); return new WP_REST_Response(['status'=>'released','stock'=>$this->stock((int)$r['product_id'])],200);
    }
    public function fulfill_invoice(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $invoiceId=absint($request['id']); $wpdb->query('START TRANSACTION');
        $inv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_invoices WHERE id=%d FOR UPDATE",$invoiceId),ARRAY_A);
        if(!$inv){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invoice_not_found'],404);}
        if($inv['status']==='fulfilled'){$wpdb->query('COMMIT');return new WP_REST_Response(['status'=>'already_fulfilled','cogs_toman'=>(int)$inv['cogs_toman']],200);}
        if($inv['status']!=='posted'){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invoice_not_fulfillable'],409);}
        $items=$wpdb->get_results($wpdb->prepare("SELECT product_id,quantity FROM {$wpdb->prefix}parsian_invoice_items WHERE invoice_id=%d ORDER BY product_id",$invoiceId),ARRAY_A)?:[];
        if(!$items){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invoice_has_no_items'],409);}
        $cogs=0; $posted=[];
        foreach($items as $item){
            $pid=(int)$item['product_id']; $qty=(int)$item['quantity']; $s=$this->stock($pid,true);
            $reserved=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}parsian_stock_reservations WHERE reference_type='invoice' AND reference_id=%d AND product_id=%d AND status='reserved'",$invoiceId,$pid));
            if($reserved<$qty||$s['on_hand']<$qty){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invoice_not_fully_reserved','product_id'=>$pid,'required'=>$qty,'reserved'=>$reserved],409);}
            $unit=$s['average_cost_toman']; if($unit<1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'inventory_cost_unavailable','product_id'=>$pid],409);}
            $cost=$unit*$qty; if($cost>PHP_INT_MAX||$cogs>PHP_INT_MAX-$cost){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'cogs_overflow'],400);}
            $cogs+=$cost;
            $ok=$wpdb->insert($wpdb->prefix.'parsian_stock_ledger',[
                'product_id'=>$pid,'source_type'=>'invoice','source_id'=>$invoiceId,'quantity_delta'=>-$qty,
                'unit_cost_toman'=>$unit,'value_delta_toman'=>-$cost,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
            ],['%d','%s','%d','%d','%d','%d','%d','%s']);
            if(!$ok){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'stock_issue_failed'],500);}
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}parsian_stock_reservations SET status='consumed',released_at=%s WHERE reference_type='invoice' AND reference_id=%d AND product_id=%d AND status='reserved'",current_time('mysql',true),$invoiceId,$pid));
            $posted[]=['product_id'=>$pid,'quantity'=>$qty,'unit_cost_toman'=>$unit,'cogs_toman'=>$cost];
        }
        $journal=Parsian_Final_Ledger::create_entry('invoice_cogs',$invoiceId,'بهای تمام‌شده فاکتور '.$inv['invoice_number'],[
            ['account_code'=>'5100','debit_toman'=>$cogs,'credit_toman'=>0],
            ['account_code'=>'1300','debit_toman'=>0,'credit_toman'=>$cogs]
        ],get_current_user_id());
        if(!$journal){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'cogs_journal_failed'],500);}
        $wpdb->update($wpdb->prefix.'parsian_invoices',['status'=>'fulfilled','cogs_toman'=>$cogs,'cogs_journal_entry_id'=>$journal],['id'=>$invoiceId],['%s','%d','%d'],['%d']);
        $this->event('inventory.invoice.fulfilled','invoice',$invoiceId,['cogs_toman'=>$cogs,'journal_entry_id'=>$journal,'items'=>$posted]);
        $wpdb->query('COMMIT'); return new WP_REST_Response(['status'=>'fulfilled','cogs_toman'=>$cogs,'journal_entry_id'=>$journal,'items'=>$posted],201);
    }
    public function valuation(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $products=$wpdb->get_results("SELECT id,sku,name,unit FROM {$wpdb->prefix}parsian_products WHERE status='active' ORDER BY name",ARRAY_A)?:[];
        $total=0; foreach($products as &$p){$p['stock']=$this->stock((int)$p['id']);$total+=(int)$p['stock']['inventory_value_toman'];} unset($p);
        return new WP_REST_Response(['method'=>'weighted_average_periodic','items'=>$products,'total_inventory_value_toman'=>$total],200);
    }
}
