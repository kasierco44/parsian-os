<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Sync {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/sync/devices',[
            ['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'list_devices'],'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sync_use');}],
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'register_device'],'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sync_use');}]
        ]);
        register_rest_route('parsian-final/v1','/sync/push',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'push'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sync_use');}
        ]);
        register_rest_route('parsian-final/v1','/sync/pull',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'pull'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sync_use');}
        ]);
    }
    public function list_devices(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT device_uuid,platform,label,created_at,last_seen_at,revoked_at
             FROM {$wpdb->prefix}parsian_devices WHERE user_id=%d ORDER BY created_at DESC",
            get_current_user_id()
        ),ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[]],200);
    }
    public function register_device(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params();
        $uuid=strtolower(sanitize_text_field($p['device_uuid']??''));
        $platform=sanitize_key($p['platform']??'');
        $label=sanitize_text_field($p['label']??'');
        if(!wp_is_uuid($uuid)||!in_array($platform,['android','windows','web'],true)||strlen($label)>120)
            return new WP_REST_Response(['code'=>'invalid_device'],400);
        global $wpdb;
        $table=$wpdb->prefix.'parsian_devices';
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,user_id,revoked_at FROM {$table} WHERE device_uuid=%s",$uuid),ARRAY_A);
        if($existing && (int)$existing['user_id']!==get_current_user_id())
            return new WP_REST_Response(['code'=>'device_owned_by_another_user'],409);
        if($existing && $existing['revoked_at']!==null)
            return new WP_REST_Response(['code'=>'device_revoked'],403);
        if(!$existing){
            $ok=$wpdb->insert($table,[
                'device_uuid'=>$uuid,'user_id'=>get_current_user_id(),'platform'=>$platform,
                'label'=>$label,'created_at'=>current_time('mysql',true),'last_seen_at'=>current_time('mysql',true)
            ],['%s','%d','%s','%s','%s','%s']);
            if(!$ok)return new WP_REST_Response(['code'=>'device_register_failed'],500);
        } else {
            $wpdb->update($table,['last_seen_at'=>current_time('mysql',true)],['id'=>(int)$existing['id']],['%s'],['%d']);
        }
        return new WP_REST_Response(['device_uuid'=>$uuid,'status'=>'registered'],200);
    }
    public function push(WP_REST_Request $request): WP_REST_Response {
        $p=$request->get_json_params();
        $device=strtolower(sanitize_text_field($p['device_uuid']??''));
        $ops=$p['operations']??null;
        if(!wp_is_uuid($device)||!is_array($ops)||count($ops)<1||count($ops)>100)
            return new WP_REST_Response(['code'=>'invalid_sync_batch'],400);
        global $wpdb;
        $devices=$wpdb->prefix.'parsian_devices';
        $deviceRow=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$devices} WHERE device_uuid=%s AND user_id=%d AND revoked_at IS NULL",$device,get_current_user_id()),ARRAY_A);
        if(!$deviceRow)return new WP_REST_Response(['code'=>'device_not_registered'],403);
        $table=$wpdb->prefix.'parsian_sync_ops';$results=[];
        foreach($ops as $op){
            if(!is_array($op)||!wp_is_uuid($op['operation_uuid']??'')||empty($op['type'])||!isset($op['payload'])||!is_array($op['payload'])){
                $results[]=['status'=>'rejected','code'=>'invalid_operation'];continue;
            }
            $type=sanitize_key($op['type']);
            $allowedTypes=['crm.lead.create','crm.lead.update','crm.task.complete','sales.proforma.draft'];
            $payload=wp_json_encode($op['payload']);
            if(!in_array($type,$allowedTypes,true)||strlen((string)$payload)>65535){
                $results[]=['operation_uuid'=>$op['operation_uuid'],'status'=>'rejected','code'=>'operation_too_large_or_invalid'];continue;
            }
            $uuid=strtolower($op['operation_uuid']);
            $payloadHash=hash('sha256',(string)$payload);
            $existing=$wpdb->get_row($wpdb->prepare("SELECT id,user_id,device_uuid,status,payload_sha256 FROM {$table} WHERE operation_uuid=%s",$uuid),ARRAY_A);
            if($existing){
                if((int)$existing['user_id']!==get_current_user_id() || $existing['device_uuid']!==$device || !hash_equals((string)$existing['payload_sha256'],$payloadHash)){
                    $results[]=['operation_uuid'=>$uuid,'status'=>'rejected','code'=>'operation_uuid_conflict'];
                } else {
                    $results[]=['operation_uuid'=>$uuid,'status'=>$existing['status'],'duplicate'=>true];
                }
                continue;
            }
            $ok=$wpdb->insert($table,[
                'operation_uuid'=>$uuid,'device_uuid'=>$device,'user_id'=>get_current_user_id(),
                'operation_type'=>$type,'payload'=>$payload,'payload_sha256'=>$payloadHash,
                'status'=>'pending_review','created_at'=>current_time('mysql',true)
            ],['%s','%s','%d','%s','%s','%s','%s']);
            if(!$ok){
                $duplicate=$wpdb->get_row($wpdb->prepare("SELECT user_id,device_uuid,status,payload_sha256 FROM {$table} WHERE operation_uuid=%s",$uuid),ARRAY_A);
                $results[]=$duplicate && (int)$duplicate['user_id']===get_current_user_id() && $duplicate['device_uuid']===$device && hash_equals((string)$duplicate['payload_sha256'],$payloadHash)
                    ?['operation_uuid'=>$uuid,'status'=>$duplicate['status'],'duplicate'=>true]
                    :['operation_uuid'=>$uuid,'status'=>'retry'];
            } else {
                $results[]=['operation_uuid'=>$uuid,'status'=>'pending_review'];
            }
        }
        $wpdb->update($devices,['last_seen_at'=>current_time('mysql',true)],['id'=>(int)$deviceRow['id']],['%s'],['%d']);
        return new WP_REST_Response(['results'=>$results],202);
    }
    public function pull(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $sinceRaw=$request->get_param('since_id');
        if($sinceRaw!==null && (!is_numeric($sinceRaw)|| (int)$sinceRaw<0))
            return new WP_REST_Response(['code'=>'invalid_cursor'],400);
        $since=min(PHP_INT_MAX,absint($sinceRaw));
        $limit=200;
        $table=$wpdb->prefix.'parsian_events';
        if(current_user_can('parsian_manage_users')||current_user_can('parsian_finance_read')){
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,payload,created_at
                 FROM {$table} WHERE id>%d ORDER BY id ASC LIMIT %d",$since,$limit
            ),ARRAY_A);
        } else {
            // Never expose another user's event stream to seller/customer clients.
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,payload,created_at
                 FROM {$table} WHERE id>%d AND actor_user_id=%d ORDER BY id ASC LIMIT %d",
                $since,get_current_user_id(),$limit
            ),ARRAY_A);
        }
        $last=$since;
        foreach((array)$rows as $row)$last=max($last,(int)$row['id']);
        return new WP_REST_Response(['items'=>$rows?:[],'next_cursor'=>$last,'has_more'=>count((array)$rows)===$limit],200);
    }
}
