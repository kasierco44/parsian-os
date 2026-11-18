<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Versioned REST API. Sensitive actions require server-side capability checks.
 */
final class Parsian_Final_Rest_API
{
    const NAMESPACE = 'parsian-final/v1';

    public static function init()
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes()
    {
        register_rest_route(self::NAMESPACE, '/health', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'health'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NAMESPACE, '/me', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'current_user'),
            'permission_callback' => array(__CLASS__, 'is_authenticated'),
        ));

        register_rest_route(self::NAMESPACE, '/manager/summary', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'manager_summary'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
    }

    public static function health()
    {
        return new WP_REST_Response(array(
            'ok' => true,
            'application' => 'PARSIAN FINAL',
            'version' => PARSian_FINAL_VERSION,
            'api' => self::NAMESPACE,
        ), 200);
    }

    public static function is_authenticated()
    {
        return is_user_logged_in();
    }

    public static function can_manage()
    {
        return current_user_can('parsian_manage_system');
    }

    public static function current_user()
    {
        $user = wp_get_current_user();
        return new WP_REST_Response(array(
            'id' => (int) $user->ID,
            'display_name' => $user->display_name,
            'roles' => array_values((array) $user->roles),
            'capabilities' => array(
                'manage_system' => current_user_can('parsian_manage_system'),
                'view_finance' => current_user_can('parsian_view_finance'),
                'manage_inventory' => current_user_can('parsian_manage_inventory'),
                'approve_documents' => current_user_can('parsian_approve_documents'),
                'use_crm' => current_user_can('parsian_use_crm'),
            ),
        ), 200);
    }

    public static function manager_summary()
    {
        return new WP_REST_Response(array(
            'status' => 'foundation',
            'message' => 'هسته مرکزی فعال است؛ ماژول‌های عملیاتی در مراحل بعدی اضافه می‌شوند.',
            'schema_version' => get_option('parsian_final_schema_version', '0'),
        ), 200);
    }
}
