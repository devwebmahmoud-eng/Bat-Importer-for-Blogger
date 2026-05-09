<?php
/**
 * Plugin Name: Bat Importer for Blogger
 * Description: Import public Blogger blogs into WordPress using the Blog ID, with image download, page import, and redirect support.
 * Version: 1.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Mahmoud Hamed
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bat-importer-for-blogger
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MHBI_VERSION', '1.0.1');
define('MHBI_FILE', __FILE__);
define('MHBI_DIR', plugin_dir_path(__FILE__));
define('MHBI_URL', plugin_dir_url(__FILE__));

require_once MHBI_DIR . 'includes/class-mhbi-utils.php';
require_once MHBI_DIR . 'includes/class-mhbi-feed-client.php';
require_once MHBI_DIR . 'includes/class-mhbi-importer.php';
require_once MHBI_DIR . 'includes/class-mhbi-admin.php';
require_once MHBI_DIR . 'includes/class-mhbi-redirector.php';

final class MHBI_Plugin {
    /** @var MHBI_Plugin|null */
    private static $instance = null;

    /** @var MHBI_Feed_Client */
    public $feed_client;

    /** @var MHBI_Importer */
    public $importer;

    /** @var MHBI_Admin */
    public $admin;

    /** @var MHBI_Redirector */
    public $redirector;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        self::maybe_initialize_settings();

        $this->feed_client = new MHBI_Feed_Client();
        $this->importer    = new MHBI_Importer($this->feed_client);
        $this->redirector  = new MHBI_Redirector();

        if (is_admin()) {
            $this->admin = new MHBI_Admin($this->importer, $this->feed_client);
        }

        add_action('wp_ajax_mhbi_start_import', array($this->importer, 'ajax_start_import'));
        add_action('wp_ajax_mhbi_process_batch', array($this->importer, 'ajax_process_batch'));
        add_action('wp_ajax_mhbi_reset_import', array($this->importer, 'ajax_reset_import'));
        add_action('wp_ajax_mhbi_stop_import', array($this->importer, 'ajax_stop_import'));
        add_action('wp_ajax_mhbi_full_reset', array($this->importer, 'ajax_full_reset'));

        add_action('before_delete_post', array($this, 'cleanup_post_redirects'));
    }
    public static function maybe_initialize_settings() {
        $migrated = (int) get_option('mhbi_redirect_404_default_migrated', 0);
        $settings = (array) get_option('mhbi_settings', array());

        if (empty($settings)) {
            add_option('mhbi_settings', MHBI_Utils::get_default_settings());
            update_option('mhbi_redirect_404_default_migrated', 1, false);

            return;
        }

        if ($migrated) {
            return;
        }

        $settings = wp_parse_args($settings, MHBI_Utils::get_default_settings());
        update_option('mhbi_settings', $settings, false);
        update_option('mhbi_redirect_404_default_migrated', 1, false);
    }

    public static function activate() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'mhbi_redirects';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            old_path varchar(500) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            old_host varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY old_path (old_path),
            KEY post_id (post_id),
            KEY old_host (old_host)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $defaults = MHBI_Utils::get_default_settings();

        $existing_settings = get_option('mhbi_settings', array());
        if (!$existing_settings) {
            add_option('mhbi_settings', $defaults);
            
        } else {
            $existing_settings = wp_parse_args((array) $existing_settings, $defaults);
            update_option('mhbi_settings', $existing_settings, false);
            
        }

        $cache_version = get_option('mhbi_redirect_cache_version', '1');
        if (!get_option('mhbi_redirect_cache_version')) {
            add_option('mhbi_redirect_cache_version', $cache_version, '', false);
        }
        
    }

    public function cleanup_post_redirects($post_id) {
        global $wpdb;

        $table_name = MHBI_Utils::get_redirect_table_name();
        $wpdb->delete($table_name, array('post_id' => (int) $post_id), array('%d')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        MHBI_Utils::bump_redirect_cache_version();
    }
}

register_activation_hook(MHBI_FILE, array('MHBI_Plugin', 'activate'));

MHBI_Plugin::instance();
