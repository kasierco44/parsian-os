<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Sync {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/sync/push',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'push'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sync_use');}
        ]);
        register_rest_route('parsian-final/v1','/sync/pull',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'pull'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sync_use');}
        ]);
    }
    public function push(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params();
        $device=sanitize_text_field($p['device_uuid']??'');
        $ops=$p['operations']??null;
        if(!wp_is_uuid($device)||!is_array($ops)||count($ops)>100)
            return new WP_REST_Response(['code'=>'invalid_sync_batch'],400);
        global $wpdb;
        $devices=$wpdb->prefix.'parsian_devices';
        $registered=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$devices} WHERE device_uuid=%s AND user_id=%d AND revoked_at IS NULL",$device,get_current_user_id()));
        if(!$registered)return new WP_REST_Response(['code'=>'device_not_registered'],403);
        $table=$wpdb->prefix.'parsian_sync_ops';$results=[];
        foreach($ops as $op){
            if(!is_array($op)||!wp_is_uuid($op['operation_uuid']??'')||empty($op['type'])||!isset($op['payload'])||!is_array($op['payload'])){
                $results[]=['status'=>'rejected','code'=>'invalid_operation'];continue;
            }
            $uuid=strtolower($op['operation_uuid']);
            $existing=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$table} WHERE operation_uuid=%s",$uuid),ARRAY_A);
            if($existing){$results[]=['operation_uuid'=>$uuid,'status'=>$existing['status'],'duplicate'=>true];continue;}
            $payload=wp_json_encode($op['payload']);
            $ok=$wpdb->insert($table,[
                'operation_uuid'=>$uuid,'device_uuid'=>$device,'user_id'=>get_current_user_id(),
                'operation_type'=>sanitize_key($op['type']),'payload'=>$payload,
                'status'=>'pending_review','created_at'=>current_time('mysql',true)
            ],['%s','%s','%d','%s','%s','%s','%s']);
            $results[]=$ok?['operation_uuid'=>$uuid,'status'=>'pending_review']:['operation_uuid'=>$uuid,'status'=>'retry'];
        }
        return new WP_REST_Response(['results'=>$results],202);
    }
    public function pull(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $since=absint($request->get_param('since_id'));
        $table=$wpdb->prefix.'parsian_events';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,payload,created_at FROM {$table} WHERE id>%d ORDER BY id ASC LIMIT 200",$since),ARRAY_A);
        $last=$since;
        foreach((array)$rows as $row)$last=max($last,(int)$row['id']);
        return new WP_REST_Response(['items'=>$rows?:[],'next_cursor'=>$last,'has_more'=>count((array)$rows)===200],200);
    }
}
