<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_REST {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'health'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('parsian-final/v1', '/me', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'me'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_access_core'); },
        ]);
    }
    public function health(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'status' => 'ok',
            'product' => 'PARSIAN FINAL',
            'version' => PARSIAN_FINAL_VERSION,
            'schema_version' => get_option('parsian_final_schema_version', 'not-installed'),
            'timestamp' => gmdate('c'),
        ], 200);
    }
    public function me(WP_REST_Request $request): WP_REST_Response {
        $user = wp_get_current_user();
        $caps = [
            'parsian_manage_settings','parsian_manage_users','parsian_view_reports',
            'parsian_crm_read','parsian_crm_write','parsian_sales_create','parsian_sales_approve',
            'parsian_finance_read','parsian_finance_write','parsian_inventory_read',
            'parsian_inventory_write','parsian_sync_use','parsian_customer_portal_read'
        ];
        $granted = [];
        foreach ($caps as $cap) { if (current_user_can($cap)) { $granted[] = $cap; } }
        return new WP_REST_Response([
            'id' => (int) $user->ID,
            'display_name' => $user->display_name,
            'roles' => array_values($user->roles),
            'capabilities' => $granted,
        ], 200);
    }
}
