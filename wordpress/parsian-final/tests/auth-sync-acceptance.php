<?php
/**
 * Auth + offline sync acceptance test. WP-CLI only, isolated DB only.
 *
 * Run:
 *   define('PARSIAN_ALLOW_FINANCE_TESTS', true);
 *   wp eval-file wordpress/parsian-final/tests/auth-sync-acceptance.php
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('Run through WP-CLI only.');
if (!defined('PARSIAN_ALLOW_FINANCE_TESTS') || PARSIAN_ALLOW_FINANCE_TESTS !== true) {
    throw new RuntimeException('Explicit staging test opt-in is required.');
}
if (function_exists('wp_get_environment_type') && wp_get_environment_type()==='production') {
    throw new RuntimeException('Auth/sync fixtures are blocked on production.');
}
global $wpdb;
$assert=static function($condition,string $message):void {
    if(!$condition)throw new RuntimeException('ASSERTION FAILED: '.$message);
};
$userId=0;$customerId=0;$productId=0;$device=wp_generate_uuid4();$operation=wp_generate_uuid4();
$authTable=$wpdb->prefix.'parsian_auth_tokens';
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$authTable))===$authTable,'token auth table exists after plugin migration');
$auth=new Parsian_Final_Auth();$sync=new Parsian_Final_Sync();
$oldAuthorization=$_SERVER['HTTP_AUTHORIZATION']??null;
try {
    $suffix=wp_generate_password(10,false,false);
    $password=wp_generate_password(32,true,true);
    $userId=wp_insert_user([
        'user_login'=>'parsian_qa_'.$suffix,'user_pass'=>$password,
        'user_email'=>'parsian-qa-'.$suffix.'@example.invalid','role'=>'parsian_seller'
    ]);
    if(is_wp_error($userId))throw new RuntimeException($userId->get_error_message());
    $user=new WP_User($userId);
    $user->add_cap('parsian_access_core');
    $user->add_cap('parsian_sync_use');
    $user->add_cap('parsian_sales_create');
    // Test fixture has manager review capability so the queue processor can be exercised.
    $user->add_cap('parsian_manage_users');

    $login=new WP_REST_Request('POST','/parsian-final/v1/auth/login');
    $login->set_param('username','parsian_qa_'.$suffix);
    $login->set_param('password',$password);
    $login->set_param('device_uuid',$device);
    $login->set_param('platform','android');
    $login->set_param('device_label','QA isolated device');
    $loginResponse=$auth->login($login);
    $loginData=$loginResponse->get_data();
    $assert($loginResponse->get_status()===200,'login returns 200');
    $assert(!empty($loginData['access_token']['token'])&&!empty($loginData['refresh_token']['token']),'login issues access and refresh tokens');
    $storedHash=$wpdb->get_var($wpdb->prepare("SELECT token_hash FROM {$authTable} WHERE user_id=%d AND token_type='access' ORDER BY id DESC LIMIT 1",$userId));
    $assert(is_string($storedHash)&&hash_equals(hash('sha256',$loginData['access_token']['token']),$storedHash),'database stores only SHA-256 token hash');
    $assert($storedHash!==$loginData['access_token']['token'],'raw access token is not stored in database');

    if(!defined('REST_REQUEST'))define('REST_REQUEST',true);
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$loginData['access_token']['token'];
    $assert((int)$auth->authenticate_bearer(0)===(int)$userId,'valid access token resolves the user');

    $refresh=new WP_REST_Request('POST','/parsian-final/v1/auth/refresh');
    $refresh->set_param('refresh_token',$loginData['refresh_token']['token']);
    $refreshResponse=$auth->refresh($refresh);
    $refreshData=$refreshResponse->get_data();
    $assert($refreshResponse->get_status()===200,'refresh rotates token pair');
    $assert($refreshData['refresh_token']['token']!==$loginData['refresh_token']['token'],'refresh token is rotated');

    $reuse=$auth->refresh($refresh);
    $assert($reuse->get_status()===401,'old refresh token cannot be reused');

    wp_set_current_user($userId);
    $push=new WP_REST_Request('POST','/parsian-final/v1/sync/push');
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$operation,'type'=>'crm.task.complete',
        'payload'=>['task_id'=>987654321,'completed'=>true]
    ]]]));
    $first=$sync->push($push);
    $firstData=$first->get_data();
    $assert($first->get_status()===202,'sync push accepts batch for review');
    $assert(($firstData['results'][0]['status']??'')==='pending_review','offline operation is queued, not auto-posted');

    $second=$sync->push($push);
    $secondData=$second->get_data();
    $assert(($secondData['results'][0]['duplicate']??false)===true,'identical operation retry is idempotent');

    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$operation,'type'=>'crm.task.complete',
        'payload'=>['task_id'=>987654321,'completed'=>false]
    ]]]));
    $conflict=$sync->push($push)->get_data();
    $assert(($conflict['results'][0]['code']??'')==='operation_uuid_conflict','same operation UUID with changed payload is rejected');

    $customerOk = $wpdb->insert($wpdb->prefix.'parsian_customers',[
        'display_name'=>'Offline QA Customer','phone'=>'09121111111','province'=>'QA','city'=>'QA',
        'credit_limit_toman'=>0,'notes'=>'','status'=>'active','created_by'=>$userId,'created_at'=>current_time('mysql',true)
    ],['%s','%s','%s','%s','%d','%s','%s','%d','%s']);
    $assert($customerOk===1,'offline proforma customer fixture is created');
    $customerId = (int)$wpdb->insert_id;
    update_user_meta($userId,'parsian_customer_'.$customerId,'1');
    $productOk = $wpdb->insert($wpdb->prefix.'parsian_products',[
        'sku'=>'QA-OFFLINE-'.wp_generate_password(5,false,false),'name'=>'Offline QA Product',
        'unit'=>'عدد','unit_price_toman'=>1000,'status'=>'active','created_by'=>$userId,'created_at'=>current_time('mysql',true)
    ],['%s','%s','%s','%d','%s','%d','%s']);
    $assert($productOk===1,'offline proforma product fixture is created');
    $productId = (int)$wpdb->insert_id;
    $proformaOperation = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$proformaOperation,'type'=>'sales.proforma.draft',
        'payload'=>['customer_id'=>$customerId,'items'=>[['product_id'=>$productId,'quantity'=>2,'unit_price_toman'=>1000]]]
    ]]]));
    $proformaQueued = $sync->push($push)->get_data();
    $assert(($proformaQueued['results'][0]['status']??'')==='pending_review','namespaced sales.proforma.draft operation is accepted into manager review queue');
    $proformaOperationId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$proformaOperation));
    $proformaApprove = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$proformaOperationId.'/approve');
    $proformaApprove->set_param('id',$proformaOperationId);
    $proformaApplied = $sync->approve_operation($proformaApprove);
    $assert($proformaApplied->get_status()===201 && ($proformaApplied->get_data()['status']??'')==='applied','manager approval creates offline proforma draft');
    $proformaId = (int)($proformaApplied->get_data()['proforma_id']??0);
    $proformaRow = $wpdb->get_row($wpdb->prepare("SELECT created_by,customer_id,status,total_toman FROM {$wpdb->prefix}parsian_proformas WHERE id=%d",$proformaId),ARRAY_A);
    $assert($proformaId>0 && (int)$proformaRow['created_by']===(int)$userId && (int)$proformaRow['customer_id']===$customerId && $proformaRow['status']==='draft' && (int)$proformaRow['total_toman']===2000,'offline proforma preserves seller ownership and validated line totals');

    $leadOperation=wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$leadOperation,'type'=>'crm.lead.create',
        'payload'=>['name'=>'Offline QA Lead','phone'=>'09120000000','province'=>'QA']
    ]]]));
    $leadQueued=$sync->push($push)->get_data();
    $assert(($leadQueued['results'][0]['status']??'')==='pending_review','offline lead is queued for manager review');
    $leadOperationId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$leadOperation));
    $approveRequest=new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$leadOperationId.'/approve');
    $approveRequest->set_param('id',$leadOperationId);
    $approved=$sync->approve_operation($approveRequest);
    $approvedData=$approved->get_data();
    $assert($approved->get_status()===201&&($approvedData['status']??'')==='applied','manager review applies offline lead exactly once');
    $createdLead=(int)($approvedData['lead_id']??0);
    $assert($createdLead>0&&(int)$wpdb->get_var($wpdb->prepare("SELECT owner_user_id FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead))===(int)$userId,'approved offline lead remains owned by its originating seller');

    $leadBaseUpdatedAt = (string)$wpdb->get_var($wpdb->prepare("SELECT updated_at FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead));
    $assert($leadBaseUpdatedAt !== '', 'offline lead has a server modification timestamp');
    $updateOperation = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$updateOperation,'type'=>'crm.lead.update',
        'payload'=>['lead_id'=>$createdLead,'display_name'=>'Updated Offline QA Lead','province'=>'Tehran','base_updated_at'=>$leadBaseUpdatedAt]
    ]]]));
    $updateQueued = $sync->push($push)->get_data();
    $assert(($updateQueued['results'][0]['status']??'')==='pending_review','offline lead update is queued for manager review');
    $updateId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$updateOperation));
    $updateRequest = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$updateId.'/approve');
    $updateRequest->set_param('id',$updateId);
    $updatedLeadResponse = $sync->approve_operation($updateRequest);
    $assert($updatedLeadResponse->get_status()===200 && ($updatedLeadResponse->get_data()['status']??'')==='applied','manager approval applies offline lead update');
    $updatedLeadRow = $wpdb->get_row($wpdb->prepare("SELECT display_name,province FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead),ARRAY_A);
    $assert(($updatedLeadRow['display_name']??'')==='Updated Offline QA Lead' && ($updatedLeadRow['province']??'')==='Tehran','offline lead update changes only the originating seller lead');

    // A concurrent server edit after the device snapshot must stop the stale patch.
    $conflictBase = (string)$wpdb->get_var($wpdb->prepare("SELECT updated_at FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead));
    $conflictOperation = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$conflictOperation,'type'=>'crm.lead.update',
        'payload'=>['lead_id'=>$createdLead,'display_name'=>'Stale Device Name','base_updated_at'=>$conflictBase]
    ]]]));
    $conflictQueued = $sync->push($push)->get_data();
    $assert(($conflictQueued['results'][0]['status']??'')==='pending_review','concurrent edit fixture is queued');
    $conflictId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$conflictOperation));
    $serverNewerAt = gmdate('Y-m-d H:i:s', time()+10);
    $wpdb->update($wpdb->prefix.'parsian_leads',['display_name'=>'Server Newer Name','updated_at'=>$serverNewerAt],['id'=>$createdLead],['%s','%s'],['%d']);
    $conflictApprove = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$conflictId.'/approve');
    $conflictApprove->set_param('id',$conflictId);
    $conflictResponse = $sync->approve_operation($conflictApprove);
    $assert($conflictResponse->get_status()===409 && ($conflictResponse->get_data()['status']??'')==='conflict','stale device edit is preserved as a conflict, not applied');
    $conflictRow = $wpdb->get_row($wpdb->prepare("SELECT status,result FROM {$wpdb->prefix}parsian_sync_ops WHERE id=%d",$conflictId),ARRAY_A);
    $conflictData = json_decode((string)$conflictRow['result'],true);
    $assert($conflictRow['status']==='conflict' && ($conflictData['server_record']['display_name']??'')==='Server Newer Name','manager conflict payload contains the newer server record');
    $resolveKeep = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$conflictId.'/resolve-conflict');
    $resolveKeep->set_param('id',$conflictId);
    $resolveKeep->set_param('decision','keep_server');
    $keptServer = $sync->resolve_conflict($resolveKeep);
    $assert($keptServer->get_status()===200 && ($keptServer->get_data()['resolution']??'')==='kept_server','manager can explicitly keep the server version');
    $finalLeadName = $wpdb->get_var($wpdb->prepare("SELECT display_name FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead));
    $assert($finalLeadName==='Server Newer Name','stale device payload never overwrites the newer server value');

    $crm = new Parsian_Final_CRM();
    $taskRequest = new WP_REST_Request('POST','/parsian-final/v1/tasks');
    $taskRequest->set_param('title','Offline completion QA task');
    $taskRequest->set_param('description','Acceptance fixture');
    $taskRequest->set_param('assigned_user_id',$userId);
    $taskCreated = $crm->create_task($taskRequest);
    $assert($taskCreated->get_status()===201,'manager task assignment endpoint creates a seller task');
    $taskId = (int)($taskCreated->get_data()['id']??0);
    $taskOperation = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$taskOperation,'type'=>'crm.task.complete',
        'payload'=>['task_id'=>$taskId,'completed'=>true]
    ]]]));
    $taskQueued = $sync->push($push)->get_data();
    $assert(($taskQueued['results'][0]['status']??'')==='pending_review','offline task completion waits for manager approval');
    $taskOperationId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$taskOperation));
    $taskApprove = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$taskOperationId.'/approve');
    $taskApprove->set_param('id',$taskOperationId);
    $taskApproved = $sync->approve_operation($taskApprove);
    $assert($taskApproved->get_status()===200 && ($taskApproved->get_data()['status']??'')==='applied','manager approval applies offline task completion');
    $assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}parsian_tasks WHERE id=%d",$taskId))==='completed','approved task is marked completed in the task register');

    // Verify seller event isolation and cursor progress over another user's event.
    $foreignEventId = 0;
    $foreignEventOk = $wpdb->insert($wpdb->prefix.'parsian_events', [
        'event_uuid'=>wp_generate_uuid4(),'actor_user_id'=>0,
        'event_type'=>'crm.foreign.qa','aggregate_type'=>'qa','aggregate_id'=>1,
        'payload'=>wp_json_encode(['must_not_leak'=>true]),'created_at'=>current_time('mysql',true)
    ], ['%s','%d','%s','%s','%d','%s','%s']);
    $assert($foreignEventOk===1,'foreign fixture event is inserted');
    $foreignEventId=(int)$wpdb->insert_id;
    $user->remove_cap('parsian_manage_users');
    wp_set_current_user(0);
    wp_set_current_user($userId); // Refresh the current user's capability snapshot.

    $pullRequest=new WP_REST_Request('GET','/parsian-final/v1/sync/pull');
    $pullRequest->set_param('since_id',0);
    $pulled=$sync->pull($pullRequest)->get_data();
    $assert((bool)array_filter($pulled['items']??[],static fn($event)=>($event['event_type']??'')==='crm.lead.created.offline'),'originating seller can pull the offline approval event');
    $assert(!(bool)array_filter($pulled['items']??[],static fn($event)=>($event['event_type']??'')==='crm.foreign.qa'),'seller cannot pull another user event');
    $assert((int)($pulled['next_cursor']??0)>=$foreignEventId,'seller delta cursor advances over filtered foreign events');
    $user->add_cap('parsian_manage_users');
    wp_set_current_user(0);
    wp_set_current_user($userId);

    $rejectOperation=wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$rejectOperation,'type'=>'crm.task.complete',
        'payload'=>['task_id'=>987654322,'completed'=>true]
    ]]]));
    $sync->push($push);
    $rejectId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$rejectOperation));
    $rejectRequest=new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$rejectId.'/reject');
    $rejectRequest->set_param('id',$rejectId);
    $rejectRequest->set_param('reason','QA rejection');
    $rejected=$sync->reject_operation($rejectRequest);
    $assert($rejected->get_status()===200&&($rejected->get_data()['status']??'')==='rejected','manager can reject unsupported offline operation');

    $revoke=new WP_REST_Request('POST','/parsian-final/v1/auth/revoke-device');
    $revoke->set_param('device_uuid',$device);
    $revoked=$auth->revoke_device($revoke);
    $assert($revoked->get_status()===200,'device revocation succeeds');
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$refreshData['access_token']['token'];
    $assert((int)$auth->authenticate_bearer(0)===0,'revoked device access token is rejected');

    WP_CLI::success('Auth/sync acceptance passed: token lifecycle, idempotent sync, namespaced operation types, manager lead/task/proforma approval, seller event isolation and device revocation.');
} finally {
    if($oldAuthorization===null)unset($_SERVER['HTTP_AUTHORIZATION']);else $_SERVER['HTTP_AUTHORIZATION']=$oldAuthorization;
    if($userId){
        $wpdb->delete($wpdb->prefix.'parsian_auth_tokens',['user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_sync_ops',['user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_devices',['user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_leads',['owner_user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_tasks',['assigned_user_id'=>$userId],['%d']);
        $proformaIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_proformas WHERE created_by=%d",$userId));
        foreach ((array)$proformaIds as $proformaId) {
            $wpdb->delete($wpdb->prefix.'parsian_proforma_items',['proforma_id'=>(int)$proformaId],['%d']);
            $wpdb->delete($wpdb->prefix.'parsian_proformas',['id'=>(int)$proformaId],['%d']);
        }
        $wpdb->delete($wpdb->prefix.'parsian_customers',['created_by'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_products',['created_by'=>$userId],['%d']);
        if ($customerId > 0) delete_user_meta($userId,'parsian_customer_'.$customerId);

        $wpdb->delete($wpdb->prefix.'parsian_events',['actor_user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_events',['event_type'=>'crm.foreign.qa'],['%s']);
        require_once ABSPATH.'wp-admin/includes/user.php';
        wp_delete_user($userId);
    }
}
