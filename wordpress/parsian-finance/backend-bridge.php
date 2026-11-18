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
        register_rest_route(self::REST_NAMESPACE, '/backend/proxy', array(
            'methods' => 'POST',
            'callback' => array($this, 'proxy'),
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

    private function login_throttle_key($username) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return 'parsian_os_login_fail_' . hash('sha256', strtolower($username) . '|' . $ip);
    }

    private function encrypt_token($token) {
        if (!function_exists('openssl_encrypt') || !function_exists('random_bytes')) {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $key = hash('sha256', wp_salt('auth'), true);
        $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $ciphertext === false ? '' : base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt_token($encrypted) {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode((string) $encrypted, true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $key = hash('sha256', wp_salt('auth'), true);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plain) ? $plain : '';
    }

    private function token() {
        $key = $this->session_key();
        if (!$key) {
            return '';
        }
        $stored = get_transient($key);
        return is_array($stored) && !empty($stored['encrypted_token'])
            ? $this->decrypt_token($stored['encrypted_token'])
            : '';
    }

    private function request($path, $method, $payload = null, $token = '', $extra_headers = array()) {
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
        foreach ($extra_headers as $header_name => $header_value) {
            if (is_string($header_name) && is_scalar($header_value)) {
                $args['headers'][$header_name] = (string) $header_value;
            }
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

        $throttle_key = $this->login_throttle_key($username);
        $failures = (int) get_transient($throttle_key);
        if ($failures >= 5) {
            return new WP_Error('login_rate_limited', 'تعداد تلاش‌های ورود زیاد است؛ ۱۵ دقیقه بعد دوباره تلاش کنید.', array('status' => 429));
        }
        $result = $this->request('auth/login', 'POST', array('username' => $username, 'password' => $password));
        unset($password);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            if (is_array($data) && isset($data['backend_status']) && in_array((int) $data['backend_status'], array(401, 423), true)) {
                set_transient($throttle_key, $failures + 1, 15 * MINUTE_IN_SECONDS);
            }
            return $result;
        }
        delete_transient($throttle_key);
        if (empty($result['access_token']) || !is_string($result['access_token'])) {
            return new WP_Error('backend_token_missing', 'Backend توکن معتبر برنگرداند.', array('status' => 502));
        }

        // Encrypt the bearer token at rest; it is bound to this WP login session.
        $encrypted_token = $this->encrypt_token($result['access_token']);
        if ($encrypted_token === '') {
            return new WP_Error('secure_storage_unavailable', 'ذخیره‌سازی امن نشست Backend روی سرور در دسترس نیست.', array('status' => 503));
        }
        set_transient($key, array('encrypted_token' => $encrypted_token), self::SESSION_TTL);
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

    /**
     * Allowlisted proxy to the canonical supplier-accounting API.
     * The caller cannot choose an arbitrary upstream URL or API path.
     */
    private function allowed_finance_route($method, $path) {
        $uuid = '[0-9a-fA-F-]{36}';
        $rules = array(
            'GET' => array(
                '#^suppliers$#',
                '#^suppliers/charge-accounts$#',
                '#^suppliers/bills$#',
                '#^suppliers/payments$#',
                '#^suppliers/reports/cash-flow$#',
                '#^suppliers/' . $uuid . '/ledger$#',
            ),
            'POST' => array(
                '#^suppliers$#',
                '#^suppliers/opening-balance$#',
                '#^suppliers/bills$#',
                '#^suppliers/payments$#',
                '#^suppliers/payments/' . $uuid . '/clear$#',
                '#^suppliers/payments/' . $uuid . '/bounce$#',
            ),
        );
        if (!isset($rules[$method])) {
            return false;
        }
        foreach ($rules[$method] as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    public function proxy(WP_REST_Request $request) {
        $token = $this->token();
        if (!$token) {
            return new WP_Error('backend_session_missing', 'ابتدا با حساب Backend وارد شوید.', array('status' => 401));
        }

        // Revalidate the Backend identity on every proxied operation. Backend
        // endpoints independently enforce their own authorization as well.
        $identity = $this->request('auth/me', 'GET', null, $token);
        if (is_wp_error($identity)) {
            delete_transient($this->session_key());
            return new WP_Error('backend_session_invalid', 'نشست Backend معتبر نیست؛ دوباره وارد شوید.', array('status' => 401));
        }
        if (!$this->permitted_finance_identity($identity)) {
            delete_transient($this->session_key());
            return new WP_Error('finance_role_required', 'این حساب Backend مجوز مالی ندارد.', array('status' => 403));
        }

        $method = strtoupper((string) $request->get_param('method'));
        $path = trim((string) $request->get_param('path'), '/');
        if (strlen($path) > 180 || strpos($path, '..') !== false || strpos($path, '?') !== false
            || strpos($path, '#') !== false || strpos($path, ':') !== false
            || !$this->allowed_finance_route($method, $path)) {
            return new WP_Error('finance_route_not_allowed', 'مسیر درخواستی در فهرست API مالی مجاز نیست.', array('status' => 403));
        }

        $query = $request->get_param('query');
        if ($method === 'GET' && is_array($query) && $query) {
            $allowed_query = array('active_only', 'supplier_id', 'status', 'forecast_days', 'from_date', 'to_date');
            $safe_query = array();
            foreach ($query as $key => $value) {
                if (in_array($key, $allowed_query, true) && is_scalar($value)) {
                    $safe_query[$key] = sanitize_text_field((string) $value);
                }
            }
            if ($safe_query) {
                $path .= '?' . http_build_query($safe_query, '', '&', PHP_QUERY_RFC3986);
            }
        }

        $payload = null;
        if ($method === 'POST') {
            $payload = $request->get_param('payload');
            if (!is_array($payload)) {
                return new WP_Error('invalid_finance_payload', 'بدنهٔ درخواست مالی باید یک شیء JSON معتبر باشد.', array('status' => 400));
            }
        }

        $headers = array();
        $idempotency_key = sanitize_text_field((string) $request->get_param('idempotency_key'));
        if ($idempotency_key !== '') {
            if (strlen($idempotency_key) < 12 || strlen($idempotency_key) > 120
                || !preg_match('/^[A-Za-z0-9._:-]+$/', $idempotency_key)) {
                return new WP_Error('invalid_idempotency_key', 'کلید جلوگیری از ثبت تکراری معتبر نیست.', array('status' => 400));
            }
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        if ($method === 'POST' && in_array($path, array('suppliers/bills', 'suppliers/payments'), true) && !$idempotency_key) {
            return new WP_Error('idempotency_key_required', 'برای ثبت بدهی یا پرداخت، کلید یکتای جلوگیری از ثبت تکراری الزامی است.', array('status' => 400));
        }

        $result = $this->request($path, $method, $payload, $token, $headers);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('ok' => true, 'data' => $result));
    }

    public function logout() {
        $key = $this->session_key();
        if ($key) {
            delete_transient($key);
        }
        return rest_ensure_response(array('connected' => false));
    }
}
