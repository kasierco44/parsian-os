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
$userId=0;$device=wp_generate_uuid4();$operation=wp_generate_uuid4();
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

    $pullRequest=new WP_REST_Request('GET','/parsian-final/v1/sync/pull');
    $pullRequest->set_param('since_id',0);
    $pulled=$sync->pull($pullRequest)->get_data();
    $assert((bool)array_filter($pulled['items']??[],static fn($event)=>($event['event_type']??'')==='crm.lead.created.offline'),'originating seller can pull the offline approval event');
    $assert(!(bool)array_filter($pulled['items']??[],static fn($event)=>($event['event_type']??'')==='crm.foreign.qa'),'seller cannot pull another user event');
    $assert((int)($pulled['next_cursor']??0)>=$foreignEventId,'seller delta cursor advances over filtered foreign events');
    $user->add_cap('parsian_manage_users');

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

    WP_CLI::success('Auth/sync acceptance passed: login, access token, refresh rotation/reuse rejection, device-bound idempotent sync, payload conflict, manager lead approval/rejection, seller event visibility and device revocation.');
} finally {
    if($oldAuthorization===null)unset($_SERVER['HTTP_AUTHORIZATION']);else $_SERVER['HTTP_AUTHORIZATION']=$oldAuthorization;
    if($userId){
        $wpdb->delete($wpdb->prefix.'parsian_auth_tokens',['user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_sync_ops',['user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_devices',['user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_leads',['owner_user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_events',['actor_user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_events',['event_type'=>'crm.foreign.qa'],['%s']);
        require_once ABSPATH.'wp-admin/includes/user.php';
        wp_delete_user($userId);
    }
}
