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
        $amount = isset($p['amount_toman']) ? filter_var($p['amount_toman'], FILTER_VALIDATE_INT) : false;
        if ($amount === false || $amount < 1 || !in_array($p['direction'] ?? '', ['in','out'], true)) {
            return new WP_REST_Response(['code'=>'invalid_payment'], 400);
        }
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix.'parsian_payments', [
            'direction'=>$p['direction'], 'amount_toman'=>$amount,
            'party_type'=>sanitize_key($p['party_type'] ?? 'other'),
            'party_id'=>absint($p['party_id'] ?? 0),
            'method'=>sanitize_key($p['method'] ?? 'cash'),
            'reference'=>sanitize_text_field($p['reference'] ?? ''),
            'status'=>'pending_approval','created_by'=>get_current_user_id(),
            'created_at'=>current_time('mysql', true)
        ], ['%s','%d','%s','%d','%s','%s','%s','%d','%s']);
        if (!$ok) return new WP_REST_Response(['code'=>'payment_create_failed'], 500);
        return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'status'=>'pending_approval','posted'=>false], 202);
    }
    public function create_cheque(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params();
        $amount=isset($p['amount_toman'])?filter_var($p['amount_toman'],FILTER_VALIDATE_INT):false;
        $date=sanitize_text_field($p['due_date']??'');
        if($amount===false||$amount<1||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!in_array($p['direction']??'', ['received','issued'],true))
            return new WP_REST_Response(['code'=>'invalid_cheque'],400);
        global $wpdb;
        $ok=$wpdb->insert($wpdb->prefix.'parsian_cheques',[
            'direction'=>$p['direction'],'amount_toman'=>$amount,'due_date'=>$date,
            'bank_name'=>sanitize_text_field($p['bank_name']??''),
            'cheque_number'=>sanitize_text_field($p['cheque_number']??''),
            'party_name'=>sanitize_text_field($p['party_name']??''),
            'status'=>'pending','created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%s','%s','%s','%d','%s']);
        if(!$ok)return new WP_REST_Response(['code'=>'cheque_create_failed'],500);
        return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'status'=>'pending'],201);
    }
    public function list_cheques(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $rows=$wpdb->get_results("SELECT id,direction,amount_toman,due_date,bank_name,party_name,status FROM {$wpdb->prefix}parsian_cheques ORDER BY due_date ASC LIMIT 200",ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[]],200);
    }
}
