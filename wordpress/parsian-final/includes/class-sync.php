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
        register_rest_route('parsian-final/v1','/sync/operations/(?P<id>\\d+)/resolve-conflict',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'resolve_conflict'],
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
            // Operation names are namespaced with dots; sanitize_key() strips dots in WordPress.
            $type=strtolower(trim((string)$op['type']));
            if (!preg_match('/^[a-z][a-z0-9]*(?:\\.[a-z][a-z0-9]*)+$/', $type)) {
                $results[]=['operation_uuid'=>$op['operation_uuid'],'status'=>'rejected','code'=>'invalid_operation_type'];continue;
            }
            $allowedTypes=['crm.lead.create','crm.lead.update','crm.customer.update','crm.task.complete','sales.proforma.draft','sales.proforma.update','finance.invoice.update','finance.payment.update'];
            $payload=wp_json_encode($op['payload']);
            if(!in_array($type,$allowedTypes,true)||strlen((string)$payload)>65535){
                $results[]=['operation_uuid'=>$op['operation_uuid'],'status'=>'rejected','code'=>'operation_too_large_or_invalid'];continue;
            }
            $requiredCapability = str_starts_with($type, 'sales.') ? 'parsian_sales_create' : (str_starts_with($type, 'finance.') ? 'parsian_finance_write' : 'parsian_crm_write');
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
            "SELECT id,operation_uuid,user_id,device_uuid,operation_type,payload,status,result,created_at
             FROM {$wpdb->prefix}parsian_sync_ops
             WHERE status IN ('pending_review','conflict')
             ORDER BY id ASC LIMIT %d", 
            $limit
        ), ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
            $row['conflict'] = $row['status'] === 'conflict' ? (json_decode((string) ($row['result'] ?? ''), true) ?: []) : null;
            unset($row['result']);
        }
        unset($row);
        return new WP_REST_Response(['items' => $rows ?: []], 200);
    }

    private function versioned_spec(string $resource): ?array {
        global $wpdb;
        $p = $wpdb->prefix . 'parsian_';
        $specs = [
            'customer' => [
                'table'=>$p.'customers',
                'select'=>"SELECT id,display_name,phone,province,city,address,credit_limit_toman,notes,status,created_at,COALESCE(updated_at,created_at) AS updated_at FROM {$p}customers",
                'fields'=>['id','display_name','phone','province','city','address','credit_limit_toman','notes','status','created_at','updated_at'],
                'owner'=>'assigned_seller'
            ],
            'proforma' => [
                'table'=>$p.'proformas',
                'select'=>"SELECT id,document_number,customer_id,status,total_toman,due_date,notes,created_by,created_at,updated_at,invoice_id FROM {$p}proformas",
                'fields'=>['id','document_number','customer_id','status','total_toman','due_date','notes','created_by','created_at','updated_at','invoice_id'],
                'owner'=>'created_by'
            ],
            'invoice' => [
                'table'=>$p.'invoices',
                'select'=>"SELECT id,invoice_number,proforma_id,customer_id,status,total_toman,due_date,issued_by,issued_at,COALESCE(updated_at,issued_at) AS updated_at,journal_entry_id FROM {$p}invoices",
                'fields'=>['id','invoice_number','status','total_toman','due_date','issued_at','updated_at','journal_entry_id'],
                'owner'=>'issued_by'
            ],
            'payment' => [
                'table'=>$p.'payments',
                'select'=>"SELECT id,direction,amount_toman,party_type,party_id,method,reference,status,created_by,created_at,COALESCE(updated_at,created_at) AS updated_at,journal_entry_id FROM {$p}payments",
                'fields'=>['id','direction','amount_toman','party_type','party_id','method','reference','status','created_by','created_at','updated_at','journal_entry_id'],
                'owner'=>'created_by'
            ],
        ];
        return $specs[$resource] ?? null;
    }

    private function versioned_record(string $resource, int $recordId, bool $lock = false): ?array {
        global $wpdb;
        $spec = $this->versioned_spec($resource);
        if (!$spec || $recordId < 1) return null;
        $sql = $spec['select'] . ' WHERE id=%d' . ($lock ? ' FOR UPDATE' : '');
        $row = $wpdb->get_row($wpdb->prepare($sql, $recordId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function versioned_revision(string $resource, array $row): string {
        $spec = $this->versioned_spec($resource);
        if (!$spec) return '';
        $data = [];
        foreach ($spec['fields'] as $field) {
            $value = $row[$field] ?? '';
            if (in_array($field, ['id','customer_id','total_toman','created_by','invoice_id','proforma_id','issued_by','journal_entry_id','party_id','amount_toman','credit_limit_toman'], true)) {
                $value = (int)$value;
            } else {
                $value = (string)$value;
            }
            $data[$field] = $value;
        }
        if ($resource === 'proforma') {
            global $wpdb;
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT product_id,description,quantity,unit_price_toman,line_total_toman FROM {$wpdb->prefix}parsian_proforma_items WHERE proforma_id=%d ORDER BY id",
                (int)$row['id']
            ), ARRAY_A) ?: [];
            $data['items_revision'] = hash('sha256', wp_json_encode($items, JSON_UNESCAPED_UNICODE));
        }
        return hash('sha256', wp_json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function versioned_update_operation(WP_REST_Request $request, array $operation, array $payload, int $ownerId): ?WP_REST_Response {
        global $wpdb;
        $type = (string)$operation['operation_type'];
        $map = [
            'crm.customer.update'=>['resource'=>'customer','id_key'=>'customer_id','mutable'=>['display_name','phone','province','city','address','notes']],
            'sales.proforma.update'=>['resource'=>'proforma','id_key'=>'proforma_id','mutable'=>['due_date','notes']],
            'finance.invoice.update'=>['resource'=>'invoice','id_key'=>'invoice_id','mutable'=>['due_date']],
            'finance.payment.update'=>['resource'=>'payment','id_key'=>'payment_id','mutable'=>['method','reference']],
        ];
        if (!isset($map[$type])) return null;
        $config = $map[$type];
        $resource = $config['resource'];
        $recordId = absint($payload[$config['id_key']] ?? 0);
        $row = $this->versioned_record($resource, $recordId, true);
        if (!$row) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'sync_target_record_not_found','resource_type'=>$resource],404);
        }
        $assigned = true;
        if ($resource === 'customer') {
            $assigned = (bool)$wpdb->get_var($wpdb->prepare(
                "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s AND meta_value='1' LIMIT 1",
                $ownerId, 'parsian_customer_' . $recordId
            ));
        } else {
            $ownerField = $resource === 'invoice' ? 'issued_by' : ($resource === 'payment' ? 'created_by' : 'created_by');
            $assigned = (int)($row[$ownerField] ?? 0) === $ownerId;
        }
        if (!$assigned) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'sync_target_record_not_owned_by_actor'],403);
        }
        $allowedStatus = [
            'customer'=>['active'],
            'proforma'=>['draft'],
            'invoice'=>['posted','fulfilled'],
            'payment'=>['pending_approval']
        ];
        if (!in_array((string)($row['status'] ?? ''), $allowedStatus[$resource], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'sync_target_state_not_editable','status'=>$row['status']??''],409);
        }
        $serverUpdatedAt = (string)($row['updated_at'] ?? '');
        $serverRevision = $this->versioned_revision($resource, $row);
        $baseUpdatedAt = trim((string)($payload['base_updated_at'] ?? ''));
        $baseRevision = trim((string)($payload['base_revision'] ?? ''));
        if ($baseUpdatedAt === '' || $baseRevision === '' || $serverUpdatedAt === '' ||
            !hash_equals($serverUpdatedAt, $baseUpdatedAt) || !hash_equals($serverRevision, $baseRevision)) {
            $deviceChanges = array_intersect_key($payload, array_flip($config['mutable']));
            $conflict = [
                'kind'=>'record_changed_on_server','resource_type'=>$resource,'record_id'=>$recordId,
                'base_updated_at'=>$baseUpdatedAt,'server_updated_at'=>$serverUpdatedAt,
                'server_revision'=>$serverRevision,'server_record'=>$row,'device_changes'=>$deviceChanges
            ];
            $wpdb->update($wpdb->prefix.'parsian_sync_ops',[
                'status'=>'conflict','result'=>wp_json_encode($conflict)
            ],['id'=>(int)$operation['id'],'status'=>'pending_review'],['%s','%s'],['%d','%s']);
            $eventOk = $wpdb->insert($wpdb->prefix.'parsian_events',[
                'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>$ownerId,
                'event_type'=>'sync.operation.conflict','aggregate_type'=>$resource,'aggregate_id'=>$recordId,
                'payload'=>wp_json_encode(['operation_uuid'=>$operation['operation_uuid'],'resource_type'=>$resource,'record_id'=>$recordId,'reason'=>'record_changed_on_server']),
                'created_at'=>current_time('mysql',true)
            ],['%s','%d','%s','%s','%d','%s','%s']);
            if (!$eventOk) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_conflict_event_write_failed'],500); }
            $wpdb->query('COMMIT');
            return new WP_REST_Response(['status'=>'conflict','code'=>'record_changed_on_server','conflict'=>$conflict],409);
        }
        $changes = [];
        foreach ($config['mutable'] as $field) {
            if (!array_key_exists($field, $payload)) continue;
            if ($field === 'due_date') {
                $date = $payload[$field];
                if ($date !== null && $date !== '' && (!is_string($date) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date) ||
                    !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(['code'=>'sync_update_date_invalid'],422);
                }
                $changes[$field] = ($date === '') ? null : $date;
            } elseif ($field === 'credit_limit_toman') {
                $changes[$field] = absint($payload[$field]);
            } elseif ($field === 'method') {
                $method = sanitize_key((string)$payload[$field]);
                if (!in_array($method,['cash','transfer','card','cheque','other'],true)) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(['code'=>'sync_payment_method_invalid'],422);
                }
                $changes[$field] = $method;
            } elseif ($field === 'reference') {
                $value = sanitize_text_field((string)$payload[$field]);
                if (strlen($value)>190) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_payment_reference_invalid'],422); }
                $changes[$field] = $value;
            } elseif ($field === 'notes') {
                $changes[$field] = sanitize_textarea_field((string)$payload[$field]);
            } else {
                $value = trim(sanitize_text_field((string)$payload[$field]));
                $max = $field === 'display_name' ? 190 : ($field === 'phone' ? 40 : 100);
                if (($field === 'display_name' && $value === '') || strlen($value)>$max) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(['code'=>'sync_update_field_invalid','field'=>$field],422);
                }
                $changes[$field] = $value;
            }
        }
        if (!$changes) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_update_empty'],422); }
        $changes['updated_at'] = current_time('mysql',true);
        $updated = $wpdb->update($this->versioned_spec($resource)['table'],$changes,['id'=>$recordId]);
        if ($updated === false) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_versioned_update_failed'],500); }
        $now = current_time('mysql',true);
        $result = ['resource_type'=>$resource,'record_id'=>$recordId,'operation_uuid'=>$operation['operation_uuid']];
        $opUpdated = $wpdb->update($wpdb->prefix.'parsian_sync_ops',[
            'status'=>'applied','result'=>wp_json_encode($result),'processed_at'=>$now
        ],['id'=>(int)$operation['id'],'status'=>'pending_review'],['%s','%s','%s'],['%d','%s']);
        if ($opUpdated !== 1) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_operation_state_conflict'],409); }
        $eventOk = $wpdb->insert($wpdb->prefix.'parsian_events',[
            'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>$ownerId,
            'event_type'=>'sync.'.$resource.'.updated.offline','aggregate_type'=>$resource,
            'aggregate_id'=>$recordId,'payload'=>wp_json_encode([$config['id_key']=>$recordId,'approved_by'=>get_current_user_id(),'operation_uuid'=>$operation['operation_uuid']]),
            'created_at'=>$now
        ],['%s','%d','%s','%s','%d','%s','%s']);
        if (!$eventOk) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_event_write_failed'],500); }
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>'applied']+$result,200);
    }

    /**
     * Manager resolution is explicit. keep_server rejects the stale device patch;
     * apply_device rebases it to the exact current server timestamp and returns it
     * to the review queue. Approval still re-checks the timestamp under row lock.
     */
    public function resolve_conflict(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $id = absint($request['id']);
        $decision = sanitize_key((string) $request->get_param('decision'));
        if (!in_array($decision, ['keep_server','apply_device'], true)) {
            return new WP_REST_Response(['code'=>'invalid_conflict_decision'],400);
        }
        $table = $wpdb->prefix . 'parsian_sync_ops';
        $wpdb->query('START TRANSACTION');
        $operation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        if (!$operation || $operation['status'] !== 'conflict') {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'sync_conflict_not_found'],404);
        }
        if ($decision === 'keep_server') {
            $conflictData = json_decode((string)$operation['result'],true) ?: [];
            $resource = sanitize_key((string)($conflictData['resource_type'] ?? 'unknown'));
            $recordId = absint($conflictData['record_id'] ?? 0);
            $updated = $wpdb->update($table,['status'=>'rejected','result'=>wp_json_encode(['resolution'=>'kept_server','resolved_by'=>get_current_user_id(),'resolved_at'=>current_time('mysql',true)])],['id'=>$id,'status'=>'conflict'],['%s','%s'],['%d','%s']);
            if ($updated !== 1) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_conflict_state_changed'],409); }
            $eventOk = $wpdb->insert($wpdb->prefix.'parsian_events',[
                'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>(int)$operation['user_id'],
                'event_type'=>'sync.operation.rejected','aggregate_type'=>$resource,'aggregate_id'=>$recordId,
                'payload'=>wp_json_encode(['operation_uuid'=>$operation['operation_uuid'],'reason'=>'kept_server']),
                'created_at'=>current_time('mysql',true)
            ],['%s','%d','%s','%s','%d','%s','%s']);
            if (!$eventOk) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_conflict_resolution_event_failed'],500); }
            $wpdb->query('COMMIT');
            return new WP_REST_Response(['status'=>'rejected','resolution'=>'kept_server'],200);
        }
        $payload = json_decode((string)$operation['payload'],true);
        $conflict = json_decode((string)$operation['result'],true);
        $resource = sanitize_key((string)($conflict['resource_type'] ?? ''));
        $recordId = absint($conflict['record_id'] ?? 0);
        if ($resource === 'lead') {
            $currentRecord = $recordId ? $wpdb->get_row($wpdb->prepare(
                "SELECT id,display_name,phone,province,notes,status,updated_at FROM {$wpdb->prefix}parsian_leads WHERE id=%d FOR UPDATE",$recordId
            ),ARRAY_A) : null;
            if (!$currentRecord) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'conflict_record_missing'],404); }
            $currentRevision = hash('sha256', wp_json_encode([
                'id'=>(int)$currentRecord['id'],'display_name'=>(string)$currentRecord['display_name'],
                'phone'=>(string)$currentRecord['phone'],'province'=>(string)$currentRecord['province'],
                'notes'=>(string)$currentRecord['notes'],'status'=>(string)$currentRecord['status'],
                'updated_at'=>(string)$currentRecord['updated_at']
            ],JSON_UNESCAPED_UNICODE));
        } else {
            $currentRecord = $this->versioned_record($resource,$recordId,true);
            if (!$currentRecord) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'conflict_record_missing'],404); }
            $currentRevision = $this->versioned_revision($resource,$currentRecord);
        }
        $currentUpdatedAt = (string)($currentRecord['updated_at'] ?? '');
        if ($currentUpdatedAt === '' || !hash_equals((string)($conflict['server_updated_at'] ?? ''),$currentUpdatedAt) ||
            !hash_equals((string)($conflict['server_revision'] ?? ''),$currentRevision)) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'server_record_changed_again','message'=>'نسخه سرور پس از ثبت تعارض تغییر کرده است؛ فهرست تعارض‌ها را تازه‌سازی کنید.'],409);
        }
        $payload['base_updated_at'] = $currentUpdatedAt;
        $payload['base_revision'] = $currentRevision;
        $updated = $wpdb->update($table,['status'=>'pending_review','payload'=>wp_json_encode($payload),'result'=>null],['id'=>$id,'status'=>'conflict'],['%s','%s','%s'],['%d','%s']);
        if ($updated !== 1) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_conflict_state_changed'],409); }
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['status'=>'pending_review','resolution'=>'device_changes_rebased','message'=>'تغییرات گوشی به نسخه فعلی سرور مبناگذاری شد و برای تأیید نهایی مدیر برگشت.'],200);
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
        if (!in_array($operation['operation_type'], ['crm.lead.create', 'crm.lead.update', 'crm.customer.update', 'crm.task.complete', 'sales.proforma.draft', 'sales.proforma.update', 'finance.invoice.update', 'finance.payment.update'], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'operation_handler_not_available','operation_type'=>$operation['operation_type']],422);
        }
        $ownerId = (int) $operation['user_id'];
        $owner = get_userdata($ownerId);
        $operationType = (string) $operation['operation_type'];
        $financeOperation = str_starts_with($operationType, 'finance.');
        $ownerCapability = $financeOperation ? 'parsian_finance_write' : (str_starts_with($operationType, 'sales.') ? 'parsian_sales_create' : 'parsian_crm_write');
        $allowedActor = $financeOperation
            ? ($owner && (in_array('parsian_accountant', (array)$owner->roles, true) || in_array('administrator', (array)$owner->roles, true)))
            : ($owner && in_array('parsian_seller', (array)$owner->roles, true));
        if (!$owner || !$allowedActor || !user_can($owner, $ownerCapability)) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['code'=>'operation_actor_not_authorized'],403);
        }
        $payload = json_decode((string) $operation['payload'], true);
        if (!is_array($payload)) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'invalid_operation_payload'],422); }

        $versionedResult = $this->versioned_update_operation($request, $operation, $payload, $ownerId);
        if ($versionedResult instanceof WP_REST_Response) return $versionedResult;

        if ($operation['operation_type'] === 'sales.proforma.draft') {
            $customerId = absint($payload['customer_id'] ?? 0);
            $items = $payload['items'] ?? null;
            if (!$customerId || !is_array($items) || count($items) < 1 || count($items) > 100) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_proforma_payload_invalid'],422);
            }
            $assigned = $wpdb->get_var($wpdb->prepare(
                "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s AND meta_value='1' LIMIT 1",
                $ownerId, 'parsian_customer_' . $customerId
            ));
            $customer = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}parsian_customers WHERE id=%d AND status='active'",
                $customerId
            ));
            if (!$assigned || !$customer) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_proforma_customer_not_assigned'],403);
            }
            $dueDate = $payload['due_date'] ?? null;
            if ($dueDate !== null && $dueDate !== '' && (!is_string($dueDate) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$dueDate) || !checkdate((int)substr($dueDate,5,2),(int)substr($dueDate,8,2),(int)substr($dueDate,0,4)))) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_proforma_due_date_invalid'],422);
            }
            if ($dueDate === '') $dueDate = null;
            $cleanItems = [];
            $total = 0;
            foreach ($items as $item) {
                if (!is_array($item) || empty($item['product_id']) || !isset($item['quantity'],$item['unit_price_toman'])) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(['code'=>'offline_proforma_line_invalid'],422);
                }
                $productId = absint($item['product_id']);
                $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT);
                $price = filter_var($item['unit_price_toman'], FILTER_VALIDATE_INT);
                if (!$productId || $quantity === false || $quantity < 1 || $quantity > 1000000 || $price === false || $price < 0 || $price > 1000000000000) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(['code'=>'offline_proforma_amount_invalid'],422);
                }
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,name FROM {$wpdb->prefix}parsian_products WHERE id=%d AND status='active' FOR UPDATE",
                    $productId
                ), ARRAY_A);
                if (!$product || ($price > 0 && $quantity > intdiv(PHP_INT_MAX,$price))) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(['code'=>'offline_proforma_product_or_total_invalid'],422);
                }
                $lineTotal = $quantity * $price;
                if ($lineTotal > 1000000000000 - $total) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(['code'=>'offline_proforma_total_limit'],422);
                }
                $total += $lineTotal;
                $cleanItems[] = ['product_id'=>$productId,'description'=>$product['name'],'quantity'=>$quantity,'unit_price_toman'=>$price,'line_total_toman'=>$lineTotal];
            }
            if ($total < 1) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_proforma_zero_total'],422);
            }
            $now = current_time('mysql',true);
            $number = 'PF-'.gmdate('ymd').'-'.strtoupper(wp_generate_password(6,false,false));
            $created = $wpdb->insert($wpdb->prefix.'parsian_proformas',[
                'document_number'=>$number,'customer_id'=>$customerId,'status'=>'draft','currency'=>'IRR',
                'total_toman'=>$total,'due_date'=>$dueDate,'notes'=>sanitize_textarea_field((string)($payload['notes']??'')),
                'created_by'=>$ownerId,'created_at'=>$now,'updated_at'=>$now
            ],['%s','%d','%s','%s','%d','%s','%s','%d','%s','%s']);
            if (!$created) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_proforma_create_failed'],500);
            }
            $proformaId = (int)$wpdb->insert_id;
            foreach ($cleanItems as $line) {
                $line['proforma_id'] = $proformaId;
                if (!$wpdb->insert($wpdb->prefix.'parsian_proforma_items',$line,['%d','%s','%d','%d','%d','%d'])) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(['code'=>'offline_proforma_item_create_failed'],500);
                }
            }
            $result = ['proforma_id'=>$proformaId,'document_number'=>$number,'total_toman'=>$total,'operation_uuid'=>$operation['operation_uuid']];
            $operationUpdated = $wpdb->update($table,[
                'status'=>'applied','result'=>wp_json_encode($result),'processed_at'=>$now
            ],['id'=>$id,'status'=>'pending_review'],['%s','%s','%s'],['%d','%s']);
            if ($operationUpdated !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'sync_operation_state_conflict'],409);
            }
            $eventOk = $wpdb->insert($wpdb->prefix.'parsian_events',[
                'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>$ownerId,
                'event_type'=>'sales.proforma.created.offline','aggregate_type'=>'proforma','aggregate_id'=>$proformaId,
                'payload'=>wp_json_encode(['proforma_id'=>$proformaId,'customer_id'=>$customerId,'total_toman'=>$total,'approved_by'=>get_current_user_id(),'operation_uuid'=>$operation['operation_uuid']]),
                'created_at'=>$now
            ],['%s','%d','%s','%s','%d','%s','%s']);
            if (!$eventOk) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'sync_event_write_failed'],500);
            }
            $wpdb->query('COMMIT');
            return new WP_REST_Response(['status'=>'applied'] + $result,201);
        }

        if ($operation['operation_type'] === 'crm.task.complete') {
            $taskId = absint($payload['task_id'] ?? 0);
            if (!$taskId || ($payload['completed'] ?? true) !== true) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_task_payload_invalid'],422);
            }
            $task = $wpdb->get_row($wpdb->prepare(
                "SELECT id,assigned_user_id,status FROM {$wpdb->prefix}parsian_tasks WHERE id=%d FOR UPDATE",
                $taskId
            ), ARRAY_A);
            if (!$task || (int) $task['assigned_user_id'] !== $ownerId) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_task_not_assigned_to_actor'],403);
            }
            if ($task['status'] !== 'open') {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_task_not_open','status'=>$task['status']],409);
            }
            $now = current_time('mysql', true);
            $taskUpdated = $wpdb->update($wpdb->prefix . 'parsian_tasks', [
                'status'=>'completed','completed_at'=>$now,'completed_by'=>$ownerId,'updated_at'=>$now
            ], ['id'=>$taskId,'assigned_user_id'=>$ownerId,'status'=>'open'], ['%s','%s','%d','%s'], ['%d','%d','%s']);
            if ($taskUpdated !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_task_state_conflict'],409);
            }
            $result = ['task_id'=>$taskId,'operation_uuid'=>$operation['operation_uuid']];
            $operationUpdated = $wpdb->update($table, [
                'status'=>'applied','result'=>wp_json_encode($result),'processed_at'=>$now
            ], ['id'=>$id,'status'=>'pending_review'], ['%s','%s','%s'], ['%d','%s']);
            if ($operationUpdated !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'sync_operation_state_conflict'],409);
            }
            $eventOk = $wpdb->insert($wpdb->prefix . 'parsian_events', [
                'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>$ownerId,
                'event_type'=>'crm.task.completed.offline','aggregate_type'=>'task','aggregate_id'=>$taskId,
                'payload'=>wp_json_encode(['task_id'=>$taskId,'assigned_user_id'=>$ownerId,'approved_by'=>get_current_user_id(),'operation_uuid'=>$operation['operation_uuid']]),
                'created_at'=>$now
            ], ['%s','%d','%s','%s','%d','%s','%s']);
            if (!$eventOk) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'sync_event_write_failed'],500);
            }
            $wpdb->query('COMMIT');
            return new WP_REST_Response(['status'=>'applied'] + $result,200);
        }

        if ($operation['operation_type'] === 'crm.lead.update') {
            $leadId = absint($payload['lead_id'] ?? 0);
            $lead = $leadId ? $wpdb->get_row($wpdb->prepare(
                "SELECT id, owner_user_id, display_name, phone, province, notes, status, updated_at FROM {$wpdb->prefix}parsian_leads WHERE id=%d FOR UPDATE",
                $leadId
            ), ARRAY_A) : null;
            if (!$lead || (int) $lead['owner_user_id'] !== $ownerId) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(['code'=>'offline_lead_not_owned_by_actor'],403);
            }

            // Optimistic concurrency: an offline edit must carry the exact server timestamp
            // from the snapshot it was based on. Missing/unknown bases fail closed.
            $baseUpdatedAt = isset($payload['base_updated_at']) ? trim((string) $payload['base_updated_at']) : '';
            $baseRevision = isset($payload['base_revision']) ? trim((string) $payload['base_revision']) : '';
            $serverUpdatedAt = (string) ($lead['updated_at'] ?? '');
            $serverRevision = hash('sha256', wp_json_encode([
                'id' => (int) $lead['id'],
                'display_name' => (string) ($lead['display_name'] ?? ''),
                'phone' => (string) ($lead['phone'] ?? ''),
                'province' => (string) ($lead['province'] ?? ''),
                'notes' => (string) ($lead['notes'] ?? ''),
                'status' => (string) ($lead['status'] ?? ''),
                'updated_at' => $serverUpdatedAt,
            ], JSON_UNESCAPED_UNICODE));
            if ($baseUpdatedAt === '' || $baseRevision === '' || $serverUpdatedAt === '' ||
                !hash_equals($serverUpdatedAt, $baseUpdatedAt) || !hash_equals($serverRevision, $baseRevision)) {
                $conflict = [
                    'kind' => 'record_changed_on_server',
                    'resource_type' => 'lead',
                    'record_id' => $leadId,
                    'base_updated_at' => $baseUpdatedAt,
                    'server_updated_at' => $serverUpdatedAt,
                    'server_revision' => $serverRevision,
                    'server_record' => [
                        'id' => (int) $lead['id'], 'display_name' => $lead['display_name'],
                        'phone' => $lead['phone'], 'province' => $lead['province'],
                        'notes' => $lead['notes'], 'updated_at' => $serverUpdatedAt
                    ],
                    'device_changes' => array_intersect_key($payload, array_flip(['display_name','phone','province','notes']))
                ];
                $wpdb->update($table, ['status'=>'conflict','result'=>wp_json_encode($conflict)], ['id'=>$id,'status'=>'pending_review'], ['%s','%s'], ['%d','%s']);
                $eventOk = $wpdb->insert($wpdb->prefix.'parsian_events',[
                    'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>$ownerId,
                    'event_type'=>'sync.operation.conflict','aggregate_type'=>'lead','aggregate_id'=>$leadId,
                    'payload'=>wp_json_encode(['operation_uuid'=>$operation['operation_uuid'],'resource_type'=>'lead','record_id'=>$leadId,'reason'=>'record_changed_on_server']),
                    'created_at'=>current_time('mysql',true)
                ],['%s','%d','%s','%s','%d','%s','%s']);
                if (!$eventOk) { $wpdb->query('ROLLBACK'); return new WP_REST_Response(['code'=>'sync_conflict_event_write_failed'],500); }
                $wpdb->query('COMMIT');
                return new WP_REST_Response(['status'=>'conflict','code'=>'record_changed_on_server','conflict'=>$conflict],409);
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
            'owner_user_id'=>$ownerId,'status'=>'new','created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)
        ], ['%s','%s','%s','%s','%d','%s','%s','%s']);
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
