<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Auth {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1','/auth/login',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'login'],'permission_callback'=>'__return_true',
            'args'=>['username'=>['required'=>true,'type'=>'string'],'password'=>['required'=>true,'type'=>'string']]
        ]);
        register_rest_route('parsian-final/v1','/auth/logout',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'logout'],
            'permission_callback'=>static function():bool{return is_user_logged_in();}
        ]);
    }
    public function login(WP_REST_Request $request) {
        $username=sanitize_user($request->get_param('username'));
        $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
        $key='parsian_login_fail_'.hash('sha256',strtolower($username).'|'.$ip);
        if((int)get_transient($key)>=8)return new WP_Error('too_many_attempts','تلاش‌های ورود بیش از حد است؛ بعداً دوباره امتحان کنید.',['status'=>429]);
        $user=wp_signon(['user_login'=>$username,'user_password'=>(string)$request->get_param('password'),'remember'=>false],is_ssl());
        if(is_wp_error($user)){set_transient($key,(int)get_transient($key)+1,15*MINUTE_IN_SECONDS);return new WP_Error('invalid_credentials','نام کاربری یا رمز عبور نادرست است.',['status'=>401]);}
        delete_transient($key);
        if(!user_can($user,'parsian_access_core'))return new WP_Error('forbidden','دسترسی به PARSIAN FINAL مجاز نیست.',['status'=>403]);
        return new WP_REST_Response(['status'=>'authenticated','user_id'=>(int)$user->ID,'message'=>'Login uses WordPress auth cookie/session; use HTTPS and WP REST nonce for subsequent browser requests.'],200);
    }
    public function logout(WP_REST_Request $request): WP_REST_Response {
        wp_logout();
        return new WP_REST_Response(['status'=>'logged_out'],200);
    }
}
