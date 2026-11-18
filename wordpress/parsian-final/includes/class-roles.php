<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Roles {
    public static function register(): void {
        $caps = [
            'parsian_access_core',
            'parsian_manage_settings',
            'parsian_manage_users',
            'parsian_view_reports',
            'parsian_crm_read',
            'parsian_crm_write',
            'parsian_sales_create',
            'parsian_sales_approve',
            'parsian_finance_read',
            'parsian_finance_write',
            'parsian_inventory_read',
            'parsian_inventory_write',
            'parsian_sync_use',
        ];
        $administrator = get_role('administrator');
        if ($administrator) {
            foreach ($caps as $cap) { $administrator->add_cap($cap); }
        }
        self::add_role('parsian_manager', 'مدیر پارسیان', $caps);
        self::add_role('parsian_accountant', 'حسابدار پارسیان', [
            'parsian_access_core','parsian_view_reports','parsian_finance_read',
            'parsian_finance_write','parsian_inventory_read','parsian_inventory_write','parsian_sync_use'
        ]);
        self::add_role('parsian_seller', 'فروشنده پارسیان', [
            'parsian_access_core','parsian_crm_read','parsian_crm_write',
            'parsian_sales_create','parsian_sync_use'
        ]);
        self::add_role('parsian_customer', 'مشتری پارسیان', ['parsian_access_core','parsian_sync_use']);
    }
    private static function add_role(string $slug, string $name, array $caps): void {
        $role = get_role($slug) ?: add_role($slug, $name, ['read' => true]);
        if ($role) { foreach ($caps as $cap) { $role->add_cap($cap); } }
    }
}
