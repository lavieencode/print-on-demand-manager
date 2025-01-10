<?php
/**
 * Handles AJAX requests for the admin interface
 */
class POD_Admin_Ajax {
    /**
     * Initialize AJAX handlers
     */
    public function __construct() {
        // Cache management
        add_action('wp_ajax_pod_refresh_cache', array($this, 'refresh_cache'));
        add_action('wp_ajax_pod_get_cache_status', array($this, 'get_cache_status'));
        add_action('wp_ajax_pod_cancel_cache', array($this, 'cancel_cache'));
        add_action('wp_ajax_pod_get_cache_data', array($this, 'get_cache_data'));
        add_action('wp_ajax_pod_debug_cache', array($this, 'debug_cache'));
        add_action('wp_ajax_pod_debug_cache_force_reset', array($this, 'debug_cache_force_reset'));
        
        // Quick Add Wizard handlers
        add_action('wp_ajax_pod_create_products', array($this, 'create_products'));
        add_action('wp_ajax_pod_upload_patterns', array($this, 'upload_patterns'));
        add_action('wp_ajax_pod_save_product_config', array($this, 'save_product_config'));
        add_action('wp_ajax_pod_save_design_settings', array($this, 'save_design_settings'));
        
        // Add nonces to admin page
        add_action('admin_enqueue_scripts', array($this, 'add_admin_nonces'));
        
        // Other AJAX handlers
        add_action('wp_ajax_pod_verify_connection', array($this, 'verify_connection'));
        add_action('wp_ajax_pod_process_cache_update', array($this, 'process_cache_update'));
        add_action('wp_ajax_pod_search_products', array($this, 'search_products'));
        add_action('wp_ajax_pod_get_product_details', array($this, 'get_product_details'));
        add_action('wp_ajax_pod_get_shipping_info', array($this, 'get_shipping_info'));
        add_action('wp_ajax_pod_get_variant_pricing', array($this, 'get_variant_pricing'));
    }

    /**
     * Add nonces to admin page
     */
    public function add_admin_nonces($hook) {
        if ($hook !== 'toplevel_page_pod-manager') {
            return;
        }

        error_log('POD Manager: Localizing admin script with nonces');
        
        wp_localize_script('pod-admin-js', 'podManagerAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonces' => array(
                'refresh_cache' => wp_create_nonce('pod_ajax_nonce'),
                'get_cache_status' => wp_create_nonce('pod_ajax_nonce'),
                'cancel_cache' => wp_create_nonce('pod_ajax_nonce'),
                'view_cache' => wp_create_nonce('pod_ajax_nonce'),
                'debug_cache' => wp_create_nonce('pod_ajax_nonce'),
                'verify_connection' => wp_create_nonce('pod_ajax_nonce'),
                'create_products' => wp_create_nonce('pod_ajax_nonce'),
                'upload_patterns' => wp_create_nonce('pod_ajax_nonce'),
                'save_product_config' => wp_create_nonce('pod_ajax_nonce'),
                'save_design_settings' => wp_create_nonce('pod_ajax_nonce')
            ),
            'debug' => WP_DEBUG
        ));
        
