<?php
/**
 * POD Manager Cron Jobs
 */

class POD_Cron {
    /**
     * @var POD_Cron Single instance of this class
     */
    private static $instance = null;

    /**
     * Get single instance of this class
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the cron functionality
     */
    private function __construct() {
        add_action('pod_printify_cache_products', array($this, 'run_product_cache_update'));
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        add_action('admin_init', array($this, 'handle_manual_trigger'));
    }

    /**
     * Register custom cron schedules if needed
     * 
     * @param array $schedules Array of registered cron schedules
     * @return array Modified schedules array
     */
    public function register_cron_schedules($schedules) {
        // Add twice daily schedule if needed
        if (!isset($schedules['twicedaily'])) {
            $schedules['twicedaily'] = array(
                'interval' => 43200, // 12 hours in seconds
                'display' => __('Twice Daily')
            );
        }
        return $schedules;
    }

    /**
     * Run the product cache update
     */
    public function run_product_cache_update() {
        // Check if another cache update is in progress
        $printify = new POD_Printify_Platform();
        if ($printify->is_cache_updating()) {
            error_log('POD Manager: Skipping cache update - another update is in progress');
            return;
        }

        $result = $printify->cache_all_products_detailed();
        
        if (is_wp_error($result)) {
            error_log('POD Manager: Cron cache update failed: ' . $result->get_error_message());
        } else {
            error_log('POD Manager: Cron cache update completed successfully');
        }
    }

    /**
     * Handle manual trigger of cache update
     */
    public function handle_manual_trigger() {
        if (
            isset($_GET['action']) && 
            $_GET['action'] === 'pod_refresh_cache' && 
            isset($_GET['_wpnonce']) && 
            wp_verify_nonce($_GET['_wpnonce'], 'pod_refresh_cache') &&
            current_user_can('manage_options')
        ) {
            // Cancel any existing cache update
            $printify = new POD_Printify_Platform();
            if ($printify->is_cache_updating()) {
                $printify->cancel_cache_update();
            }
            
            // Run the cache update
            $result = $this->run_product_cache_update();
            
            if (is_wp_error($result)) {
                wp_die('Error refreshing cache: ' . $result->get_error_message());
            }
            
            wp_safe_redirect(add_query_arg('cache_refreshed', 'true', wp_get_referer()));
            exit;
        }
    }

    /**
     * Schedule the cron events
     */
    public static function schedule_events() {
        self::clear_scheduled_events(); // Clear existing events first
        
        if (!wp_next_scheduled('pod_printify_cache_products')) {
            // Schedule first run for tomorrow at a random hour to spread server load
            $tomorrow = strtotime('tomorrow') + rand(0, 86400); // Random time tomorrow
            wp_schedule_event($tomorrow, 'daily', 'pod_printify_cache_products');
        }
    }

    /**
     * Clear the scheduled events and cleanup hooks
     */
    public static function clear_scheduled_events() {
        $log_file = dirname(dirname(__FILE__)) . '/deactivation_log.txt';
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Starting clear_scheduled_events\n", FILE_APPEND);
        
        try {
            // First remove all hooks to prevent any new scheduling
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Removing pod_printify_cache_products actions\n", FILE_APPEND);
            remove_action('pod_printify_cache_products', array(self::get_instance(), 'run_product_cache_update'));
            
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Removing init actions\n", FILE_APPEND);
            remove_action('init', array(self::get_instance(), 'register_cron_schedules'));
            
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Removing admin_init actions\n", FILE_APPEND);
            remove_action('admin_init', array(self::get_instance(), 'handle_manual_trigger'));
            
            // Then clear all scheduled events
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Checking for scheduled events\n", FILE_APPEND);
            $timestamp = wp_next_scheduled('pod_printify_cache_products');
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Next scheduled timestamp: " . ($timestamp ? date('Y-m-d H:i:s', $timestamp) : 'none') . "\n", FILE_APPEND);
            
            if ($timestamp) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Unscheduling event at timestamp\n", FILE_APPEND);
                wp_unschedule_event($timestamp, 'pod_printify_cache_products');
            }
            
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Clearing scheduled hook\n", FILE_APPEND);
            wp_clear_scheduled_hook('pod_printify_cache_products');
            
            // Reset the singleton instance
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Resetting singleton instance\n", FILE_APPEND);
            self::$instance = null;
            
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] clear_scheduled_events completed successfully\n", FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] Error in clear_scheduled_events: " . $e->getMessage() . "\n", FILE_APPEND);
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - [CRON] " . $e->getTraceAsString() . "\n", FILE_APPEND);
        }
    }
}
