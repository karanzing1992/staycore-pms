<?php
/**
 * Plugin Name: StayCore PMS — Andaz Edition
 * Description: Mobile-first property management system for WordPress. Brand-neutral core with adapters for WooCommerce and future integrations.
 * Version: 0.4.1
 * Author: Andaz Vibe Stay
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: staycore-pms
 */
if (!defined('ABSPATH')) exit;

define('STAYCORE_PMS_VERSION', '0.4.1');
define('STAYCORE_PMS_FILE', __FILE__);
define('STAYCORE_PMS_DIR', plugin_dir_path(__FILE__));
define('STAYCORE_PMS_URL', plugin_dir_url(__FILE__));

require_once STAYCORE_PMS_DIR . 'includes/class-staycore-db.php';
require_once STAYCORE_PMS_DIR . 'includes/class-staycore-integrations.php';
require_once STAYCORE_PMS_DIR . 'includes/class-staycore-rest.php';
require_once STAYCORE_PMS_DIR . 'admin/class-staycore-admin.php';
require_once STAYCORE_PMS_DIR . 'public/class-staycore-public.php';

register_activation_hook(__FILE__, ['StayCore_DB', 'activate']);

add_action('plugins_loaded', static function () {
    StayCore_DB::maybe_upgrade();
    StayCore_Integrations::boot();
    StayCore_REST::boot();
    StayCore_Admin::boot();
    StayCore_Public::boot();
});
