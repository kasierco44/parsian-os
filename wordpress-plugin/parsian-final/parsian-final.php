<?php
/**
 * Plugin Name: PARSIAN FINAL Core
 * Description: هسته مرکزی پارسیان فاینال؛ احراز هویت، نقش‌ها، داشبورد نقش‌محور، API نسخه‌بندی‌شده و زیرساخت همگام‌سازی.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Parsian Ghat'e
 * Text Domain: parsian-final
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PARSian_FINAL_VERSION', '0.1.0');
define('PARSian_FINAL_FILE', __FILE__);
define('PARSian_FINAL_DIR', plugin_dir_path(__FILE__));
define('PARSian_FINAL_URL', plugin_dir_url(__FILE__));

require_once PARSian_FINAL_DIR . 'includes/class-roles.php';
require_once PARSian_FINAL_DIR . 'includes/class-schema.php';
require_once PARSian_FINAL_DIR . 'includes/class-rest-api.php';
require_once PARSian_FINAL_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, array('Parsian_Final_Roles', 'activate'));
register_activation_hook(__FILE__, array('Parsian_Final_Schema', 'install'));
register_deactivation_hook(__FILE__, array('Parsian_Final_Roles', 'deactivate'));

add_action('plugins_loaded', function () {
    Parsian_Final_Rest_API::init();
    Parsian_Final_Admin::init();
});

add_action('init', function () {
    load_plugin_textdomain('parsian-final', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
