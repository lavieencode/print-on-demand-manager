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

// Define plugin constants
define('POD_MANAGER_VERSION', '1.0.0');
define('POD_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POD_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once POD_MANAGER_PLUGIN_DIR . 'includes/class-printify-platform.php';
require_once POD_MANAGER_PLUGIN_DIR . 'includes/class-pod-ajax.php';
require_once POD_MANAGER_PLUGIN_DIR . 'includes/class-pod-cron.php';
require_once POD_MANAGER_PLUGIN_DIR . 'admin/class-admin-menu.php';

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
    // Immediate file logging before WordPress functions
    $log_file = dirname(__FILE__) . '/deactivation_log.txt';
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Starting deactivation process\n", FILE_APPEND);
    
    try {
        // First, try to cancel any ongoing cache update
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Creating Printify Platform instance\n", FILE_APPEND);
        $printify = new POD_Printify_Platform();
        
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Checking if cache is updating\n", FILE_APPEND);
        $is_updating = $printify->is_cache_updating();
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Cache is updating: " . ($is_updating ? 'yes' : 'no') . "\n", FILE_APPEND);
        
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Attempting to cancel cache update\n", FILE_APPEND);
        $cancelled = $printify->cancel_cache_update();
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Cache update cancelled: " . ($cancelled ? 'yes' : 'no') . "\n", FILE_APPEND);
        
        // Remove all hooks and scheduled events
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Clearing scheduled events\n", FILE_APPEND);
        POD_Cron::clear_scheduled_events();
        
        // Remove AJAX handlers properly
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Removing AJAX handlers\n", FILE_APPEND);
        $ajax = new POD_Ajax();
        remove_action('wp_ajax_pod_refresh_cache', array($ajax, 'refresh_cache'));
        remove_action('wp_ajax_pod_cancel_cache', array($ajax, 'cancel_cache'));
        remove_action('wp_ajax_pod_get_cache_status', array($ajax, 'get_cache_status'));
        
        // Force cleanup of all cache-related options
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Cleaning up options\n", FILE_APPEND);
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
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Deleting option: " . $option . "\n", FILE_APPEND);
            $deleted = delete_option($option);
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Option deleted: " . ($deleted ? 'yes' : 'no') . "\n", FILE_APPEND);
        }
        
        // Remove any transients
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Removing transients\n", FILE_APPEND);
        delete_transient('pod_printify_all_products_detailed');
        
        // Force clear any WP cron hooks for our plugin
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Final cleanup of cron hooks\n", FILE_APPEND);
        $timestamp = wp_next_scheduled('pod_printify_cache_products');
        if ($timestamp) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Found scheduled event at: " . date('Y-m-d H:i:s', $timestamp) . "\n", FILE_APPEND);
            wp_unschedule_event($timestamp, 'pod_printify_cache_products');
        }
        wp_clear_scheduled_hook('pod_printify_cache_products');
        
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Deactivation completed successfully\n", FILE_APPEND);
    } catch (Exception $e) {
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error during deactivation: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $e->getTraceAsString() . "\n", FILE_APPEND);
        
        // Even if there's an error, try to force cleanup
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Attempting emergency cleanup\n", FILE_APPEND);
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

// Initialize the plugin
function pod_manager_init() {
    pod_manager_create_directories();
    
    // Initialize admin menu
    if (is_admin()) {
        $admin_menu = new POD_Admin_Menu();
        $admin_menu->init();
    }
    
    // Initialize AJAX handlers
    $ajax = new POD_Ajax();
}
add_action('init', 'pod_manager_init');
