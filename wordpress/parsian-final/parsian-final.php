<?php
/**
 * Plugin Name: PARSIAN FINAL Core
 * Description: Central business platform for Parsian Ghat'e — identity, CRM, sales, finance, inventory and sync API.
 * Version: 0.4.25
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Text Domain: parsian-final
 */
if (!defined('ABSPATH')) { exit; }
define('PARSIAN_FINAL_VERSION', '0.4.24');
define('PARSIAN_FINAL_FILE', __FILE__);
define('PARSIAN_FINAL_PATH', plugin_dir_path(__FILE__));
define('PARSIAN_FINAL_URL', plugin_dir_url(__FILE__));
require_once PARSIAN_FINAL_PATH . 'includes/class-install.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-roles.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-rest.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-crm.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-inventory.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-sync.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-finance.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-cheques.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-bank.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-ledger.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-sales.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-auth.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-panels.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-customer-portal.php';
require_once PARSIAN_FINAL_PATH . 'includes/class-backup.php';
register_activation_hook(__FILE__, ['Parsian_Final_Install', 'activate']);
register_deactivation_hook(__FILE__, ['Parsian_Final_Install', 'deactivate']);
add_action('plugins_loaded', static function (): void {
    Parsian_Final_Install::maybe_upgrade();
    Parsian_Final_Roles::register();
    (new Parsian_Final_REST())->register_routes();
    (new Parsian_Final_CRM())->register_routes();
    (new Parsian_Final_Inventory())->register_routes();
    (new Parsian_Final_Sync())->register_routes();
    (new Parsian_Final_Finance())->register_routes();
    (new Parsian_Final_Cheques())->register_routes();
    (new Parsian_Final_Bank())->register_routes();
    (new Parsian_Final_Sales())->register_routes();
    (new Parsian_Final_Ledger())->register_routes();
    (new Parsian_Final_Auth())->register_routes();
    (new Parsian_Final_Panels())->register_hooks();
    (new Parsian_Final_Customer_Portal())->register_routes();
    (new Parsian_Final_Backup())->register_routes();
});
