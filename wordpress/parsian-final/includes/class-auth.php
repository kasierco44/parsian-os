<?php
if (!defined('ABSPATH')) { exit; }

final class Parsian_Final_Auth {
    private const ACCESS_TTL = 900;
    private const REFRESH_TTL = 2592000;

    public function __construct() {
        add_filter('determine_current_user', [$this, 'authenticate_bearer'], 20);
    }
    private function table(): string {
        global $wpdb;
        return $wpdb->prefix.'parsian_auth_tokens';
    }
    private function devices_table(): string {
        global $wpdb;
        return $wpdb->prefix.'parsian_devices';
    }
    private function token_hash(string $token): string {
        return hash('sha256', $token);
    }
    private function issue_token(string $type, int $userId, string $deviceUuid, string $familyUuid, int $ttl): array {
        global $wpdb;
        $raw=bin2hex(random_bytes(32));
        $hash=$this->token_hash($raw);
        $now=time();
        $expires=gmdate('Y-m-d H:i:s',$now+$ttl);
        $ok=$wpdb->insert($this->table(),[
            'token_hash'=>$hash,'token_type'=>$type,'family_uuid'=>$familyUuid,
            'user_id'=>$userId,'device_uuid'=>$deviceUuid,'expires_at'=>$expires,
            'revoked_at'=>null,'created_at'=>gmdate('Y-m-d H:i:s',$now),'last_used_at'=>null
        ],['%s','%s','%s','%d','%s','%s','%s','%s','%s']);
        if(!$ok) throw new RuntimeException('token_persist_failed');
        return ['token'=>$raw,'expires_at'=>$expires,'expires_in'=>$ttl];
    }
    private function issue_pair(int $userId, string $deviceUuid): array {
        $family=wp_generate_uuid4();
        return [
            'token_type'=>'Bearer',
            'access_token'=>$this->issue_token('access',$userId,$deviceUuid,$family,self::ACCESS_TTL),
            'refresh_token'=>$this->issue_token('refresh',$userId,$deviceUuid,$family,self::REFRESH_TTL),
            'device_uuid'=>$deviceUuid
        ];
    }
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/auth/login',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'login'],'permission_callback'=>'__return_true',
            'args'=>['username'=>['required'=>true,'type'=>'string'],'password'=>['required'=>true,'type'=>'string']]
        ]);
        register_rest_route('parsian-final/v1','/auth/refresh',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'refresh'],'permission_callback'=>'__return_true'
        ]);
        register_rest_route('parsian-final/v1','/auth/logout',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'logout'],
            'permission_callback'=>static function():bool{return is_user_logged_in();}
        ]);
        register_rest_route('parsian-final/v1','/auth/revoke-device',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'revoke_device'],
            'permission_callback'=>static function():bool{return is_user_logged_in()&&current_user_can('parsian_sync_use');}
        ]);
    }
    public function authenticate_bearer($userId) {
        if($userId || !defined('REST_REQUEST') || !REST_REQUEST) return $userId;
        $header=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
        if(!preg_match('/^Bearer\\s+([a-f0-9]{64})$/i',trim((string)$header),$m)) return $userId;
        global $wpdb;
        $hash=$this->token_hash(strtolower($m[1]));
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT t.id,t.user_id,t.device_uuid,d.revoked_at AS device_revoked
             FROM {$this->table()} t
             INNER JOIN {$this->devices_table()} d ON d.device_uuid=t.device_uuid AND d.user_id=t.user_id
             WHERE t.token_hash=%s AND t.token_type='access' AND t.revoked_at IS NULL AND t.expires_at>%s
             LIMIT 1",$hash,gmdate('Y-m-d H:i:s')
        ),ARRAY_A);
        if(!$row || $row['device_revoked']!==null) return $userId;
        $wpdb->update($this->table(),['last_used_at'=>gmdate('Y-m-d H:i:s')],['id'=>(int)$row['id']],['%s'],['%d']);
        return (int)$row['user_id'];
    }
    private function ensure_device(int $userId, string $uuid, string $platform, string $label): ?WP_REST_Response {
        global $wpdb;
        if(!wp_is_uuid($uuid)||!in_array($platform,['android','windows','web'],true)||strlen($label)>120)
            return new WP_REST_Response(['code'=>'invalid_device'],400);
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,user_id,revoked_at FROM {$this->devices_table()} WHERE device_uuid=%s",$uuid),ARRAY_A);
        if($existing && (int)$existing['user_id']!==$userId) return new WP_REST_Response(['code'=>'device_owned_by_another_user'],409);
        if($existing && $existing['revoked_at']!==null) return new WP_REST_Response(['code'=>'device_revoked'],403);
        if(!$existing){
            $ok=$wpdb->insert($this->devices_table(),[
                'device_uuid'=>$uuid,'user_id'=>$userId,'platform'=>$platform,'label'=>$label,
                'created_at'=>gmdate('Y-m-d H:i:s'),'last_seen_at'=>gmdate('Y-m-d H:i:s')
            ],['%s','%d','%s','%s','%s','%s']);
            if(!$ok)return new WP_REST_Response(['code'=>'device_register_failed'],500);
        } else {
            $wpdb->update($this->devices_table(),['last_seen_at'=>gmdate('Y-m-d H:i:s')],['id'=>(int)$existing['id']],['%s'],['%d']);
        }
        return null;
    }
    public function login(WP_REST_Request $request) {
        $username=sanitize_user($request->get_param('username'));
        $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
        $key='parsian_login_fail_'.hash('sha256',strtolower($username).'|'.$ip);
        if((int)get_transient($key)>=8)return new WP_Error('too_many_attempts','تلاش‌های ورود بیش از حد است؛ بعداً دوباره امتحان کنید.',['status'=>429]);
        $user=wp_authenticate($username,(string)$request->get_param('password'));
        if(is_wp_error($user)){set_transient($key,(int)get_transient($key)+1,15*MINUTE_IN_SECONDS);return new WP_Error('invalid_credentials','نام کاربری یا رمز عبور نادرست است.',['status'=>401]);}
        if(!user_can($user,'parsian_access_core'))return new WP_Error('forbidden','دسترسی به PARSIAN FINAL مجاز نیست.',['status'=>403]);
        $uuid=strtolower(sanitize_text_field($request->get_param('device_uuid')??''));
        $platform=sanitize_key($request->get_param('platform')??'android');
        $label=sanitize_text_field($request->get_param('device_label')??'');
        if($uuid==='')$uuid=wp_generate_uuid4();
        $deviceError=$this->ensure_device((int)$user->ID,$uuid,$platform,$label);
        if($deviceError)return $deviceError;
        delete_transient($key);
        try{$tokens=$this->issue_pair((int)$user->ID,$uuid);}
        catch(Throwable $e){return new WP_Error('token_issue_failed','صدور توکن ورود انجام نشد.',['status'=>500]);}
        return new WP_REST_Response(['status'=>'authenticated','user_id'=>(int)$user->ID]+$tokens,200);
    }
    public function refresh(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $raw=(string)($request->get_param('refresh_token')??'');
        if(!preg_match('/^[a-f0-9]{64}$/i',$raw))return new WP_REST_Response(['code'=>'invalid_refresh_token'],401);
        $hash=$this->token_hash(strtolower($raw));
        $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE token_hash=%s AND token_type='refresh' FOR UPDATE",$hash
        ),ARRAY_A);
        if(!$row || $row['revoked_at']!==null || strtotime($row['expires_at'].' UTC')<=time()){
            $wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'refresh_token_invalid_or_expired'],401);
        }
        $device=$wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$this->devices_table()} WHERE device_uuid=%s AND user_id=%d AND revoked_at IS NULL",
            $row['device_uuid'],(int)$row['user_id']
        ),ARRAY_A);
        if(!$device){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'device_revoked'],403);}
        $wpdb->update($this->table(),['revoked_at'=>gmdate('Y-m-d H:i:s')],['id'=>(int)$row['id']],['%s'],['%d']);
        try{
            $access=$this->issue_token('access',(int)$row['user_id'],$row['device_uuid'],$row['family_uuid'],self::ACCESS_TTL);
            $refresh=$this->issue_token('refresh',(int)$row['user_id'],$row['device_uuid'],$row['family_uuid'],self::REFRESH_TTL);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'token_rotation_failed'],500);}
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['token_type'=>'Bearer','access_token'=>$access,'refresh_token'=>$refresh,'device_uuid'=>$row['device_uuid']],200);
    }
    public function logout(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $header=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
        if(preg_match('/^Bearer\\s+([a-f0-9]{64})$/i',trim((string)$header),$m)){
            $hash=$this->token_hash(strtolower($m[1]));
            $token=$wpdb->get_row($wpdb->prepare("SELECT family_uuid FROM {$this->table()} WHERE token_hash=%s AND user_id=%d",$hash,get_current_user_id()),ARRAY_A);
            if($token)$wpdb->update($this->table(),['revoked_at'=>gmdate('Y-m-d H:i:s')],['family_uuid'=>$token['family_uuid'],'user_id'=>get_current_user_id()],['%s'],['%s','%d']);
        }
        wp_logout();
        return new WP_REST_Response(['status'=>'logged_out'],200);
    }
    public function revoke_device(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $uuid=strtolower(sanitize_text_field($request->get_param('device_uuid')??''));
        if(!wp_is_uuid($uuid))return new WP_REST_Response(['code'=>'invalid_device_uuid'],400);
        $device=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->devices_table()} WHERE device_uuid=%s AND user_id=%d",$uuid,get_current_user_id()),ARRAY_A);
        if(!$device)return new WP_REST_Response(['code'=>'device_not_found'],404);
        $now=gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        $wpdb->update($this->devices_table(),['revoked_at'=>$now],['id'=>(int)$device['id']],['%s'],['%d']);
        $wpdb->update($this->table(),['revoked_at'=>$now],['device_uuid'=>$uuid,'user_id'=>get_current_user_id()],['%s'],['%s','%d']);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['device_uuid'=>$uuid,'status'=>'revoked'],200);
    }
}
