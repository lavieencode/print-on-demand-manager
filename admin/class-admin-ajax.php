<?php
/**
 * Handles AJAX requests for the admin interface
 */
class POD_Admin_Ajax {
    /**
     * Initialize AJAX handlers
     */
    public function init() {
        error_log('POD Manager: Initializing AJAX handlers');
        add_action('wp_ajax_pod_verify_connection', array($this, 'verify_connection'));
        add_action('wp_ajax_pod_refresh_cache', array($this, 'refresh_cache'));
        add_action('wp_ajax_pod_cancel_cache', array($this, 'cancel_cache'));
        add_action('wp_ajax_pod_get_cache_status', array($this, 'get_cache_status'));
        error_log('POD Manager: AJAX handlers initialized');
    }

    /**
     * Verify API connection
     */
    public function verify_connection() {
        error_log('POD Manager: Starting verify_connection');
        error_log('POD Manager: POST data: ' . print_r($_POST, true));
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pod_verify_connection')) {
            error_log('POD Manager: Nonce verification failed');
            $this->send_json_response(false, array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('POD Manager: User does not have manage_options capability');
            $this->send_json_response(false, array('message' => 'Insufficient permissions'));
            return;
        }

        $printify = new POD_Printify_Platform();
        $result = $printify->verify_connection();

        if (is_wp_error($result)) {
            error_log('POD Manager: Verification failed: ' . $result->get_error_message());
            $this->send_json_response(false, array('message' => $result->get_error_message()));
            return;
        }

        error_log('POD Manager: Verification successful: ' . print_r($result, true));
        $this->send_json_response(true, $result);
    }

    /**
     * Send JSON response without triggering admin notices
     */
    private function send_json_response($success, $data) {
        // Prevent WordPress from adding admin notices
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        
        // Set headers
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        
        // Send response
        echo json_encode(array(
            'success' => $success,
            'data' => $data
        ));
        
        // End execution
        exit;
    }

    /**
     * Start cache refresh
     */
    public function refresh_cache() {
        error_log('POD Manager: Starting refresh_cache');
        check_ajax_referer('pod_refresh_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            error_log('POD Manager: User does not have manage_options capability');
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $printify = new POD_Printify_Platform();
        $result = $printify->start_cache_update();

        if (is_wp_error($result)) {
            error_log('POD Manager: Cache refresh failed: ' . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
            return;
        }

        error_log('POD Manager: Cache refresh started');
        wp_send_json_success();
    }

    /**
     * Cancel cache update
     */
    public function cancel_cache() {
        error_log('POD Manager: Starting cancel_cache');
        check_ajax_referer('pod_cancel_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            error_log('POD Manager: User does not have manage_options capability');
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $printify = new POD_Printify_Platform();
        $result = $printify->cancel_cache_update();

        if (is_wp_error($result)) {
            error_log('POD Manager: Cache cancel failed: ' . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
            return;
        }

        error_log('POD Manager: Cache cancel successful');
        wp_send_json_success();
    }

    /**
     * Get cache update status
     */
    public function get_cache_status() {
        error_log('POD Manager: Starting get_cache_status');
        check_ajax_referer('pod_get_cache_status', 'nonce');
        
        if (!current_user_can('manage_options')) {
            error_log('POD Manager: User does not have manage_options capability');
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $printify = new POD_Printify_Platform();
        $is_updating = $printify->is_cache_updating();
        $progress = $printify->get_cache_progress();

        error_log('POD Manager: Cache status: ' . print_r($progress, true));
        wp_send_json_success(array(
            'is_updating' => $is_updating,
            'current' => $progress['current'],
            'total' => $progress['total']
        ));
    }
}
