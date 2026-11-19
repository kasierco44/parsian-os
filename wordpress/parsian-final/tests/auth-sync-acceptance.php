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
$userId=0;$financeUserId=0;$customerId=0;$productId=0;$invoiceId=0;$paymentId=0;$device=wp_generate_uuid4();$financeDevice=wp_generate_uuid4();$operation=wp_generate_uuid4();
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

    // Customer revision conflict: preserve server-side changes and allow explicit rebase.
    $crm = new Parsian_Final_CRM();
    $customerList = $crm->customers(new WP_REST_Request('GET','/parsian-final/v1/customers'))->get_data();
    $customerSnapshot = null;
    foreach (($customerList['items']??[]) as $candidate) if ((int)$candidate['id']===$customerId) {$customerSnapshot=$candidate;break;}
    $assert(is_array($customerSnapshot) && !empty($customerSnapshot['revision']) && !empty($customerSnapshot['updated_at']),'customer API exposes revision and timestamp');
    $customerUpdateUuid = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$customerUpdateUuid,'type'=>'crm.customer.update',
        'payload'=>['customer_id'=>$customerId,'notes'=>'Offline customer note','base_updated_at'=>$customerSnapshot['updated_at'],'base_revision'=>$customerSnapshot['revision']]
    ]]]));
    $customerQueued = $sync->push($push)->get_data();
    $assert(($customerQueued['results'][0]['status']??'')==='pending_review','offline customer update enters review queue');
    $customerOperationId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$customerUpdateUuid));
    $wpdb->update($wpdb->prefix.'parsian_customers',['province'=>'Server Province','updated_at'=>gmdate('Y-m-d H:i:s',time()+30)],['id'=>$customerId],['%s','%s'],['%d']);
    $customerApprove = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$customerOperationId.'/approve');
    $customerApprove->set_param('id',$customerOperationId);
    $customerConflict = $sync->approve_operation($customerApprove);
    $assert($customerConflict->get_status()===409 && ($customerConflict->get_data()['status']??'')==='conflict','stale customer edit is flagged as a conflict');
    $customerConflictRow = $wpdb->get_row($wpdb->prepare("SELECT result FROM {$wpdb->prefix}parsian_sync_ops WHERE id=%d",$customerOperationId),ARRAY_A);
    $customerConflictData = json_decode((string)$customerConflictRow['result'],true);
    $assert(($customerConflictData['server_record']['province']??'')==='Server Province','customer conflict includes the latest server value');
    $customerRebase = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$customerOperationId.'/resolve-conflict');
    $customerRebase->set_param('id',$customerOperationId);$customerRebase->set_param('decision','apply_device');
    $assert($sync->resolve_conflict($customerRebase)->get_status()===200,'manager can rebase customer changes');
    $customerApproveAgain = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$customerOperationId.'/approve');
    $customerApproveAgain->set_param('id',$customerOperationId);
    $customerApplied = $sync->approve_operation($customerApproveAgain);
    $assert($customerApplied->get_status()===200 && $wpdb->get_var($wpdb->prepare("SELECT notes FROM {$wpdb->prefix}parsian_customers WHERE id=%d",$customerId))==='Offline customer note','rebased customer change applies only after manager approval');

    // Proforma revision conflict includes line items in its digest and only edits drafts.
    $sales = new Parsian_Final_Sales();
    $proformaList = $sales->list_proformas(new WP_REST_Request('GET','/parsian-final/v1/proformas'))->get_data();
    $proformaSnapshot = null;
    foreach (($proformaList['items']??[]) as $candidate) if ((int)$candidate['id']===$proformaId) {$proformaSnapshot=$candidate;break;}
    $assert(is_array($proformaSnapshot) && !empty($proformaSnapshot['revision']) && !empty($proformaSnapshot['updated_at']),'proforma API exposes revision and timestamp');
    $proformaUpdateUuid = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$proformaUpdateUuid,'type'=>'sales.proforma.update',
        'payload'=>['proforma_id'=>$proformaId,'notes'=>'Device proforma note','base_updated_at'=>$proformaSnapshot['updated_at'],'base_revision'=>$proformaSnapshot['revision']]
    ]]]));
    $proformaUpdateQueued = $sync->push($push)->get_data();
    $assert(($proformaUpdateQueued['results'][0]['status']??'')==='pending_review','offline proforma update enters review queue');
    $proformaUpdateId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$proformaUpdateUuid));
    $wpdb->update($wpdb->prefix.'parsian_proformas',['notes'=>'Server proforma note','updated_at'=>gmdate('Y-m-d H:i:s',time()+40)],['id'=>$proformaId],['%s','%s'],['%d']);
    $proformaUpdateApprove = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$proformaUpdateId.'/approve');
    $proformaUpdateApprove->set_param('id',$proformaUpdateId);
    $proformaConflict = $sync->approve_operation($proformaUpdateApprove);
    $assert($proformaConflict->get_status()===409 && ($proformaConflict->get_data()['status']??'')==='conflict','stale proforma edit is flagged as a conflict');
    $proformaConflictRow = $wpdb->get_row($wpdb->prepare("SELECT result FROM {$wpdb->prefix}parsian_sync_ops WHERE id=%d",$proformaUpdateId),ARRAY_A);
    $proformaConflictData = json_decode((string)$proformaConflictRow['result'],true);
    $assert(($proformaConflictData['server_record']['notes']??'')==='Server proforma note','proforma conflict includes the current server notes');
    $proformaKeep = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$proformaUpdateId.'/resolve-conflict');
    $proformaKeep->set_param('id',$proformaUpdateId);$proformaKeep->set_param('decision','keep_server');
    $assert($sync->resolve_conflict($proformaKeep)->get_status()===200,'manager can keep server proforma revision');
    $assert($wpdb->get_var($wpdb->prepare("SELECT notes FROM {$wpdb->prefix}parsian_proformas WHERE id=%d",$proformaId))==='Server proforma note','keeping server does not overwrite proforma data');

    // A line-item-only server edit must change the proforma revision even if updated_at is unchanged.
    $proformaListAfterKeep = $sales->list_proformas(new WP_REST_Request('GET','/parsian-final/v1/proformas'))->get_data();
    $proformaSnapshotAfterKeep = null;
    foreach (($proformaListAfterKeep['items']??[]) as $candidate) if ((int)$candidate['id']===$proformaId) {$proformaSnapshotAfterKeep=$candidate;break;}
    $itemOnlyUuid = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$itemOnlyUuid,'type'=>'sales.proforma.update',
        'payload'=>['proforma_id'=>$proformaId,'notes'=>'Stale item snapshot edit','base_updated_at'=>$proformaSnapshotAfterKeep['updated_at'],'base_revision'=>$proformaSnapshotAfterKeep['revision']]
    ]]]));
    $itemOnlyQueued = $sync->push($push)->get_data();
    $assert(($itemOnlyQueued['results'][0]['status']??'')==='pending_review','line-item revision fixture is queued');
    $itemOnlyOperationId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$itemOnlyUuid));
    $wpdb->update($wpdb->prefix.'parsian_proforma_items',['quantity'=>3,'line_total_toman'=>3000],['proforma_id'=>$proformaId],['%d','%d'],['%d']);
    $itemOnlyApprove = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$itemOnlyOperationId.'/approve');
    $itemOnlyApprove->set_param('id',$itemOnlyOperationId);
    $itemOnlyConflict = $sync->approve_operation($itemOnlyApprove);
    $assert($itemOnlyConflict->get_status()===409 && ($itemOnlyConflict->get_data()['status']??'')==='conflict','proforma line-item change is detected even without timestamp change');
    $itemOnlyKeep = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$itemOnlyOperationId.'/resolve-conflict');
    $itemOnlyKeep->set_param('id',$itemOnlyOperationId);$itemOnlyKeep->set_param('decision','keep_server');
    $assert($sync->resolve_conflict($itemOnlyKeep)->get_status()===200,'manager can preserve line-item server revision');

    // Finance records use revision checks too; posted invoice facts are not mutable offline.
    $financeSuffix = wp_generate_password(10,false,false);
    $financeUserId = wp_insert_user([
        'user_login'=>'parsian_finance_qa_'.$financeSuffix,'user_pass'=>wp_generate_password(32,true,true),
        'user_email'=>'parsian-finance-qa-'.$financeSuffix.'@example.invalid','role'=>'parsian_accountant'
    ]);
    if (is_wp_error($financeUserId)) throw new RuntimeException($financeUserId->get_error_message());
    $financeUser = new WP_User($financeUserId);
    $financeUser->add_cap('parsian_sync_use');
    $financeUser->add_cap('parsian_finance_write');
    $financeUser->add_cap('parsian_finance_read');
    $invoiceOk = $wpdb->insert($wpdb->prefix.'parsian_invoices',[
        'invoice_number'=>'QA-INV-'.$financeSuffix,'proforma_id'=>$proformaId,'customer_id'=>$customerId,
        'status'=>'posted','currency'=>'IRR','total_toman'=>5000,'due_date'=>'2027-01-01',
        'issued_by'=>$financeUserId,'issued_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)
    ]);
    $assert($invoiceOk===1,'invoice fixture for revision test is created');
    $invoiceId = (int)$wpdb->insert_id;
    $paymentOk = $wpdb->insert($wpdb->prefix.'parsian_payments',[
        'direction'=>'in','amount_toman'=>2500,'party_type'=>'customer','party_id'=>$customerId,
        'method'=>'cash','reference'=>'QA-ORIGINAL','status'=>'pending_approval',
        'created_by'=>$financeUserId,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)
    ]);
    $assert($paymentOk===1,'payment fixture for revision test is created');
    $paymentId = (int)$wpdb->insert_id;
    $financeDeviceRequest = new WP_REST_Request('POST','/parsian-final/v1/sync/devices');
    $financeDeviceRequest->set_body(wp_json_encode(['device_uuid'=>$financeDevice,'platform'=>'android','label'=>'Finance QA device']));
    wp_set_current_user($financeUserId);
    $assert($sync->register_device($financeDeviceRequest)->get_status()===200,'accountant device registration succeeds');
    $finance = new Parsian_Final_Finance();
    $invoiceBalanceRequest = new WP_REST_Request('GET','/parsian-final/v1/invoices/'.$invoiceId.'/balance');
    $invoiceBalanceRequest->set_param('id',$invoiceId);
    $invoiceSnapshot = $finance->invoice_balance($invoiceBalanceRequest);
    $invoiceSnapshotData = $invoiceSnapshot->get_data()['invoice'] ?? [];
    $assert(!empty($invoiceSnapshotData['revision']) && !empty($invoiceSnapshotData['updated_at']),'invoice balance exposes revision and timestamp');
    $invoiceUpdateUuid = wp_generate_uuid4();
    $financePush = new WP_REST_Request('POST','/parsian-final/v1/sync/push');
    $financePush->set_body(wp_json_encode(['device_uuid'=>$financeDevice,'operations'=>[[
        'operation_uuid'=>$invoiceUpdateUuid,'type'=>'finance.invoice.update',
        'payload'=>['invoice_id'=>$invoiceId,'due_date'=>'2027-02-01','base_updated_at'=>$invoiceSnapshotData['updated_at'],'base_revision'=>$invoiceSnapshotData['revision']]
    ]]]));
    $invoiceQueued = $sync->push($financePush)->get_data();
    $assert(($invoiceQueued['results'][0]['status']??'')==='pending_review','offline invoice metadata update is queued');
    $invoiceOperationId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$invoiceUpdateUuid));
    $wpdb->update($wpdb->prefix.'parsian_invoices',['due_date'=>'2027-03-01','updated_at'=>gmdate('Y-m-d H:i:s',time()+50)],['id'=>$invoiceId],['%s','%s'],['%d']);
    wp_set_current_user($userId);
    $invoiceApprove = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$invoiceOperationId.'/approve');
    $invoiceApprove->set_param('id',$invoiceOperationId);
    $invoiceConflict = $sync->approve_operation($invoiceApprove);
    $assert($invoiceConflict->get_status()===409 && ($invoiceConflict->get_data()['status']??'')==='conflict','concurrent invoice metadata change is detected');
    $invoiceKeep = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$invoiceOperationId.'/resolve-conflict');
    $invoiceKeep->set_param('id',$invoiceOperationId);$invoiceKeep->set_param('decision','keep_server');
    $assert($sync->resolve_conflict($invoiceKeep)->get_status()===200,'manager can keep the server invoice metadata');

    wp_set_current_user($financeUserId);
    $paymentList = $finance->list_payments(new WP_REST_Request('GET','/parsian-final/v1/payments'))->get_data();
    $paymentSnapshot = null;
    foreach (($paymentList['items']??[]) as $candidate) if ((int)$candidate['id']===$paymentId) {$paymentSnapshot=$candidate;break;}
    $assert(is_array($paymentSnapshot) && !empty($paymentSnapshot['revision']) && !empty($paymentSnapshot['updated_at']),'payment register exposes revision and timestamp');
    $paymentUpdateUuid = wp_generate_uuid4();
    $financePush->set_body(wp_json_encode(['device_uuid'=>$financeDevice,'operations'=>[[
        'operation_uuid'=>$paymentUpdateUuid,'type'=>'finance.payment.update',
        'payload'=>['payment_id'=>$paymentId,'reference'=>'QA-DEVICE-REFERENCE','base_updated_at'=>$paymentSnapshot['updated_at'],'base_revision'=>$paymentSnapshot['revision']]
    ]]]));
    $paymentQueued = $sync->push($financePush)->get_data();
    $assert(($paymentQueued['results'][0]['status']??'')==='pending_review','offline payment update is queued');
    $paymentOperationId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$paymentUpdateUuid));
    $wpdb->update($wpdb->prefix.'parsian_payments',['reference'=>'QA-SERVER-REFERENCE','updated_at'=>gmdate('Y-m-d H:i:s',time()+60)],['id'=>$paymentId],['%s','%s'],['%d']);
    wp_set_current_user($userId);
    $paymentApprove = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$paymentOperationId.'/approve');
    $paymentApprove->set_param('id',$paymentOperationId);
    $paymentConflict = $sync->approve_operation($paymentApprove);
    $assert($paymentConflict->get_status()===409 && ($paymentConflict->get_data()['status']??'')==='conflict','concurrent payment edit is detected');
    $paymentConflictRow = $wpdb->get_row($wpdb->prepare("SELECT result FROM {$wpdb->prefix}parsian_sync_ops WHERE id=%d",$paymentOperationId),ARRAY_A);
    $paymentConflictData = json_decode((string)$paymentConflictRow['result'],true);
    $assert(($paymentConflictData['server_record']['reference']??'')==='QA-SERVER-REFERENCE','payment conflict preserves current server reference');
    $paymentKeep = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$paymentOperationId.'/resolve-conflict');
    $paymentKeep->set_param('id',$paymentOperationId);$paymentKeep->set_param('decision','keep_server');
    $assert($sync->resolve_conflict($paymentKeep)->get_status()===200,'manager can keep the server payment metadata');
    $assert($wpdb->get_var($wpdb->prepare("SELECT reference FROM {$wpdb->prefix}parsian_payments WHERE id=%d",$paymentId))==='QA-SERVER-REFERENCE','stale payment reference never overwrites server value');
    wp_set_current_user($financeUserId);
    $financePullRequest = new WP_REST_Request('GET','/parsian-final/v1/sync/pull');
    $financePullRequest->set_param('since_id',0);
    $financePulled = $sync->pull($financePullRequest)->get_data();
    $financeSyncEvents = array_filter($financePulled['items']??[],static fn($event)=>str_starts_with((string)($event['event_type']??''),'sync.') && in_array((string)($event['aggregate_type']??''),['invoice','payment'],true));
    $assert(count($financeSyncEvents)>=2,'accountant delta stream includes invoice/payment conflict events for local outbox acknowledgement');
    wp_set_current_user($userId);

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

    $leadBaseRow = $wpdb->get_row($wpdb->prepare("SELECT id,display_name,phone,province,notes,status,updated_at FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead),ARRAY_A);
    $leadBaseUpdatedAt = (string)($leadBaseRow['updated_at']??'');
    $leadBaseRevision = hash('sha256', wp_json_encode([
        'id'=>(int)$leadBaseRow['id'],'display_name'=>(string)$leadBaseRow['display_name'],
        'phone'=>(string)$leadBaseRow['phone'],'province'=>(string)$leadBaseRow['province'],
        'notes'=>(string)$leadBaseRow['notes'],'status'=>(string)$leadBaseRow['status'],
        'updated_at'=>$leadBaseUpdatedAt
    ], JSON_UNESCAPED_UNICODE));
    $assert($leadBaseUpdatedAt !== '' && $leadBaseRevision !== '', 'offline lead has server revision and modification timestamp');
    $updateOperation = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$updateOperation,'type'=>'crm.lead.update',
        'payload'=>['lead_id'=>$createdLead,'display_name'=>'Updated Offline QA Lead','province'=>'Tehran','base_updated_at'=>$leadBaseUpdatedAt,'base_revision'=>$leadBaseRevision]
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
    $conflictBaseRow = $wpdb->get_row($wpdb->prepare("SELECT id,display_name,phone,province,notes,status,updated_at FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead),ARRAY_A);
    $conflictBase = (string)$conflictBaseRow['updated_at'];
    $conflictRevision = hash('sha256', wp_json_encode([
        'id'=>(int)$conflictBaseRow['id'],'display_name'=>(string)$conflictBaseRow['display_name'],
        'phone'=>(string)$conflictBaseRow['phone'],'province'=>(string)$conflictBaseRow['province'],
        'notes'=>(string)$conflictBaseRow['notes'],'status'=>(string)$conflictBaseRow['status'],
        'updated_at'=>$conflictBase
    ], JSON_UNESCAPED_UNICODE));
    $conflictOperation = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$conflictOperation,'type'=>'crm.lead.update',
        'payload'=>['lead_id'=>$createdLead,'display_name'=>'Stale Device Name','base_updated_at'=>$conflictBase,'base_revision'=>$conflictRevision]
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

    // The manager may explicitly rebase a device patch, but final approval rechecks it.
    $rebaseBaseRow = $wpdb->get_row($wpdb->prepare("SELECT id,display_name,phone,province,notes,status,updated_at FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead),ARRAY_A);
    $rebaseTimestamp = (string)$rebaseBaseRow['updated_at'];
    $rebaseRevision = hash('sha256', wp_json_encode([
        'id'=>(int)$rebaseBaseRow['id'],'display_name'=>(string)$rebaseBaseRow['display_name'],
        'phone'=>(string)$rebaseBaseRow['phone'],'province'=>(string)$rebaseBaseRow['province'],
        'notes'=>(string)$rebaseBaseRow['notes'],'status'=>(string)$rebaseBaseRow['status'],
        'updated_at'=>$rebaseTimestamp
    ], JSON_UNESCAPED_UNICODE));
    $rebaseOperation = wp_generate_uuid4();
    $push->set_body(wp_json_encode(['device_uuid'=>$device,'operations'=>[[
        'operation_uuid'=>$rebaseOperation,'type'=>'crm.lead.update',
        'payload'=>['lead_id'=>$createdLead,'display_name'=>'Manager Accepted Device Value','base_updated_at'=>$rebaseTimestamp,'base_revision'=>$rebaseRevision]
    ]]]));
    $rebaseQueued = $sync->push($push)->get_data();
    $assert(($rebaseQueued['results'][0]['status']??'')==='pending_review','rebase fixture is queued');
    $rebaseId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_sync_ops WHERE operation_uuid=%s",$rebaseOperation));
    $wpdb->update($wpdb->prefix.'parsian_leads',['province'=>'Another Server Edit','updated_at'=>gmdate('Y-m-d H:i:s',time()+20)],['id'=>$createdLead],['%s','%s'],['%d']);
    $rebaseApproveFirst = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$rebaseId.'/approve');
    $rebaseApproveFirst->set_param('id',$rebaseId);
    $assert($sync->approve_operation($rebaseApproveFirst)->get_status()===409,'second concurrent edit is detected');
    $resolveApply = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$rebaseId.'/resolve-conflict');
    $resolveApply->set_param('id',$rebaseId);
    $resolveApply->set_param('decision','apply_device');
    $rebased = $sync->resolve_conflict($resolveApply);
    $assert($rebased->get_status()===200 && ($rebased->get_data()['resolution']??'')==='device_changes_rebased','manager can explicitly rebase device changes to current server revision');
    $rebaseApproveFinal = new WP_REST_Request('POST','/parsian-final/v1/sync/operations/'.$rebaseId.'/approve');
    $rebaseApproveFinal->set_param('id',$rebaseId);
    $appliedAfterRebase = $sync->approve_operation($rebaseApproveFinal);
    $assert($appliedAfterRebase->get_status()===200 && ($appliedAfterRebase->get_data()['status']??'')==='applied','rebased device changes require and pass a separate final approval');
    $finalRebasedName = $wpdb->get_var($wpdb->prepare("SELECT display_name FROM {$wpdb->prefix}parsian_leads WHERE id=%d",$createdLead));
    $assert($finalRebasedName==='Manager Accepted Device Value','explicitly approved rebased device value is applied');

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

    // Backup snapshot validation is deliberately non-destructive: it verifies format,
    // complete table coverage and checksum without writing to the database.
    $backupService = new Parsian_Final_Backup();
    $backupReflection = new ReflectionClass($backupService);
    $tablesMethod = $backupReflection->getMethod('tables');
    $tablesMethod->setAccessible(true);
    $backupTables = [];
    foreach ($tablesMethod->invoke($backupService) as $tableName) {
        $backupTables[$tableName] = [];
    }
    $backupPayload = [
        'format' => 'PARSIAN_FINAL_BACKUP',
        'format_version' => 1,
        'plugin_version' => PARSIAN_FINAL_VERSION,
        'schema_version' => get_option('parsian_final_schema_version', 'unknown'),
        'source_site' => home_url('/'),
        'created_at_utc' => gmdate('c'),
        'tables' => $backupTables,
        'excluded_tables' => ['auth_tokens' => ['reason' => 'credential material is intentionally excluded; revoke and re-register devices after disaster recovery']],
        'user_customer_assignments' => [],
    ];
    $backupCanonical = wp_json_encode($backupPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $backupPayload['checksum_sha256'] = hash('sha256', $backupCanonical);
    $backupRequest = new WP_REST_Request('POST', '/parsian-final/v1/backup/validate');
    $backupRequest->set_header('content-type', 'application/json');
    $backupRequest->set_body(wp_json_encode($backupPayload));
    $backupValidated = $backupService->validate_snapshot($backupRequest);
    $assert($backupValidated->get_status() === 200 && ($backupValidated->get_data()['valid'] ?? false) === true, 'complete backup snapshot with valid checksum passes non-destructive validation');
    $assert(($backupValidated->get_data()['restored'] ?? true) === false, 'backup validation never claims that data was restored');
    $tamperedBackup = $backupPayload;
    $tamperedBackup['tables']['customers'][] = ['id' => 999999, 'display_name' => 'tampered'];
    $tamperedRequest = new WP_REST_Request('POST', '/parsian-final/v1/backup/validate');
    $tamperedRequest->set_header('content-type', 'application/json');
    $tamperedRequest->set_body(wp_json_encode($tamperedBackup));
    $tamperedResult = $backupService->validate_snapshot($tamperedRequest);
    $assert($tamperedResult->get_status() === 422 && ($tamperedResult->get_data()['code'] ?? '') === 'backup_checksum_mismatch', 'tampered backup content is rejected by checksum validation');
    $manifestTamper = $backupPayload;
    $manifestTamper['excluded_tables'] = [];
    unset($manifestTamper['checksum_sha256']);
    $manifestCanonical = wp_json_encode($manifestTamper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $manifestTamper['checksum_sha256'] = hash('sha256', $manifestCanonical);
    $manifestRequest = new WP_REST_Request('POST', '/parsian-final/v1/backup/validate');
    $manifestRequest->set_header('content-type', 'application/json');
    $manifestRequest->set_body(wp_json_encode($manifestTamper));
    $manifestResult = $backupService->validate_snapshot($manifestRequest);
    $assert($manifestResult->get_status() === 422 && ($manifestResult->get_data()['code'] ?? '') === 'backup_exclusion_manifest_mismatch', 'backup credential exclusion manifest cannot be silently altered');


    WP_CLI::success('PARSIAN ONE auth/sync acceptance passed: idempotency, lead/customer/proforma/invoice/payment revisions, manager conflict decisions, task/proforma review, seller isolation and device revocation.');
} finally {
    if($oldAuthorization===null)unset($_SERVER['HTTP_AUTHORIZATION']);else $_SERVER['HTTP_AUTHORIZATION']=$oldAuthorization;
    if($financeUserId){
        $wpdb->delete($wpdb->prefix.'parsian_sync_ops',['user_id'=>$financeUserId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_devices',['user_id'=>$financeUserId],['%d']);
        $wpdb->delete($wpdb->prefix.'parsian_events',['actor_user_id'=>$financeUserId],['%d']);
        if ($invoiceId) $wpdb->delete($wpdb->prefix.'parsian_invoices',['id'=>$invoiceId],['%d']);
        if ($paymentId) $wpdb->delete($wpdb->prefix.'parsian_payments',['id'=>$paymentId],['%d']);
        require_once ABSPATH.'wp-admin/includes/user.php';
        wp_delete_user($financeUserId);
    }
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
