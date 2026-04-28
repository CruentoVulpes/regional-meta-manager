<?php
/**
 * Plugin Name: Regional Meta Manager
 * Plugin URI: https://example.com/regional-meta-manager
 * Description: Regional page meta (lang, canonical, hreflang).
 * Version: 1.1.1
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

define('RMM_VERSION', '1.1.1');
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

        // Use branch main only: PUC otherwise prefers releases/tags over main.
        add_filter(
            'puc_vcs_update_detection_strategies-regional-meta-manager',
            static function ( $strategies ) {
                if ( isset( $strategies['branch'] ) ) {
                    return array( 'branch' => $strategies['branch'] );
                }
                return $strategies;
            },
            10,
            1
        );

        // Unauthenticated GitHub API is ~60 req/hour per IP (403 on busy hosts); PAT ~5000/hour. Never commit tokens.
        $github_token = '';
        if ( defined( 'RMM_GITHUB_TOKEN' ) && RMM_GITHUB_TOKEN ) {
            $github_token = (string) RMM_GITHUB_TOKEN;
        } elseif ( defined( 'RMM_GITHUB_TOKEN_FILE' ) && RMM_GITHUB_TOKEN_FILE ) {
            $token_file = RMM_GITHUB_TOKEN_FILE;
            if ( is_string( $token_file ) && is_readable( $token_file ) ) {
                $raw = file_get_contents( $token_file );
                if ( is_string( $raw ) && $raw !== '' ) {
                    $raw          = str_replace( array( "\r\n", "\r" ), "\n", $raw );
                    $line         = explode( "\n", $raw, 2 );
                    $github_token = trim( $line[0] );
                }
            }
        } elseif ( function_exists( 'getenv' ) ) {
            $from_env = getenv( 'RMM_GITHUB_TOKEN' );
            if ( is_string( $from_env ) && $from_env !== '' ) {
                $github_token = $from_env;
            }
        }
        $github_token = apply_filters( 'rmm_github_token', $github_token );
        if ( $github_token !== '' ) {
            $rmm_update_checker->setAuthentication( $github_token );
        }
    }
}

require_once RMM_PLUGIN_DIR . 'includes/class-regional-meta.php';
require_once RMM_PLUGIN_DIR . 'includes/class-bulk-manager.php';

function rmm_init() {
    new RegionalMeta();
    new RMMBulkManager();
}
add_action('plugins_loaded', 'rmm_init');
