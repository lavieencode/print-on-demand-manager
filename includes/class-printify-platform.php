<?php
/**
 * Handles all interactions with the Printify API
 */
class POD_Printify_Platform {
    private $api_key;
    private $api_base_url = 'https://api.printify.com/v1/';
    private $shop_id;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_key = get_option('pod_printify_api_key');
    }

    /**
     * Make an API request to Printify
     */
    private function make_request($endpoint, $method = 'GET', $body = null) {
        $url = $this->api_base_url . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            )
        );

        if ($body) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            error_log('POD Manager: API request failed: ' . $response->get_error_message());
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) >= 400) {
            error_log('POD Manager: API error: ' . $body);
            return new WP_Error('api_error', isset($data['message']) ? $data['message'] : 'API request failed');
        }

        return $data;
    }

    /**
     * Search products in cache with filters
     */
    public function search_products($query = '', $filters = array()) {
        $cache_key = 'pod_printify_search_' . md5($query . serialize($filters));
        $is_cached = get_transient($cache_key) !== false;
        
        if ($is_cached) {
            $cached_results = get_transient($cache_key);
            if ($cached_results !== false) {
                return $cached_results;
            }
        }

        // Get all products from cache
        $all_products = $this->get_cached_products();
        if (is_wp_error($all_products)) {
            return $all_products;
        }

        // Filter products
        $filtered_products = array();
        foreach ($all_products as $product) {
            $matches = true;

            // Apply text search
            if (!empty($query)) {
                $search_in = array(
                    $product['title'],
                    $product['description'],
                    implode(' ', $product['tags'])
                );
                $haystack = strtolower(implode(' ', $search_in));
                $matches = strpos($haystack, strtolower($query)) !== false;
            }

            // Apply filters
            if ($matches && !empty($filters)) {
                foreach ($filters as $key => $value) {
                    switch ($key) {
                        case 'provider':
                            if (!empty($value) && $product['print_provider']['id'] != $value) {
                                $matches = false;
                            }
                            break;
                        case 'category':
                            if (!empty($value) && !in_array($value, $product['tags'])) {
                                $matches = false;
                            }
                            break;
                    }
                }
            }

            if ($matches) {
                $filtered_products[] = $product;
            }
        }

        // Cache results for 5 minutes
        set_transient($cache_key, $filtered_products, 5 * MINUTE_IN_SECONDS);

        return $filtered_products;
    }

    /**
     * Get cached products
     */
    public function get_cached_products() {
        $cache = get_option('pod_printify_products_cache');
        $cache_time = get_option('pod_printify_products_cache_time');
        
        // Check if cache is valid (less than 24 hours old)
        if ($cache && $cache_time && (time() - $cache_time < 24 * HOUR_IN_SECONDS)) {
            return $cache;
        }
        
        // Get fresh data
        $products = $this->get_all_catalog_products();
        if (!is_wp_error($products)) {
            update_option('pod_printify_products_cache', $products);
            update_option('pod_printify_products_cache_time', time());
        }
        
        return $products;
    }

    /**
     * Get all products from Printify catalog
     */
    public function get_all_catalog_products() {
        return $this->make_request('catalog/blueprints');
    }

    /**
     * Get blueprint details
     */
    public function get_blueprint($blueprint_id) {
        return $this->make_request("catalog/blueprints/{$blueprint_id}");
    }

    /**
     * Create a new product
     */
    public function create_product($data) {
        $shop_id = get_option('pod_printify_shop_id');
        if (!$shop_id) {
            return new WP_Error('no_shop_id', 'Shop ID is not set');
        }

        return $this->make_request("shops/{$shop_id}/products.json", 'POST', $data);
    }

    /**
     * Upload an image to Printify
     */
    public function upload_image($file) {
        if (!file_exists($file['tmp_name'])) {
            return new WP_Error('no_file', 'No file was uploaded');
        }

        // Get file contents and encode
        $image_data = base64_encode(file_get_contents($file['tmp_name']));

        $data = array(
            'file_name' => $file['name'],
            'contents' => $image_data
        );

        return $this->make_request('uploads/images.json', 'POST', $data);
    }

    /**
     * Check if cache update is in progress
     */
    public function is_cache_updating() {
        $updating = get_option('pod_printify_cache_updating', false);
        if (!$updating) {
            return false;
        }

        // Check for stale process (over 5 minutes without updates)
        $progress = $this->get_cache_progress();
        if (!empty($progress['last_update'])) {
            $last_update = strtotime($progress['last_update']);
            if (time() - $last_update > 300) { // 5 minutes
                error_log('POD Manager: Found stale cache update process, cleaning up');
                $this->cancel_cache_update();
                return false;
            }
        }

        // Check if status is cancelled
        if (!empty($progress['status']) && $progress['status'] === 'cancelled') {
            $this->cancel_cache_update();
            return false;
        }

        return true;
    }

    /**
     * Get cache update progress
     */
    public function get_cache_progress() {
        return get_option('pod_printify_cache_progress', array(
            'total' => 0,
            'current' => 0,
            'status' => 'idle',
            'last_update' => current_time('mysql')
        ));
    }

    /**
     * Cancel ongoing cache update
     */
    public function cancel_cache_update() {
        try {
            // Set status to cancelled
            $progress = $this->get_cache_progress();
            $progress['status'] = 'cancelled';
            update_option('pod_printify_cache_progress', $progress);
            
            // Force cleanup of all cache-related flags
            delete_option('pod_printify_cache_updating');
            delete_option('pod_printify_all_products_detailed_temp');
            delete_transient('pod_printify_all_products_detailed');
            
            error_log('POD Manager: Cache update cancelled and cleaned up');
            return true;
        } catch (Exception $e) {
            error_log('POD Manager: Error in cancel_cache_update: ' . $e->getMessage());
            // Even if there's an error, try to force cleanup
            delete_option('pod_printify_cache_updating');
            delete_option('pod_printify_all_products_detailed_temp');
            delete_transient('pod_printify_all_products_detailed');
            return false;
        }
    }

    /**
     * Cache all products with detailed information
     */
    public function cache_all_products_detailed() {
        try {
            // Set cache updating flag
            update_option('pod_printify_cache_updating', true);
            
            // Initialize progress
            $progress = array(
                'total' => 0,
                'current' => 0,
                'status' => 'starting',
                'last_update' => current_time('mysql')
            );
            update_option('pod_printify_cache_progress', $progress);
            
            // Get all products first
            $products = $this->get_all_catalog_products();
            if (is_wp_error($products)) {
                throw new Exception($products->get_error_message());
            }
            
            // Update progress
            $progress['total'] = count($products);
            $progress['status'] = 'processing';
            update_option('pod_printify_cache_progress', $progress);
            
            // Get existing cache or start fresh
            $cache_key = 'pod_printify_all_products_detailed';
            $temp_cache_key = $cache_key . '_temp';
            $cached_products = get_option($cache_key, array());
            
            // Store in temporary cache while processing
            update_option($temp_cache_key, $cached_products);
            
            // Process each product
            foreach ($products as $index => $product) {
                // Check if process was cancelled
                $progress = $this->get_cache_progress();
                if ($progress['status'] === 'cancelled') {
                    throw new Exception('Cache update cancelled by user');
                }
                
                // Update progress
                $progress['current'] = $index + 1;
                $progress['last_update'] = current_time('mysql');
                update_option('pod_printify_cache_progress', $progress);
                
                // Get detailed product info
                $temp_cached_products = get_option($temp_cache_key, array());
                
                if (!isset($temp_cached_products[$product['id']])) {
                    try {
                        // Update progress for current item
                        $progress['status'] = 'fetching_' . $product['id'];
                        update_option('pod_printify_cache_progress', $progress);
                        
                        $detailed = $this->get_blueprint($product['id']);
                        if (!is_wp_error($detailed)) {
                            $temp_cached_products[$product['id']] = array_merge($product, $detailed);
                        }
                    } catch (Exception $e) {
                        error_log('POD Manager: Error caching product ' . $product['id'] . ': ' . $e->getMessage());
                    }
                    
                    // Save progress periodically
                    update_option($temp_cache_key, $temp_cached_products);
                }
                
                // Update progress
                $progress['status'] = 'processed_' . $product['id'];
                update_option('pod_printify_cache_progress', $progress);
            }
            
            // Save final cache
            update_option($cache_key, $temp_cached_products);
            update_option('pod_printify_last_cache_update', current_time('mysql'));
            
            // Update final progress
            $progress['status'] = 'completed';
            $progress['last_update'] = current_time('mysql');
            update_option('pod_printify_cache_progress', $progress);
            
            // Cleanup
            delete_option('pod_printify_cache_updating');
            delete_option($temp_cache_key);
            
            return true;
        } catch (Exception $e) {
            // Update progress to show error
            $progress['status'] = 'error';
            $progress['error'] = $e->getMessage();
            update_option('pod_printify_cache_progress', $progress);
            
            // Cleanup
            delete_option('pod_printify_cache_updating');
            
            return new WP_Error('cache_error', $e->getMessage());
        }
    }

    /**
     * Get API key
     */
    public function get_api_key() {
        return $this->api_key;
    }

    /**
     * Get cached providers
     */
    public function get_cached_providers() {
        $providers = array();
        $products = $this->get_cached_products();
        
        if (!is_wp_error($products)) {
            foreach ($products as $product) {
                if (isset($product['print_provider'])) {
                    $provider = $product['print_provider'];
                    $providers[$provider['id']] = array(
                        'id' => $provider['id'],
                        'title' => $provider['title']
                    );
                }
            }
        }
        
        return $providers;
    }
}
