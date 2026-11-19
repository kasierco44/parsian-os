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
$auth=new Parsian_Final_Auth();$sync=new Parsian_Final_Sync();
$oldAuthorization=$_SERVER['HTTP_AUTHORIZATION']??null;
try {
    $suffix=wp_generate_password(10,false,false);
    $password=wp_generate_password(32,true,true);
    $userId=wp_insert_user([
        'user_login'=>'parsian_qa_'.$suffix,'user_pass'=>$password,
        'user_email'=>'parsian-qa-'.$suffix.'@example.invalid','role'=>'subscriber'
    ]);
    if(is_wp_error($userId))throw new RuntimeException($userId->get_error_message());
    $user=new WP_User($userId);
    $user->add_cap('parsian_access_core');
    $user->add_cap('parsian_sync_use');

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
    $push->set_param('device_uuid',$device);
    $push->set_param('operations',[[
        'operation_uuid'=>$operation,'type'=>'crm.task.complete',
        'payload'=>['task_id'=>987654321,'completed'=>true]
    ]]);
    $first=$sync->push($push);
    $firstData=$first->get_data();
    $assert($first->get_status()===202,'sync push accepts batch for review');
    $assert(($firstData['results'][0]['status']??'')==='pending_review','offline operation is queued, not auto-posted');

    $second=$sync->push($push);
    $secondData=$second->get_data();
    $assert(($secondData['results'][0]['duplicate']??false)===true,'identical operation retry is idempotent');

    $push->set_param('operations',[[
        'operation_uuid'=>$operation,'type'=>'crm.task.complete',
        'payload'=>['task_id'=>987654321,'completed'=>false]
    ]]);
    $conflict=$sync->push($push)->get_data();
    $assert(($conflict['results'][0]['code']??'')==='operation_uuid_conflict','same operation UUID with changed payload is rejected');

    $revoke=new WP_REST_Request('POST','/parsian-final/v1/auth/revoke-device');
    $revoke->set_param('device_uuid',$device);
    $revoked=$auth->revoke_device($revoke);
    $assert($revoked->get_status()===200,'device revocation succeeds');
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$refreshData['access_token']['token'];
    $assert((int)$auth->authenticate_bearer(0)===0,'revoked device access token is rejected');

    WP_CLI::success('Auth/sync acceptance passed: login, access token, refresh rotation/reuse rejection, device-bound idempotent sync, payload conflict and device revocation.');
} finally {
    if($oldAuthorization===null)unset($_SERVER['HTTP_AUTHORIZATION']);else $_SERVER['HTTP_AUTHORIZATION']=$oldAuthorization;
    if($userId){
        $wpdb->delete($wpdb->prefix.'parsian_auth_tokens',['user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_sync_ops',['user_id'=>$userId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_devices',['user_id'=>$userId],['%d']);
        require_once ABSPATH.'wp-admin/includes/user.php';
        wp_delete_user($userId);
    }
}
