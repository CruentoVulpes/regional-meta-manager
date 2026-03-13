<?php
/**
 * Plugin Name: Regional Meta Manager
 * Plugin URI: https://example.com/regional-meta-manager
 * Description: Управление региональными мета-данными страниц (lang, canonical, hreflang).
 * Version: 1.0.3
 * Author: Vlad
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: regional-meta-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RMM_VERSION', '1.0.3');
define('RMM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RMM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Auto-updates via Plugin Update Checker (GitHub).
if ( file_exists( RMM_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
    require RMM_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

    if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        $rmm_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/CruentoVulpes/regional-meta-manager/',
            __FILE__,
            'regional-meta-manager'
        );

        $rmm_update_checker->setBranch( 'main' );
    }
}

require_once RMM_PLUGIN_DIR . 'includes/class-regional-meta.php';

function rmm_init() {
    new RegionalMeta();
}
add_action('plugins_loaded', 'rmm_init');
