<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_CRM {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1', '/customers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'customers'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_crm_read'); },
        ]);
        register_rest_route('parsian-final/v1', '/leads', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'leads'],
                'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_crm_read'); },
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_lead'],
                'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_crm_write'); },
                'args' => [
                    'name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'phone' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'province' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'notes' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                ],
            ],
        ]);
        register_rest_route('parsian-final/v1', '/proformas', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_proforma'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_sales_create'); },
        ]);
    }
    public function customers(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'parsian_customers';
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $offset = ($page - 1) * $per_page;
        if (current_user_can('parsian_manage_users') || current_user_can('parsian_finance_read')) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, display_name, province, status FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ), ARRAY_A);
        } else {
            // Enforce assignment in SQL before pagination; never fetch unassigned customers.
            $user_id = get_current_user_id();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.id, c.display_name, c.province, c.status
                 FROM {$table} c
                 INNER JOIN {$wpdb->usermeta} um
                   ON um.user_id = %d
                  AND um.meta_key = CONCAT('parsian_customer_', c.id)
                  AND um.meta_value = '1'
                 ORDER BY c.id DESC LIMIT %d OFFSET %d",
                $user_id, $per_page, $offset
            ), ARRAY_A);
        }
        return new WP_REST_Response(['items' => $rows ?: [], 'page' => $page, 'per_page' => $per_page], 200);
    }
    public function leads(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'parsian_leads';
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $offset = ($page - 1) * $per_page;
        if (current_user_can('parsian_manage_users')) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, display_name, phone, province, notes, owner_user_id, status, created_at
                 FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, display_name, phone, province, notes, owner_user_id, status, created_at
                 FROM {$table} WHERE owner_user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
                get_current_user_id(), $per_page, $offset
            ), ARRAY_A);
        }
        return new WP_REST_Response(['items' => $rows ?: [], 'page' => $page, 'per_page' => $per_page], 200);
    }
    public function create_lead(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'parsian_leads';
        $name = trim((string) $request->get_param('name'));
        $phone = trim((string) $request->get_param('phone'));
        if ($name === '' || strlen($name) > 190 || strlen($phone) > 40) {
            return new WP_REST_Response(['code' => 'invalid_lead'], 400);
        }
        $ok = $wpdb->insert($table, [
            'display_name' => $name,
            'phone' => $phone,
            'province' => (string) $request->get_param('province'),
            'notes' => (string) $request->get_param('notes'),
            'owner_user_id' => get_current_user_id(),
            'status' => 'new',
            'created_at' => current_time('mysql', true),
        ], ['%s','%s','%s','%s','%d','%s','%s']);
        if (!$ok) { return new WP_REST_Response(['code'=>'lead_create_failed'], 500); }
        $id = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'parsian_events', [
            'event_uuid' => wp_generate_uuid4(),
            'actor_user_id' => get_current_user_id(),
            'event_type' => 'crm.lead.created',
            'aggregate_type' => 'lead',
            'aggregate_id' => $id,
            'payload' => wp_json_encode(['lead_id' => $id, 'owner_user_id' => get_current_user_id()]),
            'created_at' => current_time('mysql', true),
        ], ['%s','%d','%s','%s','%d','%s','%s']);
        return new WP_REST_Response(['id' => $id, 'status' => 'new'], 201);
    }
    public function create_proforma(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['items']) || !is_array($body['items'])) {
            return new WP_REST_Response(['code'=>'invalid_items','message'=>'At least one line item is required.'], 400);
        }
        if (count($body['items']) > 100) { return new WP_REST_Response(['code'=>'too_many_items'], 400); }
        $clean = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item) || empty($item['product_id']) || !isset($item['quantity']) || !isset($item['unit_price'])) {
                return new WP_REST_Response(['code'=>'invalid_line_item'], 400);
            }
            $qty = filter_var($item['quantity'], FILTER_VALIDATE_INT);
            $price = filter_var($item['unit_price'], FILTER_VALIDATE_INT);
            if ($qty === false || $qty < 1 || $qty > 1000000 || $price === false || $price < 0 || $price > 1000000000000) {
                return new WP_REST_Response(['code'=>'invalid_amount'], 400);
            }
            $clean[] = ['product_id'=>(int)$item['product_id'],'quantity'=>$qty,'unit_price'=>$price];
        }
        return new WP_REST_Response([
            'status'=>'draft_validation_passed',
            'approval_required'=>true,
            'items'=>$clean,
            'message'=>'Validated draft only; no accounting or inventory posting has occurred.'
        ], 202);
    }
}
