<?php
/**
 * Plugin Name: Print on Demand Manager
 * Description: Integrates with Printify for print-on-demand product management
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: pod-manager
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

register_deactivation_hook(__FILE__, 'pod_deactivate');

/**
 * The code that runs during plugin deactivation.
 */
function pod_deactivate() {
    // Clean up any running cache updates
    $printify = POD_Printify_Platform::get_instance();
    $printify->cancel_cache_update();
    
    // Clean up options
    delete_option('pod_printify_cache_updating');
    delete_option('pod_printify_cache_progress');
    delete_option('pod_printify_last_cache_update');
    
    // Clear scheduled hooks
    wp_clear_scheduled_hook('pod_printify_cache_products');
}

// Define plugin constants
define('POD_MANAGER_VERSION', time()); // Force cache bust during development
define('POD_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
$plugin_url = plugin_dir_url(__FILE__);

// Ensure URL has correct protocol
if (is_ssl()) {
    $plugin_url = str_replace('http://', 'https://', $plugin_url);
}
define('POD_MANAGER_PLUGIN_URL', $plugin_url);

// Verify file exists
$js_file = POD_MANAGER_PLUGIN_DIR . 'admin/js/admin.js';
$css_file = POD_MANAGER_PLUGIN_DIR . 'admin/css/admin.css';

// Include required files
require_once POD_MANAGER_PLUGIN_DIR . 'includes/class-printify-platform.php';
require_once POD_MANAGER_PLUGIN_DIR . 'admin/class-admin-menu.php';
require_once POD_MANAGER_PLUGIN_DIR . 'admin/class-admin-ajax.php';
require_once POD_MANAGER_PLUGIN_DIR . 'includes/class-pod-ajax.php';
require_once POD_MANAGER_PLUGIN_DIR . 'includes/class-pod-cron.php';

// Activation hook
register_activation_hook(__FILE__, 'pod_manager_activate');
function pod_manager_activate() {
    // Check if API key exists
    if (!get_option('pod_printify_api_key')) {
        add_option('pod_printify_api_key', '');
    }

    // Schedule cron events
    POD_Cron::schedule_events();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'pod_manager_deactivate');
function pod_manager_deactivate() {
    try {
        // Force cleanup of all cache-related options
        $options_to_delete = array(
            'pod_printify_cache_updating',
            'pod_printify_cache_progress',
            'pod_printify_all_products_detailed',
            'pod_printify_all_products_detailed_temp',
            'pod_printify_last_cache_update',
            'pod_printify_products_cache',
            'pod_printify_products_cache_time'
        );
        
        foreach ($options_to_delete as $option) {
            delete_option($option);
        }
        
        // Remove any transients
        delete_transient('pod_printify_all_products_detailed');
        
        // Remove AJAX handlers properly
        $ajax = POD_Ajax::get_instance();
        remove_action('wp_ajax_pod_refresh_cache', array($ajax, 'refresh_cache'));
        remove_action('wp_ajax_pod_cancel_cache', array($ajax, 'cancel_cache'));
        remove_action('wp_ajax_pod_get_cache_status', array($ajax, 'get_cache_status'));
        
        // Force clear any WP cron hooks for our plugin
        $timestamp = wp_next_scheduled('pod_printify_cache_products');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'pod_printify_cache_products');
        }
        wp_clear_scheduled_hook('pod_printify_cache_products');
        
        // Remove all hooks and scheduled events
        POD_Cron::clear_scheduled_events();
        
    } catch (Exception $e) {
        error_log('POD Manager: Error during deactivation: ' . $e->getMessage());
        // Even if there's an error, try to force cleanup
        delete_option('pod_printify_cache_updating');
        delete_option('pod_printify_all_products_detailed_temp');
        wp_clear_scheduled_hook('pod_printify_cache_products');
    }
}

// Create required directories
function pod_manager_create_directories() {
    $upload_dir = wp_upload_dir();
    $pod_upload_dir = $upload_dir['basedir'] . '/pod-manager';
    
    if (!file_exists($pod_upload_dir)) {
        wp_mkdir_p($pod_upload_dir);
    }
}

/**
 * Initialize the plugin
 */
function pod_manager_init() {
    pod_manager_create_directories();
    
    // Initialize admin menu
    if (is_admin()) {
        $admin_menu = new POD_Admin_Menu();
        $admin_menu->init();
    }
    
    // Initialize AJAX handlers
    new POD_Admin_Ajax(); // Constructor handles initialization
    
    // Get cron instance (initialization happens in constructor)
    POD_Cron::get_instance();
}
add_action('plugins_loaded', 'pod_manager_init');
