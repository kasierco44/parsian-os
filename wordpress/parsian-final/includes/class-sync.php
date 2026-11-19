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
        register_rest_route('parsian-final/v1','/sync/operations',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'list_pending_operations'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_manage_users');}
        ]);
        register_rest_route('parsian-final/v1','/sync/operations/(?P<id>\\d+)/approve',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'approve_operation'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_manage_users');}
        ]);
        register_rest_route('parsian-final/v1','/sync/operations/(?P<id>\\d+)/reject',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'reject_operation'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_manage_users');}
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
            $requiredCapability = str_starts_with($type, 'sales.') ? 'parsian_sales_create' : 'parsian_crm_write';
            if (!current_user_can($requiredCapability)) {
                $results[]=['operation_uuid'=>$op['operation_uuid'],'status'=>'rejected','code'=>'operation_actor_not_authorized'];continue;
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

    public function list_pending_operations(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $limit = min(100, max(1, absint($request->get_param('limit') ?: 50)));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,operation_uuid,user_id,device_uuid,operation_type,payload,status,created_at
             FROM {$wpdb->prefix}parsian_sync_ops
             WHERE status='pending_review'
             ORDER BY id ASC LIMIT %d",
            $limit
        ), ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        }
        unset($row);
        return new WP_REST_Response(['items' => $rows ?: []], 200);
    }

    public function approve_operation(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $id = absint($request['id']);
        $table = $wpdb->prefix . 'parsian_sync_ops';
        $wpdb->query('START TRANSACTION');
        $operation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $id), ARRAY_A);
        if (!$operation) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_operation_not_found'],404); }
        if ($operation['status'] === 'applied') {
            $result = json_decode((string) $operation['result'], true) ?: [];
            $wpdb->query('COMMIT');
            return new WP_REST_Response(['status'=>'already_applied'] + $result, 200);
        }
        if ($operation['status'] !== 'pending_review') {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'sync_operation_not_reviewable','status'=>$operation['status']],409);
        }
        if (!in_array($operation['operation_type'], ['crm.lead.create', 'crm.lead.update'], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'operation_handler_not_available','operation_type'=>$operation['operation_type']],422);
        }
        $ownerId = (int) $operation['user_id'];
        $owner = get_userdata($ownerId);
        if (!$owner || !in_array('parsian_seller', (array) $owner->roles, true) || !user_can($owner, 'parsian_crm_write')) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'operation_actor_not_seller'],403);
        }
        $payload = json_decode((string) $operation['payload'], true);
        if (!is_array($payload)) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'invalid_operation_payload'],422); }

        if ($operation['operation_type'] === 'crm.lead.update') {
            $leadId = absint($payload['lead_id'] ?? 0);
            $lead = $leadId ? $wpdb->get_row($wpdb->prepare(
                "SELECT id, owner_user_id FROM {$wpdb->prefix}parsian_leads WHERE id=%d FOR UPDATE",
                $leadId
            ), ARRAY_A) : null;
            if (!$lead || (int) $lead['owner_user_id'] !== $ownerId) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_lead_not_owned_by_actor'],403);
            }

            $changes = [];
            $formats = [];
            $limits = ['display_name'=>190, 'phone'=>40, 'province'=>100];
            foreach ($limits as $field => $maxLength) {
                if (array_key_exists($field, $payload)) {
                    $value = trim(sanitize_text_field((string) $payload[$field]));
                    if (($field === 'display_name' && $value === '') || strlen($value) > $maxLength) {
                        $wpdb->query('ROLLBACK');
                        return new WP_REST_Response(['code'=>'offline_lead_update_invalid','field'=>$field],422);
                    }
                    $changes[$field] = $value;
                    $formats[] = '%s';
                }
            }
            if (array_key_exists('notes', $payload)) {
                $changes['notes'] = sanitize_textarea_field((string) $payload['notes']);
                $formats[] = '%s';
            }
            if (!$changes) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_lead_update_empty'],422);
            }
            $changes['updated_at'] = current_time('mysql', true);
            $formats[] = '%s';
            $updatedLead = $wpdb->update(
                $wpdb->prefix . 'parsian_leads',
                $changes,
                ['id'=>$leadId, 'owner_user_id'=>$ownerId],
                $formats,
                ['%d','%d']
            );
            if ($updatedLead === false) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_lead_update_failed'],500);
            }
            $result = ['lead_id'=>$leadId,'operation_uuid'=>$operation['operation_uuid']];
            $updatedOperation = $wpdb->update($table, [
                'status'=>'applied','result'=>wp_json_encode($result),'processed_at'=>current_time('mysql',true)
            ], ['id'=>$id,'status'=>'pending_review'], ['%s','%s','%s'], ['%d','%s']);
            if ($updatedOperation !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'sync_operation_state_conflict'],409);
            }
            $eventOk = $wpdb->insert($wpdb->prefix . 'parsian_events', [
                'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>$ownerId,
                'event_type'=>'crm.lead.updated.offline','aggregate_type'=>'lead','aggregate_id'=>$leadId,
                'payload'=>wp_json_encode(['lead_id'=>$leadId,'owner_user_id'=>$ownerId,'approved_by'=>get_current_user_id(),'operation_uuid'=>$operation['operation_uuid']]),
                'created_at'=>current_time('mysql',true)
            ], ['%s','%d','%s','%s','%d','%s','%s']);
            if (!$eventOk) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'sync_event_write_failed'],500);
            }
            $wpdb->query('COMMIT');
            return new WP_REST_Response(['status'=>'applied'] + $result, 200);
        }

        $name = trim(sanitize_text_field((string) ($payload['name'] ?? '')));
        $phone = trim(sanitize_text_field((string) ($payload['phone'] ?? '')));
        $province = trim(sanitize_text_field((string) ($payload['province'] ?? '')));
        $notes = sanitize_textarea_field((string) ($payload['notes'] ?? ''));
        if ($name === '' || strlen($name) > 190 || strlen($phone) > 40 || strlen($province) > 100) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'offline_lead_payload_invalid'],422);
        }
        $ok = $wpdb->insert($wpdb->prefix . 'parsian_leads', [
            'display_name'=>$name,'phone'=>$phone,'province'=>$province,'notes'=>$notes,
            'owner_user_id'=>$ownerId,'status'=>'new','created_at'=>current_time('mysql',true)
        ], ['%s','%s','%s','%s','%d','%s','%s']);
        if (!$ok) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'offline_lead_create_failed'],500); }
        $leadId = (int) $wpdb->insert_id;
        $result = ['lead_id'=>$leadId,'operation_uuid'=>$operation['operation_uuid']];
        $updated = $wpdb->update($table, [
            'status'=>'applied','result'=>wp_json_encode($result),'processed_at'=>current_time('mysql',true)
        ], ['id'=>$id,'status'=>'pending_review'], ['%s','%s','%s'], ['%d','%s']);
        if ($updated !== 1) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_operation_state_conflict'],409); }
        $eventOk = $wpdb->insert($wpdb->prefix . 'parsian_events', [
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>$ownerId,
            'event_type'=>'crm.lead.created.offline','aggregate_type'=>'lead','aggregate_id'=>$leadId,
            'payload'=>wp_json_encode(['lead_id'=>$leadId,'owner_user_id'=>$ownerId,'approved_by'=>get_current_user_id(),'operation_uuid'=>$operation['operation_uuid']]),
            'created_at'=>current_time('mysql',true)
        ], ['%s','%d','%s','%s','%d','%s','%s']);
        if (!$eventOk) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_event_write_failed'],500); }
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>'applied'] + $result, 201);
    }

    public function reject_operation(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $id = absint($request['id']);
        $reason = sanitize_textarea_field((string) ($request->get_param('reason') ?? ''));
        $table = $wpdb->prefix . 'parsian_sync_ops';
        $wpdb->query('START TRANSACTION');
        $operation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $id), ARRAY_A);
        if (!$operation) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_operation_not_found'],404); }
        if ($operation['status'] !== 'pending_review') {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'sync_operation_not_reviewable','status'=>$operation['status']],409);
        }
        $updated = $wpdb->update($table, [
            'status'=>'rejected','result'=>wp_json_encode(['reason'=>$reason]),'processed_at'=>current_time('mysql',true)
        ], ['id'=>$id,'status'=>'pending_review'], ['%s','%s','%s'], ['%d','%s']);
        if ($updated !== 1) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_operation_state_conflict'],409); }
        $eventOk = $wpdb->insert($wpdb->prefix . 'parsian_events', [
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>(int)$operation['user_id'],
            'event_type'=>'sync.operation.rejected','aggregate_type'=>'sync_operation','aggregate_id'=>$id,
            'payload'=>wp_json_encode(['operation_uuid'=>$operation['operation_uuid'],'reason'=>$reason,'reviewed_by'=>get_current_user_id()]),
            'created_at'=>current_time('mysql',true)
        ], ['%s','%d','%s','%s','%d','%s','%s']);
        if (!$eventOk) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_event_write_failed'],500); }
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>'rejected','operation_uuid'=>$operation['operation_uuid']],200);
    }

    public function pull(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $sinceRaw = $request->get_param('since_id');
        if ($sinceRaw !== null && (!is_numeric($sinceRaw) || (string)(int)$sinceRaw !== (string)$sinceRaw || (int)$sinceRaw < 0)) {
            return new WP_REST_Response(['code'=>'invalid_cursor'], 400);
        }

        $since = $sinceRaw === null ? 0 : (int)$sinceRaw;
        $limit = 200;
        $table = $wpdb->prefix . 'parsian_events';

        // Read a bounded global page so the cursor can advance across events
        // belonging to other users. Filtering in SQL by actor_user_id would
        // otherwise leave a seller's cursor stuck whenever only foreign events
        // were created after their last sync.
        $scanned = $wpdb->get_results($wpdb->prepare(
            "SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,actor_user_id,payload,created_at
             FROM {$table} WHERE id>%d ORDER BY id ASC LIMIT %d",
            $since, $limit
        ), ARRAY_A);

        $isManager = current_user_can('parsian_manage_users');
        $isFinance = current_user_can('parsian_finance_read');
        $userId = get_current_user_id();
        $visible = [];
        $last = $since;

        foreach ((array) $scanned as $event) {
            $last = max($last, (int) $event['id']);
            $eventType = (string) $event['event_type'];
            $financeEvent = str_starts_with($eventType, 'finance.')
                || str_starts_with($eventType, 'accounting.')
                || str_starts_with($eventType, 'payment.')
                || str_starts_with($eventType, 'cheque.')
                || str_starts_with($eventType, 'bank.')
                || str_starts_with($eventType, 'stock.');

            if ($isManager || ($isFinance ? $financeEvent : (int) $event['actor_user_id'] === $userId)) {
                unset($event['actor_user_id']);
                $visible[] = $event;
            }
        }

        return new WP_REST_Response([
            'items' => $visible,
            'next_cursor' => $last,
            'has_more' => count((array) $scanned) === $limit
        ], 200);
    }
}
