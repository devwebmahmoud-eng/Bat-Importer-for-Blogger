<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$mhbi_table_name = $wpdb->prefix . 'mhbi_redirects';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing custom plugin table during uninstall.
$wpdb->query(
    $wpdb->prepare('DROP TABLE IF EXISTS %i', $mhbi_table_name)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option('mhbi_settings');
delete_option('mhbi_import_job');
delete_option('mhbi_redirect_404_default_migrated');
delete_option('mhbi_redirect_cache_version');
