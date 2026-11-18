<?php
/**
 * Server-side identity bridge for PARSIAN OS Finance.
 *
 * Configure PARSIAN_OS_API_BASE_URL in wp-config.php, e.g.
 * define('PARSIAN_OS_API_BASE_URL', 'https://api.example.com/api/v1');
 */
if (!defined('ABSPATH')) {
    exit;
}

final class Parsian_OS_Backend_Bridge {
    const SESSION_PREFIX = 'parsian_os_backend_session_';
    const SESSION_TTL = 1500; // Backend access tokens default to 30 minutes.
    const REST_NAMESPACE = 'parsian-finance/v1';

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/backend/login', array(
            'methods' => 'POST',
            'callback' => array($this, 'login'),
            'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/backend/session', array(
            'methods' => 'GET',
            'callback' => array($this, 'session'),
            'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/backend/password', array(
            'methods' => 'POST',
            'callback' => array($this, 'change_password'),
            'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/backend/logout', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'logout'),
            'permission_callback' => array($this, 'can_view'),
        ));
    }

    public function can_view() {
        return is_user_logged_in() && current_user_can('parsian_view_finance');
    }

    private function api_base() {
        if (!defined('PARSIAN_OS_API_BASE_URL')) {
            return new WP_Error('backend_not_configured', 'نشانی Backend در تنظیمات امن سرور وردپرس تعریف نشده است.', array('status' => 503));
        }
        $base = untrailingslashit(esc_url_raw(PARSIAN_OS_API_BASE_URL));
        if (!$base || wp_parse_url($base, PHP_URL_SCHEME) !== 'https') {
            return new WP_Error('backend_https_required', 'اتصال Backend فقط از طریق HTTPS مجاز است.', array('status' => 503));
        }
        return $base;
    }

    private function session_key() {
        $wp_session = function_exists('wp_get_session_token') ? wp_get_session_token() : '';
        if (!$wp_session || !get_current_user_id()) {
            return '';
        }
        return self::SESSION_PREFIX . hash('sha256', get_current_user_id() . '|' . $wp_session);
    }

    private function login_throttle_key($username) {\n        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';\n        return 'parsian_os_login_fail_' . hash('sha256', strtolower($username) . '|' . $ip);\n    }\n\n    private function token() {
        $key = $this->session_key();
        if (!$key) {
            return '';
        }
        $stored = get_transient($key);
        return is_array($stored) && !empty($stored['access_token']) ? (string) $stored['access_token'] : '';
    }

    private function request($path, $method, $payload = null, $token = '') {
        $base = $this->api_base();
        if (is_wp_error($base)) {
            return $base;
        }
        $args = array(
            'method' => $method,
            'timeout' => 12,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array('Accept' => 'application/json'),
        );
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        if ($payload !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($payload);
        }
        $response = wp_remote_request($base . '/' . ltrim($path, '/'), $args);
        if (is_wp_error($response)) {
            return new WP_Error('backend_unreachable', 'ارتباط امن با Backend برقرار نشد.', array('status' => 502));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = 'درخواست احراز هویت در Backend تأیید نشد.';
            if ($status === 401) {
                $message = 'نام کاربری یا رمز عبور Backend صحیح نیست.';
            } elseif ($status === 403) {
                $message = 'حساب Backend غیرفعال است، مجوز مالی ندارد یا باید رمز اولیه را تغییر دهد.';
            } elseif ($status === 423) {
                $message = 'حساب Backend موقتاً قفل شده است.';
            }
            return new WP_Error('backend_auth_failed', $message, array('status' => $status >= 400 && $status < 500 ? $status : 502, 'backend_status' => $status));
        }
        return is_array($body) ? $body : new WP_Error('backend_invalid_response', 'پاسخ Backend معتبر نیست.', array('status' => 502));
    }

    private function permitted_finance_identity($user) {
        if (!is_array($user) || empty($user['id']) || empty($user['is_active'])) {
            return false;
        }
        if (!empty($user['is_superuser'])) {
            return true;
        }
        $roles = isset($user['role_codes']) && is_array($user['role_codes']) ? $user['role_codes'] : array();
        return in_array('manager', $roles, true) || in_array('accountant', $roles, true);
    }

    public function login(WP_REST_Request $request) {
        $username = sanitize_text_field((string) $request->get_param('username'));
        $password = (string) $request->get_param('password');
        if (strlen($username) < 3 || strlen($username) > 80 || $password === '' || strlen($password) > 128) {
            return new WP_Error('invalid_credentials', 'اطلاعات ورود معتبر نیست.', array('status' => 400));
        }
        $key = $this->session_key();
        if (!$key) {
            return new WP_Error('session_missing', 'نشست وردپرس معتبر نیست؛ دوباره وارد وردپرس شوید.', array('status' => 401));
        }

        $result = $this->request('auth/login', 'POST', array('username' => $username, 'password' => $password));
        unset($password);
        if (is_wp_error($result)) {
            return $result;
        }
        if (empty($result['access_token']) || !is_string($result['access_token'])) {
            return new WP_Error('backend_token_missing', 'Backend توکن معتبر برنگرداند.', array('status' => 502));
        }

        // The bearer token is stored only server-side and is bound to this WP login session.
        set_transient($key, array('access_token' => $result['access_token']), self::SESSION_TTL);
        $identity = $this->request('auth/me', 'GET', null, $result['access_token']);
        if (is_wp_error($identity)) {
            // A 403 from /me can mean the first-login password change is required.
            $data = $identity->get_error_data();
            if (is_array($data) && isset($data['backend_status']) && (int) $data['backend_status'] === 403) {
                return rest_ensure_response(array('connected' => true, 'password_change_required' => true));
            }
            delete_transient($key);
            return $identity;
        }
        if (!$this->permitted_finance_identity($identity)) {
            delete_transient($key);
            return new WP_Error('finance_role_required', 'این حساب Backend نقش مدیر یا حسابدار مالی ندارد.', array('status' => 403));
        }
        return rest_ensure_response(array('connected' => true, 'password_change_required' => false, 'identity' => $identity));
    }

    public function session() {
        $token = $this->token();
        if (!$token) {
            return rest_ensure_response(array('connected' => false));
        }
        $identity = $this->request('auth/me', 'GET', null, $token);
        if (is_wp_error($identity)) {
            $data = $identity->get_error_data();
            if (is_array($data) && isset($data['backend_status']) && (int) $data['backend_status'] === 403) {
                return rest_ensure_response(array('connected' => true, 'password_change_required' => true));
            }
            delete_transient($this->session_key());
            return rest_ensure_response(array('connected' => false));
        }
        if (!$this->permitted_finance_identity($identity)) {
            delete_transient($this->session_key());
            return new WP_Error('finance_role_required', 'این حساب Backend مجوز پنل مالی را ندارد.', array('status' => 403));
        }
        return rest_ensure_response(array('connected' => true, 'password_change_required' => false, 'identity' => $identity));
    }

    public function change_password(WP_REST_Request $request) {
        $token = $this->token();
        if (!$token) {
            return new WP_Error('backend_session_missing', 'ابتدا با حساب Backend وارد شوید.', array('status' => 401));
        }
        $current = (string) $request->get_param('current_password');
        $new = (string) $request->get_param('new_password');
        if ($current === '' || strlen($new) < 12 || strlen($new) > 128) {
            return new WP_Error('invalid_password', 'رمز جدید باید حداقل ۱۲ نویسه باشد.', array('status' => 400));
        }
        $result = $this->request('auth/password/change', 'POST', array('current_password' => $current, 'new_password' => $new), $token);
        unset($current, $new);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!$this->permitted_finance_identity($result)) {
            return new WP_Error('finance_role_required', 'حساب شما مجوز مالی ندارد.', array('status' => 403));
        }
        return rest_ensure_response(array('changed' => true, 'identity' => $result));
    }

    public function logout() {
        $key = $this->session_key();
        if ($key) {
            delete_transient($key);
        }
        return rest_ensure_response(array('connected' => false));
    }
}
