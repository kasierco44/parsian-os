<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Finance {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1', '/payments', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_payment'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_write'); },
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
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix.'parsian_payments', [
            'direction'=>$direction, 'amount_toman'=>$amount,
            'party_type'=>$party_type,
            'party_id'=>absint($p['party_id'] ?? 0),
            'method'=>$method,
            'reference'=>sanitize_text_field($p['reference'] ?? ''),
            'status'=>'pending_approval','created_by'=>get_current_user_id(),
            'created_at'=>current_time('mysql', true)
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
        if($amount===false || $amount<1 || $amount>1000000000000 ||
           !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date) ||
           !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)) ||
           !in_array($p['direction']??'', ['received','issued'],true)) {
            return new WP_REST_Response(['code'=>'invalid_cheque'],400);
        }
        global $wpdb;
        $ok=$wpdb->insert($wpdb->prefix.'parsian_cheques',[
            'direction'=>$p['direction'],'amount_toman'=>$amount,'due_date'=>$date,
            'bank_name'=>sanitize_text_field($p['bank_name']??''),
            'cheque_number'=>sanitize_text_field($p['cheque_number']??''),
            'party_name'=>sanitize_text_field($p['party_name']??''),
            'status'=>'pending','created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%s','%s','%s','%d','%s']);
        if(!$ok)return new WP_REST_Response(['code'=>'cheque_create_failed'],500);
        $id=(int)$wpdb->insert_id;
        $wpdb->insert($wpdb->prefix.'parsian_events',[
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>get_current_user_id(),
            'event_type'=>'finance.cheque.created','aggregate_type'=>'cheque',
            'aggregate_id'=>$id,'payload'=>wp_json_encode(['cheque_id'=>$id,'status'=>'pending']),
            'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%d','%s','%s']);
        return new WP_REST_Response(['id'=>$id,'status'=>'pending'],201);
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
}
