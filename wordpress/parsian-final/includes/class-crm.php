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
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_lead'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_crm_write'); },
            'args' => [
                'name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'phone' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'province' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'notes' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
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
        $rows = $wpdb->get_results("SELECT id, display_name, province, status FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
        if (!current_user_can('parsian_manage_users') && !current_user_can('parsian_finance_read')) {
            $rows = array_values(array_filter((array) $rows, static function (array $row): bool {
                return (int) get_user_meta(get_current_user_id(), 'parsian_customer_' . $row['id'], true) === 1;
            }));
        }
        return new WP_REST_Response(['items' => $rows ?: []], 200);
    }
    public function create_lead(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'parsian_leads';
        $ok = $wpdb->insert($table, [
            'display_name' => $request->get_param('name'),
            'phone' => (string) $request->get_param('phone'),
            'province' => (string) $request->get_param('province'),
            'notes' => (string) $request->get_param('notes'),
            'owner_user_id' => get_current_user_id(),
            'status' => 'new',
            'created_at' => current_time('mysql', true),
        ], ['%s','%s','%s','%s','%d','%s','%s']);
        if (!$ok) { return new WP_REST_Response(['code'=>'lead_create_failed'], 500); }
        return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'status'=>'new'], 201);
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
            if ($qty === false || $qty < 1 || $price === false || $price < 0) {
                return new WP_REST_Response(['code'=>'invalid_amount'], 400);
            }
            $clean[] = ['product_id'=>(int)$item['product_id'],'quantity'=>$qty,'unit_price'=>$price];
        }
        // No invoice is finalized here. Persisted, manager-approved workflow is the next implementation slice.
        return new WP_REST_Response([
            'status'=>'draft_validation_passed',
            'approval_required'=>true,
            'items'=>$clean,
            'message'=>'Validated draft only; no accounting or inventory posting has occurred.'
        ], 202);
    }
}
