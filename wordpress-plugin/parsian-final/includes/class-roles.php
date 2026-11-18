<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers application roles. Capabilities are checked on the server for every protected route.
 */
final class Parsian_Final_Roles
{
    public static function activate()
    {
        self::register_roles();
        update_option('parsian_final_version', PARSian_FINAL_VERSION, false);
    }

    public static function deactivate()
    {
        // Roles and data are intentionally retained on deactivation.
    }

    public static function register_roles()
    {
        add_role('parsian_manager', 'مدیر پارسیان', array(
            'read' => true,
            'parsian_manage_system' => true,
            'parsian_view_finance' => true,
            'parsian_manage_inventory' => true,
            'parsian_approve_documents' => true,
            'parsian_view_all_customers' => true,
        ));

        add_role('parsian_accountant', 'حسابدار پارسیان', array(
            'read' => true,
            'parsian_view_finance' => true,
            'parsian_manage_inventory' => true,
            'parsian_view_all_customers' => true,
        ));

        add_role('parsian_seller', 'فروشنده پارسیان', array(
            'read' => true,
            'parsian_use_crm' => true,
            'parsian_create_proforma' => true,
            'parsian_view_assigned_customers' => true,
        ));

        add_role('parsian_customer', 'مشتری پارسیان', array(
            'read' => true,
            'parsian_view_own_account' => true,
            'parsian_create_order' => true,
        ));

        // Site administrators retain access to the full application.
        $admin = get_role('administrator');
        if ($admin) {
            foreach (array(
                'parsian_manage_system',
                'parsian_view_finance',
                'parsian_manage_inventory',
                'parsian_approve_documents',
                'parsian_view_all_customers',
                'parsian_use_crm',
                'parsian_create_proforma',
            ) as $capability) {
                $admin->add_cap($capability);
            }
        }
    }
}
