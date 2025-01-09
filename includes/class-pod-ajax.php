<?php
/**
 * Handles all AJAX requests for the plugin
 */
class POD_Ajax {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_pod_refresh_cache', array($this, 'refresh_cache'));
        add_action('wp_ajax_pod_cancel_cache', array($this, 'cancel_cache'));
        add_action('wp_ajax_pod_get_cache_status', array($this, 'get_cache_status'));
        add_action('wp_ajax_pod_search_products', array($this, 'search_products'));
        add_action('wp_ajax_pod_upload_image', array($this, 'upload_image'));
        add_action('wp_ajax_pod_create_product', array($this, 'create_product'));
    }

    /**
     * Verify nonce and user capabilities
     */
    private function verify_request($nonce_action) {
        if (!check_ajax_referer($nonce_action, 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            wp_die();
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            wp_die();
        }

        return true;
    }

    /**
     * Refresh product cache
     */
    public function refresh_cache() {
        $this->verify_request('pod_refresh_cache');

        $printify = new POD_Printify_Platform();
        
        // Check if cache update is already running
        if ($printify->is_cache_updating()) {
            wp_send_json_error('Cache update already in progress');
            wp_die();
        }

        // Start cache update
        $result = $printify->cache_all_products_detailed();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Cache update started');
        }
        
        wp_die();
    }

    /**
     * Cancel cache update
     */
    public function cancel_cache() {
        $this->verify_request('pod_cancel_cache');

        $printify = new POD_Printify_Platform();
        $result = $printify->cancel_cache_update();
        
        if ($result) {
            wp_send_json_success('Cache update cancelled');
        } else {
            wp_send_json_error('Failed to cancel cache update');
        }
        
        wp_die();
    }

    /**
     * Get cache update status
     */
    public function get_cache_status() {
        $this->verify_request('pod_get_cache_status');

        $printify = new POD_Printify_Platform();
        $progress = $printify->get_cache_progress();
        $is_updating = $printify->is_cache_updating();
        
        wp_send_json_success(array(
            'is_updating' => $is_updating,
            'progress' => $progress
        ));
        
        wp_die();
    }

    /**
     * Search products
     */
    public function search_products() {
        $this->verify_request('pod_search_products');

        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        $filters = isset($_POST['filters']) ? $_POST['filters'] : array();
        
        // Sanitize filters
        $sanitized_filters = array();
        foreach ($filters as $key => $value) {
            $sanitized_filters[sanitize_key($key)] = sanitize_text_field($value);
        }

        $printify = new POD_Printify_Platform();
        $results = $printify->search_products($query, $sanitized_filters);
        
        if (is_wp_error($results)) {
            wp_send_json_error($results->get_error_message());
        } else {
            wp_send_json_success($results);
        }
        
        wp_die();
    }

    /**
     * Upload image to Printify
     */
    public function upload_image() {
        $this->verify_request('pod_upload_image');

        if (!isset($_FILES['image'])) {
            wp_send_json_error('No image uploaded');
            wp_die();
        }

        $printify = new POD_Printify_Platform();
        $result = $printify->upload_image($_FILES['image']);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
        
        wp_die();
    }

    /**
     * Create a new product
     */
    public function create_product() {
        $this->verify_request('pod_create_product');

        if (!isset($_POST['product_data'])) {
            wp_send_json_error('No product data provided');
            wp_die();
        }

        $product_data = json_decode(stripslashes($_POST['product_data']), true);
        if (!$product_data) {
            wp_send_json_error('Invalid product data format');
            wp_die();
        }

        $printify = new POD_Printify_Platform();
        $result = $printify->create_product($product_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
        
        wp_die();
    }
}
