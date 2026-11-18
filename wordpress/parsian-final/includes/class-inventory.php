<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Inventory {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/inventory/items',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'items'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_inventory_read');}
        ]);
        register_rest_route('parsian-final/v1','/inventory/movements',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'movement'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_inventory_write');}
        ]);
    }
    public function items(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $rows=$wpdb->get_results("SELECT id,sku,name,unit,status FROM {$wpdb->prefix}parsian_products ORDER BY name ASC LIMIT 300",ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[]],200);
    }
    public function movement(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params();
        $qty=isset($p['quantity'])?filter_var($p['quantity'],FILTER_VALIDATE_INT):false;
        if($qty===false||$qty===0||empty($p['product_id'])||!in_array($p['direction']??'', ['in','out'],true))
            return new WP_REST_Response(['code'=>'invalid_movement'],400);
        global $wpdb;
        $ok=$wpdb->insert($wpdb->prefix.'parsian_stock_movements',[
            'product_id'=>absint($p['product_id']),'quantity'=>$qty,
            'direction'=>$p['direction'],'reason'=>sanitize_text_field($p['reason']??''),
            'status'=>'pending_approval','created_by'=>get_current_user_id(),
            'created_at'=>current_time('mysql',true)
        ],['%d','%d','%s','%s','%s','%d','%s']);
        if(!$ok)return new WP_REST_Response(['code'=>'movement_create_failed'],500);
        return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'status'=>'pending_approval','stock_posted'=>false],202);
    }
}
