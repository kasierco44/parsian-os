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
        $page=max(1,absint($request->get_param('page')?:1));
        $perPage=min(100,max(1,absint($request->get_param('per_page')?:50)));
        $offset=($page-1)*$perPage;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,sku,name,unit,status FROM {$wpdb->prefix}parsian_products
             WHERE status='active' ORDER BY name ASC LIMIT %d OFFSET %d",$perPage,$offset
        ),ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[],'page'=>$page,'per_page'=>$perPage],200);
    }
    public function movement(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params();
        if(!is_array($p))return new WP_REST_Response(['code'=>'invalid_movement'],400);
        $qty=isset($p['quantity'])?filter_var($p['quantity'],FILTER_VALIDATE_INT):false;
        $productId=absint($p['product_id']??0);
        $direction=$p['direction']??'';
        if($qty===false||$qty<1||$qty>100000000||$productId<1||!in_array($direction,['in','out'],true))
            return new WP_REST_Response(['code'=>'invalid_movement'],400);
        global $wpdb;
        $product=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}parsian_products WHERE id=%d AND status='active'",$productId
        ));
        if(!$product)return new WP_REST_Response(['code'=>'product_not_found'],404);
        $reason=sanitize_text_field($p['reason']??'');
        if(strlen($reason)>190)return new WP_REST_Response(['code'=>'reason_too_long'],400);
        $ok=$wpdb->insert($wpdb->prefix.'parsian_stock_movements',[
            'product_id'=>$productId,'quantity'=>$qty,'direction'=>$direction,'reason'=>$reason,
            'status'=>'pending_approval','created_by'=>get_current_user_id(),
            'created_at'=>current_time('mysql',true)
        ],['%d','%d','%s','%s','%s','%d','%s']);
        if(!$ok)return new WP_REST_Response(['code'=>'movement_create_failed'],500);
        $id=(int)$wpdb->insert_id;
        $wpdb->insert($wpdb->prefix.'parsian_events',[
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>get_current_user_id(),
            'event_type'=>'inventory.movement.requested','aggregate_type'=>'stock_movement',
            'aggregate_id'=>$id,'payload'=>wp_json_encode(['movement_id'=>$id,'product_id'=>$productId,'status'=>'pending_approval']),
            'created_at'=>current_time('mysql',true)
        ],['%s','%d','%s','%s','%d','%s','%s']);
        return new WP_REST_Response(['id'=>$id,'status'=>'pending_approval','stock_posted'=>false],202);
    }
}
