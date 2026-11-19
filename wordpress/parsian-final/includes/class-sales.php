<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Sales {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/proformas',[
            [
                'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'create_proforma'],
                'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sales_create');}
            ],
            [
                'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'list_proformas'],
                'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sales_create');}
            ]
        ]);
        register_rest_route('parsian-final/v1','/proformas/(?P<id>\d+)',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'get_proforma'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sales_create');}
        ]);
        register_rest_route('parsian-final/v1','/proformas/(?P<id>\d+)/approve',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'approve_proforma'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sales_approve');}
        ]);
    }
    private function can_access_customer(int $customerId): bool {
        if (current_user_can('parsian_manage_users') || current_user_can('parsian_finance_read')) return true;
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT umeta_id FROM {$wpdb->usermeta}
             WHERE user_id=%d AND meta_key=%s AND meta_value='1' LIMIT 1",
            get_current_user_id(), 'parsian_customer_' . $customerId
        ));
    }
    private function event(string $type,string $aggregate,int $id,array $payload): void {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'parsian_events',[
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>get_current_user_id(),
            'event_type'=>$type,'aggregate_type'=>$aggregate,'aggregate_id'=>$id,
            'payload'=>wp_json_encode($payload),'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%d','%s','%s']);
    }
    public function create_proforma(WP_REST_Request $request): WP_REST_Response {
        $body=$request->get_json_params();
        if(!is_array($body)||empty($body['customer_id'])||empty($body['items'])||!is_array($body['items'])||count($body['items'])>100)
            return new WP_REST_Response(['code'=>'invalid_proforma'],400);
        $customerId=absint($body['customer_id']);
        if(!$this->can_access_customer($customerId))return new WP_REST_Response(['code'=>'customer_not_assigned'],403);
        global $wpdb;
        $customer=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}parsian_customers WHERE id=%d AND status='active'",$customerId
        ));
        if(!$customer)return new WP_REST_Response(['code'=>'customer_not_found'],404);
        $clean=[];$total=0;
        foreach($body['items'] as $item){
            if(!is_array($item)||empty($item['product_id'])||!isset($item['quantity'],$item['unit_price_toman']))
                return new WP_REST_Response(['code'=>'invalid_line_item'],400);
            $productId=absint($item['product_id']);
            $qty=filter_var($item['quantity'],FILTER_VALIDATE_INT);
            $price=filter_var($item['unit_price_toman'],FILTER_VALIDATE_INT);
            if(!$productId||$qty===false||$qty<1||$qty>1000000||$price===false||$price<0||$price>1000000000000)
                return new WP_REST_Response(['code'=>'invalid_line_item_amount'],400);
            $product=$wpdb->get_row($wpdb->prepare(
                "SELECT id,name FROM {$wpdb->prefix}parsian_products WHERE id=%d AND status='active'",$productId
            ),ARRAY_A);
            if(!$product)return new WP_REST_Response(['code'=>'product_not_found','product_id'=>$productId],404);
            if($price>0 && $qty>intdiv(PHP_INT_MAX,$price))return new WP_REST_Response(['code'=>'total_overflow'],400);
            $lineTotal=$qty*$price;
            if($total>1000000000000-$lineTotal)return new WP_REST_Response(['code'=>'total_limit_exceeded'],400);
            $total+=$lineTotal;
            $clean[]=['product_id'=>$productId,'description'=>$product['name'],'quantity'=>$qty,'unit_price_toman'=>$price,'line_total_toman'=>$lineTotal];
        }
        if($total<1)return new WP_REST_Response(['code'=>'zero_total_not_allowed'],400);
        $wpdb->query('START TRANSACTION');
        $now=current_time('mysql',true);
        $number='PF-'.gmdate('ymd').'-'.strtoupper(wp_generate_password(6,false,false));
        $ok=$wpdb->insert($wpdb->prefix.'parsian_proformas',[
            'document_number'=>$number,'customer_id'=>$customerId,'status'=>'draft','currency'=>'IRR',
            'total_toman'=>$total,'notes'=>sanitize_textarea_field($body['notes']??''),
            'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
        ],['%s','%d','%s','%s','%d','%s','%d','%s','%s']);
        if(!$ok){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'proforma_create_failed'],500);}
        $id=(int)$wpdb->insert_id;
        foreach($clean as $line){
            $line['proforma_id']=$id;
            if(!$wpdb->insert($wpdb->prefix.'parsian_proforma_items',$line,
                ['%d','%s','%d','%d','%d','%d'])){
                $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'proforma_item_create_failed'],500);
            }
        }
        $this->event('sales.proforma.created','proforma',$id,['customer_id'=>$customerId,'total_toman'=>$total,'status'=>'draft']);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['id'=>$id,'document_number'=>$number,'status'=>'draft','approval_required'=>true,'total_toman'=>$total],201);
    }
    public function list_proformas(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $page=max(1,absint($request->get_param('page')?:1));
        $perPage=min(100,max(1,absint($request->get_param('per_page')?:30)));
        $offset=($page-1)*$perPage;
        if(current_user_can('parsian_manage_users')||current_user_can('parsian_sales_approve')){
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,document_number,customer_id,status,total_toman,created_by,created_at,invoice_id
                 FROM {$wpdb->prefix}parsian_proformas ORDER BY id DESC LIMIT %d OFFSET %d",$perPage,$offset
            ),ARRAY_A);
        } else {
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,document_number,customer_id,status,total_toman,created_by,created_at,invoice_id
                 FROM {$wpdb->prefix}parsian_proformas WHERE created_by=%d ORDER BY id DESC LIMIT %d OFFSET %d",
                get_current_user_id(),$perPage,$offset
            ),ARRAY_A);
        }
        return new WP_REST_Response(['items'=>$rows?:[],'page'=>$page,'per_page'=>$perPage],200);
    }
    public function get_proforma(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;$id=absint($request['id']);
        $doc=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}parsian_proformas WHERE id=%d",$id),ARRAY_A);
        if(!$doc)return new WP_REST_Response(['code'=>'proforma_not_found'],404);
        if(!current_user_can('parsian_manage_users')&&!current_user_can('parsian_sales_approve')&&(int)$doc['created_by']!==get_current_user_id())
            return new WP_REST_Response(['code'=>'forbidden'],403);
        $items=$wpdb->get_results($wpdb->prepare(
            "SELECT product_id,description,quantity,unit_price_toman,line_total_toman FROM {$wpdb->prefix}parsian_proforma_items WHERE proforma_id=%d ORDER BY id",$id
        ),ARRAY_A);
        return new WP_REST_Response(['proforma'=>$doc,'items'=>$items?:[]],200);
    }
    public function approve_proforma(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;$id=absint($request['id']);
        $wpdb->query('START TRANSACTION');
        $table=$wpdb->prefix.'parsian_proformas';
        $doc=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        if(!$doc){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'proforma_not_found'],404);}
        if($doc['status']==='approved' && (int)$doc['invoice_id']>0){
            $invoiceId=(int)$doc['invoice_id'];$wpdb->query('COMMIT');
            return new WP_REST_Response(['status'=>'already_approved','invoice_id'=>$invoiceId],200);
        }
        if($doc['status']!=='draft'){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'proforma_not_approvable'],409);}
        $items=$wpdb->get_results($wpdb->prepare(
            "SELECT product_id,description,quantity,unit_price_toman,line_total_toman FROM {$wpdb->prefix}parsian_proforma_items WHERE proforma_id=%d ORDER BY id",$id
        ),ARRAY_A);
        if(!$items){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'proforma_has_no_items'],409);}
        $invoiceNumber='INV-'.gmdate('ymd').'-'.strtoupper(wp_generate_password(6,false,false));
        $now=current_time('mysql',true);
        $ok=$wpdb->insert($wpdb->prefix.'parsian_invoices',[
            'invoice_number'=>$invoiceNumber,'proforma_id'=>$id,'customer_id'=>(int)$doc['customer_id'],
            'status'=>'posted','currency'=>'IRR','total_toman'=>(int)$doc['total_toman'],
            'issued_by'=>get_current_user_id(),'issued_at'=>$now
        ],['%s','%d','%d','%s','%s','%d','%d','%s']);
        if(!$ok){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invoice_create_failed'],500);}
        $invoiceId=(int)$wpdb->insert_id;
        foreach($items as $line){
            $line['invoice_id']=$invoiceId;
            if(!$wpdb->insert($wpdb->prefix.'parsian_invoice_items',$line,
                ['%d','%s','%d','%d','%d','%d'])){
                $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'invoice_item_create_failed'],500);
            }
        }
        $journalId=Parsian_Final_Ledger::create_entry('invoice',$invoiceId,'صدور فاکتور '.$invoiceNumber,[
            ['account_code'=>'1100','debit_toman'=>(int)$doc['total_toman'],'credit_toman'=>0,'party_type'=>'customer','party_id'=>(int)$doc['customer_id']],
            ['account_code'=>'4100','debit_toman'=>0,'credit_toman'=>(int)$doc['total_toman'],'party_type'=>'customer','party_id'=>(int)$doc['customer_id']]
        ],get_current_user_id());
        if(!$journalId){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'journal_post_failed'],500);}
        $wpdb->update($wpdb->prefix.'parsian_invoices',['journal_entry_id'=>$journalId],['id'=>$invoiceId],['%d'],['%d']);
        $updated=$wpdb->update($table,[
            'status'=>'approved','approved_by'=>get_current_user_id(),'approved_at'=>$now,
            'invoice_id'=>$invoiceId,'updated_at'=>$now
        ],['id'=>$id,'status'=>'draft'],['%s','%d','%s','%d','%s'],['%d','%s']);
        if($updated!==1){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'proforma_state_conflict'],409);}
        $this->event('sales.proforma.approved','proforma',$id,['invoice_id'=>$invoiceId,'journal_entry_id'=>$journalId]);
        $this->event('sales.invoice.issued','invoice',$invoiceId,['invoice_number'=>$invoiceNumber,'total_toman'=>(int)$doc['total_toman']]);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>'approved','proforma_id'=>$id,'invoice_id'=>$invoiceId,'invoice_number'=>$invoiceNumber,'journal_entry_id'=>$journalId,'total_toman'=>(int)$doc['total_toman']],201);
    }
}
