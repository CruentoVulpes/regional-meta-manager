<?php
/**
 * Regional Meta Manager plugin uninstall.
 *
 * @package RegionalMetaManager
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
    '_regional_lang',
    '_regional_canonical',
    '_regional_canonical_transfer_content',
    '_regional_hreflang'
)");