        error_log('POD Manager: Nonces created: ' . implode(', ', array_keys(array(
            'refresh_cache' => wp_create_nonce('pod_ajax_nonce'),
            'get_cache_status' => wp_create_nonce('pod_ajax_nonce'),
            'cancel_cache' => wp_create_nonce('pod_ajax_nonce'),
            'view_cache' => wp_create_nonce('pod_ajax_nonce'),
            'debug_cache' => wp_create_nonce('pod_ajax_nonce'),
            'verify_connection' => wp_create_nonce('pod_ajax_nonce'),
            'create_products' => wp_create_nonce('pod_ajax_nonce'),
            'upload_patterns' => wp_create_nonce('pod_ajax_nonce'),
            'save_product_config' => wp_create_nonce('pod_ajax_nonce'),
            'save_design_settings' => wp_create_nonce('pod_ajax_nonce')
        ))));
    }

    /**
     * Send JSON response with consistent format
     */
    private function send_json_response($success, $data) {
        if (is_wp_error($data)) {
            $data = array('message' => $data->get_error_message());
        }
        wp_send_json(array(
            'success' => $success,
            'data' => $data
        ));
    }

    /**
     * Verify API connection
     */
    public function verify_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            $this->send_json_response(false, array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            $this->send_json_response(false, array('message' => 'Insufficient permissions'));
            return;
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        if (empty($api_key)) {
            $this->send_json_response(false, array('message' => 'API key is required'));
            return;
        }

        error_log('POD Manager: Verifying connection with API key length: ' . strlen($api_key));
        
        $printify = new POD_Printify_Platform();
        $result = $printify->set_api_key($api_key);

        if (is_wp_error($result)) {
            error_log('POD Manager: Connection verification failed: ' . $result->get_error_message());
            $this->send_json_response(false, array('message' => $result->get_error_message()));
            return;
        }

        error_log('POD Manager: Connection verified successfully');
        $this->send_json_response(true, array(
            'message' => 'Connection verified successfully!',
            'shop_count' => $result['shop_count'],
            'selected_shop' => $result['selected_shop']
        ));
    }

    /**
     * Refresh the cache
     */
    public function refresh_cache() {
        error_log('POD Manager: Refresh cache called');

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            error_log('POD Manager: Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!current_user_can('manage_options')) {
            error_log('POD Manager: Insufficient permissions');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        // Get platform instance
        $printify = POD_Printify_Platform::get_instance();

        // Check for running update
        if ($printify->is_cache_updating()) {
            error_log('POD Manager: Cache update already in progress');
            wp_send_json_error(array('message' => 'Cache update already in progress'));
            return;
        }

        // Clear any existing scheduled tasks first
        wp_clear_scheduled_hook('pod_printify_process_cache_chunk');
        
        // Force reset any existing cache flags before starting
        error_log('POD Manager: Force resetting cache flags before starting new update');
        delete_option('pod_printify_cache_updating');
        delete_option('pod_printify_last_cache_update');
        delete_option('pod_printify_cache_progress');
        delete_option('pod_printify_cache_start_time');
        delete_option('pod_printify_last_activity');
        delete_option('pod_printify_cache_current_blueprint');
        delete_option('pod_printify_cache_total_blueprints');
        delete_transient('pod_printify_emergency_stop');
        delete_transient('pod_printify_cache_process');
        
        // Set up new cache update
        update_option('pod_printify_cache_updating', true);
        update_option('pod_printify_last_cache_update', current_time('mysql'));
        update_option('pod_printify_cache_start_time', time());
        update_option('pod_printify_last_activity', time());
        update_option('pod_printify_cache_progress', array(
            'status' => 'running',
            'phase' => 'initializing',
            'current' => 0,
            'total' => 0,
            'message' => 'Starting cache update...'
        ));

        error_log('POD Manager: Cache flags reset and initialized');

        // Start the update process
        try {
            $result = $printify->update_all_products_cache();
            if (is_wp_error($result)) {
                error_log('POD Manager: Failed to start cache update: ' . $result->get_error_message());
                $printify->cancel_cache_update();
                wp_send_json_error(array('message' => $result->get_error_message()));
                return;
            }
            wp_send_json_success(array('message' => 'Cache update started'));
        } catch (Exception $e) {
            error_log('POD Manager: Exception starting cache update: ' . $e->getMessage());
            $printify->cancel_cache_update();
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Process the cache update
     */
    public function process_cache_update($args = array()) {
        error_log('POD Manager: Process cache update called');
        error_log('POD Manager: Args: ' . print_r($args, true));

        $is_internal = isset($args['internal_call']) && $args['internal_call'];
        
        if (!$is_internal) {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
                error_log('POD Manager: Nonce verification failed');
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }

            if (!current_user_can('manage_options')) {
                error_log('POD Manager: Insufficient permissions');
                wp_send_json_error(array('message' => 'Insufficient permissions'));
                return;
            }
        }

        $printify = new POD_Printify_Platform();
        
        // Check if we have the required credentials
        if (!$printify->has_api_key() || !$printify->has_shop_id()) {
            error_log('POD Manager: Missing API key or shop ID');
            error_log('POD Manager: API Key exists: ' . ($printify->has_api_key() ? 'yes' : 'no'));
            error_log('POD Manager: Shop ID exists: ' . ($printify->has_shop_id() ? 'yes' : 'no'));
            delete_option('pod_printify_cache_updating');
            wp_send_json_error(array('message' => 'API key or shop ID not set'));
            return;
        }

        try {
            // Start the update process
            $result = $printify->update_all_products_cache();
            error_log('POD Manager: Cache update result: ' . print_r($result, true));
            
            if (is_wp_error($result)) {
                error_log('POD Manager: Cache update failed: ' . $result->get_error_message());
                delete_option('pod_printify_cache_updating');
                wp_send_json_error(array('message' => $result->get_error_message()));
                return;
            }
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            error_log('POD Manager: Exception during cache update: ' . $e->getMessage());
            delete_option('pod_printify_cache_updating');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Cancel cache update
     */
    public function cancel_cache() {
        error_log('POD Manager: Cancel request received');
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            error_log('POD Manager: Invalid nonce for cancel_cache');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!current_user_can('manage_options')) {
            error_log('POD Manager: Unauthorized access to cancel_cache');
            wp_send_json_error(array('message' => 'Unauthorized access'));
            return;
        }

        try {
            $platform = POD_Printify_Platform::get_instance();
            
            // First try to terminate any running process
            $platform->terminate_cache_update();
            
            // Then cancel and clean up
            $result = $platform->cancel_cache_update();
            
            error_log('POD Manager: Cancel result: ' . print_r($result, true));
            
            // Force status update
            $progress = get_option('pod_printify_cache_progress', array());
            $progress['status'] = 'cancelled';
            $progress['current_item'] = 'Cache update cancelled';
            $progress['cancelled_at'] = current_time('mysql');
            update_option('pod_printify_cache_progress', $progress);
            
            // Clean up
            delete_option('pod_printify_cache_updating');
            delete_transient('pod_printify_cache_process');
            delete_transient('pod_printify_emergency_stop');
            
            wp_send_json_success(array(
                'message' => 'Cache update cancelled successfully',
                'progress' => $progress
            ));
        } catch (Exception $e) {
            error_log('POD Manager: Failed to cancel cache update: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Failed to cancel cache update: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Get cache update status
     */
    public function get_cache_status() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            error_log('POD Manager: Invalid or missing nonce for get_cache_status');
            wp_send_json_error(array(
                'message' => 'Security check failed'
            ), 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            error_log('POD Manager: Unauthorized access to get_cache_status');
            wp_send_json_error(array(
                'message' => 'Unauthorized access'
            ), 403);
            return;
        }

        $platform = POD_Printify_Platform::get_instance();
        $progress = get_option('pod_printify_cache_progress', array());
        $last_activity = (int)get_option('pod_printify_last_activity', 0);
        $process_id = get_transient('pod_printify_cache_process');
        
        // Add default values if not set
        $progress = array_merge(array(
            'status' => 'unknown',
            'phase' => '',
            'current_item' => '',
            'current' => 0,
            'total' => 0,
            'percentage' => 0,
            'last_update' => ''
        ), $progress);
        
        // Check for timeout (2 minutes without activity)
        if ($progress['status'] === 'running' && 
            $last_activity > 0 && 
            (time() - $last_activity) > 120) {
            
            error_log('POD Manager: Cache update timeout detected');
            $platform->cancel_cache_update();
            $progress = get_option('pod_printify_cache_progress', array());
            $progress['status'] = 'error';
            $progress['error'] = 'Update timed out due to inactivity';
            update_option('pod_printify_cache_progress', $progress);
        }
        
        // Check if we're actually updating
        $is_updating = $platform->is_cache_updating();
        if ($is_updating && $progress['status'] !== 'running') {
            $progress['status'] = 'running';
            update_option('pod_printify_cache_progress', $progress);
        } else if (!$is_updating && $progress['status'] === 'running') {
            $progress['status'] = 'error';
            $progress['error'] = 'Cache update process died unexpectedly';
            update_option('pod_printify_cache_progress', $progress);
        }
        
        $response = array(
            'is_running' => $progress['status'] === 'running',
            'is_complete' => $progress['status'] === 'complete',
            'progress' => $progress,
            'debug_info' => array(
                'process_id' => $process_id,
                'last_activity' => $last_activity ? date('Y-m-d H:i:s', $last_activity) : 'never',
                'last_activity_age' => $last_activity ? human_time_diff($last_activity) : 'never',
                'is_updating' => $is_updating
            )
        );
        
        error_log('POD Manager: Cache status response: ' . print_r($response, true));
        wp_send_json_success($response);
    }

    /**
     * Debug cache - reset flags and clear any running processes
     */
    public function debug_cache() {
        error_log('POD Manager: Debug cache - checking nonce');
        
        if (!isset($_POST['nonce'])) {
            error_log('POD Manager: Debug cache - nonce not provided');
            wp_send_json_error(array(
                'message' => 'Security token missing'
            ), 403);
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            error_log('POD Manager: Debug cache - invalid nonce provided');
            wp_send_json_error(array(
                'message' => 'Security check failed. Please refresh the page and try again.'
            ), 403);
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('POD Manager: Debug cache - unauthorized access attempt');
            wp_send_json_error(array(
                'message' => 'Unauthorized access'
            ), 403);
            return;
        }
        
        try {
            error_log('POD Manager: Debug cache - resetting flags');
            
            global $wpdb;
            $options_table = $wpdb->prefix . 'options';
            
            // Reset cache flags
            update_option('pod_cache_is_running', false);
            update_option('pod_cache_last_run', '');
            update_option('pod_cache_progress', 0);
            update_option('pod_cache_total', 0);
            update_option('pod_cache_current_phase', '');
            update_option('pod_cache_current_item', '');
            
            // Clear any transients related to cache
            $wpdb->query("DELETE FROM $options_table WHERE option_name LIKE '_transient_pod_cache_%'");
            $wpdb->query("DELETE FROM $options_table WHERE option_name LIKE '_transient_timeout_pod_cache_%'");
            
            error_log('POD Manager: Debug cache - flags reset successfully');
            
            wp_send_json_success(array(
                'message' => 'Cache flags reset successfully'
            ));
            
        } catch (Exception $e) {
            error_log('POD Manager: Debug cache error - ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Failed to reset cache flags: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Debug function to force reset cache flags
     */
    public function debug_cache_force_reset() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        error_log('POD Manager: Force resetting cache flags');
        
        // Store current state for logging
        $previous_state = array(
            'was_updating' => get_option('pod_printify_cache_updating', false),
            'last_update' => get_option('pod_printify_last_cache_update'),
            'progress' => get_option('pod_printify_cache_progress', array())
        );
        error_log('POD Manager: Previous state: ' . print_r($previous_state, true));
        
        // Clear all cache-related options
        $options_to_delete = array(
            'pod_printify_cache_updating',
            'pod_printify_cache_progress',
            'pod_printify_cache_current_blueprint',
            'pod_printify_last_cache_update',
            'pod_printify_cache_start_time',
            'pod_printify_last_activity',
            'pod_printify_force_stop',
            'pod_printify_cache_blueprints',
            'pod_printify_cache_total_blueprints',
            'pod_printify_total_products',
            'pod_printify_total_blueprints',
            'pod_printify_last_updated'
        );
        
        foreach ($options_to_delete as $option) {
            delete_option($option);
        }
        
        // Clear any transients
        delete_transient('pod_printify_emergency_stop');
        delete_transient('pod_printify_rate_limit');
        
        // Clear scheduled cron events
        wp_clear_scheduled_hook('pod_printify_process_cache_chunk');
        
        error_log('POD Manager: Cache flags reset successfully');
        
        wp_send_json_success(array(
            'message' => 'Cache flags reset successfully',
            'previous_state' => $previous_state
        ));
    }

    /**
     * Search products
     */
    public function search_products() {
        // TO DO: implement search products functionality
    }

    /**
     * Get product details
     */
    public function get_product_details() {
        // TO DO: implement get product details functionality
    }

    /**
     * Get shipping info
     */
    public function get_shipping_info() {
        // TO DO: implement get shipping info functionality
    }

    /**
     * Get variant pricing
     */
    public function get_variant_pricing() {
        // TO DO: implement get variant pricing functionality
    }

    /**
     * Get cache data summary
     */
    public function get_cache_data() {
        check_ajax_referer('pod_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        try {
            error_log('POD Manager: Getting cache data');
            $db_manager = POD_DB_Manager::get_instance();
            
            // First check if tables exist
            $tables_check = $db_manager->tables_exist();
            if ($tables_check !== true) {
                error_log('POD Manager: Cache tables missing: ' . print_r($tables_check, true));
                wp_send_json_error(array(
                    'message' => 'Cache tables do not exist',
                    'missing_tables' => $tables_check
                ));
                return;
            }
            
            // Get cache summary
            $summary = $db_manager->get_cache_summary();
            error_log('POD Manager: Cache summary: ' . print_r($summary, true));
            
            wp_send_json_success(array(
                'message' => 'Cache data retrieved successfully',
                'data' => $summary
            ));
            
        } catch (Exception $e) {
            error_log('POD Manager: Error getting cache data: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Failed to get cache data: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Handle product creation from wizard
     */
    public function create_products() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        // Get wizard state
        $wizard_state = json_decode(stripslashes($_POST['wizard_state']), true);
        if (!$wizard_state) {
            wp_send_json_error(array('message' => 'Invalid wizard state'));
            return;
        }

        // Get platform instance
        $printify = POD_Printify_Platform::get_instance();

        try {
            // Process pattern files
            $pattern_files = array();
            foreach ($_FILES as $key => $file) {
                if (strpos($key, 'pattern_') === 0) {
                    $upload = wp_handle_upload($file, array('test_form' => false));
                    if (isset($upload['error'])) {
                        throw new Exception('Failed to upload pattern: ' . $upload['error']);
                    }
                    $pattern_files[] = $upload['url'];
                }
            }

            // Create products for each selected product
            $created_products = array();
            foreach ($wizard_state['selectedProducts'] as $product) {
                $config = $wizard_state['productConfigs'][$product['id']];
                
                // Prepare product data
                $product_data = array(
                    'title' => str_replace(
                        array('{pattern_name}', '{product_name}'),
                        array($wizard_state['mainPattern']['file']['name'], $product['title']),
                        $wizard_state['finalSettings']['title_format']
                    ),
                    'description' => str_replace(
                        array('{pattern_name}', '{product_name}', '{materials}'),
                        array($wizard_state['mainPattern']['file']['name'], $product['title'], $product['materials']),
                        $wizard_state['finalSettings']['description_template']
                    ),
                    'print_provider_id' => $config['provider_id'],
                    'print_areas' => array(
                        'front' => array(
                            'src' => $wizard_state['mainPattern']['dataUrl'],
                            'position' => $wizard_state['designSettings']['position'],
                            'scale' => $wizard_state['designSettings']['scale']
                        )
                    ),
                    'variants' => $config['variants'],
                    'price_adjustment' => array(
                        'type' => $wizard_state['finalSettings']['price_adjustment'],
                        'value' => floatval($wizard_state['finalSettings']['price_value'])
                    )
                );

                // Create product
                $result = $printify->create_product($product_data);
                if (is_wp_error($result)) {
                    throw new Exception('Failed to create product ' . $product['title'] . ': ' . $result->get_error_message());
                }

                $created_products[] = $result;
            }

            wp_send_json_success(array(
                'message' => count($created_products) . ' products created successfully',
                'products' => $created_products
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Handle pattern file uploads
     */
    public function upload_patterns() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        try {
            $uploaded_files = array();
            foreach ($_FILES as $file) {
                $upload = wp_handle_upload($file, array('test_form' => false));
                if (isset($upload['error'])) {
                    throw new Exception('Failed to upload file: ' . $upload['error']);
                }
                $uploaded_files[] = $upload;
            }

            wp_send_json_success(array(
                'message' => count($uploaded_files) . ' files uploaded successfully',
                'files' => $uploaded_files
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Save product configuration
     */
    public function save_product_config() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        $config = json_decode(stripslashes($_POST['config']), true);
        if (!$config) {
            wp_send_json_error(array('message' => 'Invalid configuration data'));
            return;
        }

        wp_send_json_success(array(
            'message' => 'Configuration saved successfully',
            'config' => $config
        ));
    }

    /**
     * Save design settings
     */
    public function save_design_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        $settings = json_decode(stripslashes($_POST['settings']), true);
        if (!$settings) {
            wp_send_json_error(array('message' => 'Invalid settings data'));
            return;
        }

        wp_send_json_success(array(
            'message' => 'Design settings saved successfully',
            'settings' => $settings
        ));
    }
}
