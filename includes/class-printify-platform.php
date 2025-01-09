<?php
/**
 * Handles all interactions with the Printify API
 */
require_once plugin_dir_path(__FILE__) . 'class-pod-db-manager.php';

class POD_Printify_Platform {
    /**
     * Instance of this class.
     *
     * @var POD_Printify_Platform
     */
    private static $instance = null;

    /**
     * Get an instance of this class.
     *
     * @return self
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
            
            // Load credentials when instance is created
            $api_key = get_option('pod_printify_api_key', '');
            $shop_id = get_option('pod_printify_shop_id', '');
            
            if (!empty($api_key)) {
                self::$instance->set_api_key($api_key);
            }
            
            if (!empty($shop_id)) {
                self::$instance->set_shop_id($shop_id);
            }
        }
        return self::$instance;
    }

    /**
     * API key for Printify API
     *
     * @var string
     */
    private $api_key = '';

    /**
     * Shop ID for Printify API
     * 
     * @var string
     */
    private $shop_id = '';

    /**
     * API base URL
     * 
     * @var string
     */
    private $api_base_url = 'https://api.printify.com/v1/';

    /**
     * Rate limiting properties
     */
    private $last_request_time = 0;
    private $min_request_interval = 1; // Minimum time between requests in seconds

    /**
     * Safety settings
     */
    private $max_chunk_time = 120;   // Maximum seconds per chunk
    private $max_memory = 256;       // Maximum MB of memory
    private $rate_limit = 500;       // Maximum API requests per minute (Printify allows 600)
    private $chunk_size = 20;        // Items to process per chunk
    private $max_api_calls = 500;    // Maximum API calls per minute

    /**
     * Request timeouts
     */
    private $request_timeouts = array(
        'default' => 60,
        'variants' => 120,  // Longer timeout for variant requests
        'blueprints' => 90, // Medium timeout for blueprint requests
    );

    /**
     * Retry delays
     */
    private $retry_delays = array(
        'timeout' => 180,   // 3 minutes for timeout errors
        'default' => 60     // 1 minute for other errors
    );

    /**
     * Constructor
     */
    public function __construct() {
        error_log('POD Manager: Initializing Printify Platform');
        
        // Load credentials from WordPress options
        $this->api_key = get_option('pod_printify_api_key', '');
        $this->shop_id = get_option('pod_printify_shop_id', '');
        
        error_log('POD Manager: API Key exists: ' . (!empty($this->api_key) ? 'yes (' . strlen($this->api_key) . ' chars)' : 'no'));
        error_log('POD Manager: Shop ID exists: ' . (!empty($this->shop_id) ? 'yes (' . $this->shop_id . ')' : 'no'));
    }

    /**
     * Validate API key format
     *
     * @return bool|WP_Error
     */
    private function validate_api_key(): bool|WP_Error {
        if (empty($this->api_key)) {
            error_log('POD Manager: API key is empty');
            return new WP_Error('no_api_key', 'API key is required');
        }

        // Check minimum length for JWT token
        if (strlen($this->api_key) < 100) {
            error_log('POD Manager: API key is too short for a JWT token');
            return new WP_Error('invalid_api_key', 'API key appears to be invalid');
        }

        // Basic JWT format check (three parts separated by dots)
        $parts = explode('.', $this->api_key);
        if (count($parts) !== 3) {
            error_log('POD Manager: API key is not in valid JWT format');
            return new WP_Error('invalid_api_key', 'API key is not in valid format');
        }

        return true;
    }

    /**
     * Make an API request to Printify
     *
     * @param string $endpoint The API endpoint
     * @param string $method HTTP method (GET, POST, etc)
     * @param array|null $body Request body data
     * @param int|null $timeout Optional timeout override in seconds
     * @return array|WP_Error
     */
    private function make_request(string $endpoint, string $method = 'GET', ?array $body = null, ?int $timeout = null): array|WP_Error {
        error_log("POD Manager: Making API request to endpoint: {$endpoint}");
        
        // Track API call before making request
        $this->track_api_call();

        // Check if we're being cancelled during a cache update
        if (get_option('pod_printify_cache_updating', false)) {
            // Update last activity time
            update_option('pod_printify_last_activity', time());
            
            // Check for emergency stop
            if (get_transient('pod_printify_emergency_stop')) {
                error_log('POD Manager: Emergency stop detected, aborting all operations');
                $this->cancel_cache_update();
                return new WP_Error('emergency_stop', 'Emergency stop activated');
            }

            // Check if we're in a long-running process
            $start_time = get_option('pod_printify_cache_start_time');
            if ($start_time) {
                $elapsed_time = time() - $start_time;
                if ($elapsed_time > $this->max_chunk_time) {
                    error_log('POD Manager: Process running too long, forcing stop');
                    $this->cancel_cache_update();
                    return new WP_Error('timeout', 'Process timeout');
                }
            }

            // Check for force stop
            if (get_option('pod_printify_force_stop', false)) {
                error_log('POD Manager: Force stop detected, aborting API request');
                return new WP_Error('force_stop', 'Operation was force stopped');
            }
        }

        // Rate limiting
        $current_time = time();
        $time_since_last_request = $current_time - $this->last_request_time;
        if ($time_since_last_request < $this->min_request_interval) {
            usleep($this->min_request_interval * 1000000); // Convert to microseconds
        }
        $this->last_request_time = time();

        // Determine appropriate timeout based on endpoint
        if ($timeout === null) {
            if (strpos($endpoint, '/variants') !== false) {
                $timeout = $this->request_timeouts['variants'];
            } elseif (strpos($endpoint, '/blueprints') !== false) {
                $timeout = $this->request_timeouts['blueprints'];
            } else {
                $timeout = $this->request_timeouts['default'];
            }
        }

        error_log("POD Manager: Sending request to {$endpoint} with {$timeout}s timeout");

        // Prepare the request
        $url = $this->api_base_url . ltrim($endpoint, '/');
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => $timeout,
            'sslverify' => true
        );

        if ($body !== null) {
            $args['body'] = json_encode($body);
        }

        // Make the request
        $response = wp_remote_request($url, $args);

        // Check for errors
        if (is_wp_error($response)) {
            error_log('POD Manager: API request failed: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log("POD Manager: API response received - Status: {$status_code}");
        
        if ($status_code < 200 || $status_code >= 300) {
            error_log("POD Manager: API error response - Code: {$status_code}, Body: {$body}");
            return new WP_Error('api_error', "API request failed with status {$status_code}: {$body}");
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('POD Manager: Failed to parse API response: ' . json_last_error_msg());
            return new WP_Error('json_parse_error', 'Failed to parse API response');
        }

        error_log('POD Manager: API request completed successfully');
        return $data;
    }

    /**
     * Get shipping information for a product
     *
     * @param string $product_id Product ID
     * @param string $provider_id Provider ID
     * @return array|WP_Error
     */
    private function get_shipping_info(string $product_id, string $provider_id): array|WP_Error {
        return $this->make_request("catalog/blueprints/{$product_id}/print_providers/{$provider_id}/shipping.json");
    }

    /**
     * Get variant information for a product
     *
     * @param string $product_id Product ID
     * @param string $provider_id Provider ID
     * @return array|WP_Error
     */
    private function get_variant_pricing(string $product_id, string $provider_id): array|WP_Error {
        return $this->make_request("catalog/blueprints/{$product_id}/print_providers/{$provider_id}/variants.json");
    }

    /**
     * Get print providers for a product
     *
     * @param string $product_id Product ID
     * @return array|WP_Error
     */
    private function get_print_providers(string $product_id): array|WP_Error {
        // Use a longer timeout for provider requests since they can be large
        return $this->make_request("catalog/blueprints/{$product_id}/print_providers.json", 'GET', null, 180);
    }

    /**
     * Get all blueprints
     *
     * @return array|WP_Error
     */
    private function get_blueprints(): array|WP_Error {
        return $this->make_request('catalog/blueprints.json');
    }

    /**
     * Get blueprint details
     *
     * @param string $blueprint_id Blueprint ID
     * @return array|WP_Error
     */
    private function get_blueprint(string $blueprint_id): array|WP_Error {
        return $this->make_request("catalog/blueprints/{$blueprint_id}.json");
    }

    /**
     * Verify API connection and get shop count
     *
     * @return array|WP_Error
     */
    public function verify_connection(): array|WP_Error {
        if (empty($this->api_key)) {
            error_log('POD Manager: No API key provided for verification');
            return new WP_Error('no_api_key', 'API key is required');
        }

        $response = $this->make_request('shops.json');

        if (is_wp_error($response)) {
            error_log('POD Manager: API verification failed: ' . $response->get_error_message());
            return $response;
        }

        if (!is_array($response)) {
            error_log('POD Manager: Invalid response format from shops API');
            return new WP_Error('invalid_response', 'Invalid response from API');
        }

        if (empty($response)) {
            error_log('POD Manager: No shops found for this API key');
            return new WP_Error('no_shops', 'No shops found for this API key');
        }

        // For now, use the first shop if multiple exist
        $first_shop = reset($response);
        if (!isset($first_shop['id'])) {
            error_log('POD Manager: Shop data missing ID field');
            return new WP_Error('invalid_shop_data', 'Shop data is missing required fields');
        }

        // Save the shop ID
        update_option('pod_printify_shop_id', $first_shop['id']);
        error_log('POD Manager: Saved shop ID: ' . $first_shop['id']);

        // Return success with shop data
        return array(
            'shop_count' => count($response),
            'shops' => $response,
            'selected_shop' => $first_shop
        );
    }

    /**
     * Set API key and validate it
     *
     * @param string $api_key API key to set
     * @return array|WP_Error
     */
    public function set_api_key(string $api_key): array|WP_Error {
        error_log('POD Manager: Setting new API key');
        
        if (empty($api_key)) {
            error_log('POD Manager: Empty API key provided');
            return new WP_Error('invalid_api_key', 'API key cannot be empty');
        }

        // Validate the API key format
        $this->api_key = $api_key;
        $validation = $this->validate_api_key();
        if (is_wp_error($validation)) {
            error_log('POD Manager: API key validation failed: ' . $validation->get_error_message());
            return $validation;
        }

        // Try to verify the connection and get shop data
        $verification = $this->verify_connection();
        if (is_wp_error($verification)) {
            error_log('POD Manager: Connection verification failed: ' . $verification->get_error_message());
            return $verification;
        }

        // Save the API key only after successful verification
        update_option('pod_printify_api_key', $api_key);
        error_log('POD Manager: API key saved successfully');

        return $verification;
    }

    /**
     * Set shop ID
     *
     * @param string $shop_id Shop ID to set
     * @return void
     */
    public function set_shop_id(string $shop_id): void {
        $this->shop_id = $shop_id;
        update_option('pod_printify_shop_id', $shop_id);
    }

    /**
     * Get API key
     *
     * @return string
     */
    public function get_api_key(): string {
        return $this->api_key;
    }

    /**
     * Get shop ID
     *
     * @return string
     */
    public function get_shop_id(): string {
        return $this->shop_id;
    }

    /**
     * Check if API key is set
     *
     * @return bool
     */
    public function has_api_key(): bool {
        return !empty($this->api_key);
    }

    /**
     * Check if shop ID is set
     *
     * @return bool
     */
    public function has_shop_id(): bool {
        return !empty($this->shop_id);
    }

    /**
     * Get cached providers with optional auto-refresh
     *
     * @param bool $auto_refresh Whether to auto refresh the cache
     * @return array
     */
    public function get_cached_providers(bool $auto_refresh = false): array {
        $cache = get_option('pod_printify_all_products_detailed', array());
        
        // Only auto-refresh if specified and cache is empty or old
        if ($auto_refresh) {
            $last_update = get_option('pod_printify_last_cache_update');
            if (empty($cache) || empty($last_update) || strtotime($last_update) < strtotime('-24 hours')) {
                $this->cache_all_products_detailed();
                $cache = get_option('pod_printify_all_products_detailed', array());
            }
        }
        
        return $cache;
    }

    /**
     * Update all products cache
     * 
     * Instead of running the entire update in one process, we'll split it into smaller chunks
     * and use WordPress's built-in cron system to handle them safely
     *
     * @return bool|WP_Error
     */
    public function update_all_products_cache(): bool|WP_Error {
        try {
            // Check if we're already updating
            if ($this->is_cache_updating()) {
                error_log('POD Manager: Cache update already in progress');
                return new WP_Error('already_updating', 'Cache update already in progress');
            }

            $db = $this->get_db_manager();

            error_log('POD Manager: Checking database tables');
            
            // Only create tables if they don't exist
            $tables_check = $db->tables_exist();
            if ($tables_check !== true) {
                error_log('POD Manager: Some tables missing, creating them: ' . implode(', ', $tables_check));
                $db->create_tables();
                
                // Verify tables were created
                $tables_check = $db->tables_exist();
                if ($tables_check !== true) {
                    $missing = implode(', ', $tables_check);
                    throw new Exception("Failed to create required tables: $missing");
                }
                error_log('POD Manager: Tables created successfully');
            } else {
                error_log('POD Manager: All required tables exist');
            }

            // Get all blueprints first
            $blueprints = $this->get_blueprints();
            if (is_wp_error($blueprints)) {
                throw new Exception('Failed to get blueprints: ' . $blueprints->get_error_message());
            }

            // Clear any existing cache update flags
            delete_option('pod_printify_cache_updating');
            delete_option('pod_printify_cache_progress');
            delete_option('pod_printify_cache_current_blueprint');
            delete_option('pod_printify_last_cache_update');
            delete_option('pod_printify_cache_start_time');
            delete_option('pod_printify_last_activity');
            delete_option('pod_printify_force_stop');
            delete_transient('pod_printify_emergency_stop');

            // Initialize the update process
            update_option('pod_printify_cache_blueprints', $blueprints);
            update_option('pod_printify_cache_total_blueprints', count($blueprints));
            update_option('pod_printify_cache_current_blueprint', 0);
            update_option('pod_printify_cache_updating', true);
            update_option('pod_printify_last_cache_update', current_time('mysql'));
            update_option('pod_printify_cache_start_time', time());
            update_option('pod_printify_last_activity', time());

            // Initialize progress tracking
            $this->set_running_process();

            // Initialize progress tracking
            $progress = array(
                'status' => 'running',
                'phase' => 'initialization',
                'current_item' => 'Starting cache update',
                'current' => 0,
                'total' => count($blueprints),
                'percentage' => 0,
                'last_update' => current_time('mysql')
            );
            update_option('pod_printify_cache_progress', $progress);

            // Schedule the first chunk
            wp_schedule_single_event(time(), 'pod_printify_process_cache_chunk');
            
            error_log('POD Manager: Cache update started');
            return true;
        } catch (Exception $e) {
            error_log('POD Manager: Failed to start cache update: ' . $e->getMessage());
            $this->cancel_cache_update();
            return new WP_Error('update_failed', 'Failed to start cache update: ' . $e->getMessage());
        }
    }

    /**
     * Process a chunk of cache updates
     * 
     * @return void
     */
    public function process_cache_chunk() {
        if (!$this->is_cache_updating()) {
            error_log('POD Manager: Cannot process chunk - cache update not in progress');
            return;
        }

        $this->update_last_activity();
        
        $current_blueprint = (int)get_option('pod_printify_cache_current_blueprint', 0);
        $total_blueprints = (int)get_option('pod_printify_cache_total_blueprints', 0);
        $chunk_size = 5; // Process 5 blueprints at a time
        $start_time = microtime(true);
        $timeout = 20; // 20 second timeout per chunk
        
        error_log("POD Manager: Starting cache chunk processing - Current: {$current_blueprint}, Total: {$total_blueprints}");
        
        try {
            // First ensure we have a process ID
            if (!$this->get_running_process()) {
                $this->set_running_process();
                error_log('POD Manager: Set process ID for background processing');
            }

            $blueprints = get_option('pod_printify_cache_blueprints', array());
            if (empty($blueprints)) {
                error_log('POD Manager: No cached blueprints found, fetching from API');
                $blueprints = $this->get_blueprints();
                if (is_wp_error($blueprints)) {
                    throw new Exception('Failed to get blueprints: ' . $blueprints->get_error_message());
                }
                
                if (empty($blueprints)) {
                    error_log('POD Manager: No blueprints found in API response');
                    $this->complete_cache_update();
                    return;
                }

                // Store blueprints for future chunks
                update_option('pod_printify_cache_blueprints', $blueprints);
                error_log('POD Manager: Retrieved and stored ' . count($blueprints) . ' blueprints from API');
            }

            // Store total count first time
            if ($total_blueprints === 0) {
                $total_blueprints = count($blueprints);
                update_option('pod_printify_cache_total_blueprints', $total_blueprints);
            }

            $processed = 0;
            while ($processed < $chunk_size && $current_blueprint < $total_blueprints) {
                if ((microtime(true) - $start_time) > $timeout) {
                    error_log('POD Manager: Chunk timeout reached');
                    break;
                }

                $blueprint = $blueprints[$current_blueprint];
                $this->process_single_blueprint($blueprint);
                $this->update_last_activity();
                
                $current_blueprint++;
                $processed++;
            }

            // Update progress
            update_option('pod_printify_cache_current_blueprint', $current_blueprint);
            
            // Check if we're done
            if ($current_blueprint >= $total_blueprints) {
                $this->complete_cache_update();
            }
            
            // Schedule the next chunk
            if ($current_blueprint < $total_blueprints) {
                error_log('POD Manager: Scheduling next chunk');
                wp_schedule_single_event(time(), 'pod_printify_process_cache_chunk');
            }
            
        } catch (Exception $e) {
            error_log('POD Manager: Error processing cache chunk: ' . $e->getMessage());
            error_log('POD Manager: Stack trace: ' . $e->getTraceAsString());
            
            // Update progress with error
            $progress = get_option('pod_printify_cache_progress', array());
            $progress['status'] = 'error';
            $progress['error'] = $e->getMessage();
            update_option('pod_printify_cache_progress', $progress);
            
            // Determine retry delay based on error type
            $delay = $this->retry_delays['default'];
            if (strpos($e->getMessage(), 'timeout') !== false || 
                strpos($e->getMessage(), 'SSL connection') !== false) {
                $delay = $this->retry_delays['timeout'];
                error_log("POD Manager: Timeout detected, using longer retry delay of {$delay}s");
            }
            
            // Schedule retry after delay
            wp_schedule_single_event(time() + $delay, 'pod_printify_process_cache_chunk');
        }
    }

    /**
     * Process a single blueprint
     *
     * @param array $blueprint Blueprint data
     * @return void
     */
    private function process_single_blueprint(array $blueprint) {
        // Store blueprint
        $result = $this->store_blueprint($blueprint);
        if (is_wp_error($result)) {
            throw new Exception('Failed to store blueprint: ' . $result->get_error_message());
        }

        // Get and store providers
        $providers = $this->get_print_providers($blueprint['id']);
        if (is_wp_error($providers)) {
            throw new Exception('Failed to get providers: ' . $providers->get_error_message());
        }

        foreach ($providers as $provider) {
            $result = $this->store_provider($provider, $blueprint['id']);
            if (is_wp_error($result)) {
                throw new Exception('Failed to store provider: ' . $result->get_error_message());
            }

            // Get and store variants
            $variants_response = $this->get_variant_pricing($blueprint['id'], $provider['id']);
            if (is_wp_error($variants_response)) {
                throw new Exception('Failed to get variants: ' . $variants_response->get_error_message());
            }

            // Ensure we have the variants array
            if (!is_array($variants_response) || !isset($variants_response['variants'])) {
                error_log('POD Manager: Invalid variants response format: ' . print_r($variants_response, true));
                continue;
            }

            foreach ($variants_response['variants'] as $variant) {
                if (!is_array($variant)) {
                    error_log('POD Manager: Invalid variant data structure: ' . print_r($variant, true));
                    continue;
                }

                $result = $this->store_variant($variant, $blueprint['id'], $provider['id']);
                if (is_wp_error($result)) {
                    error_log('POD Manager: Failed to store variant: ' . $result->get_error_message());
                    continue;
                }
            }
        }
    }

    /**
     * Store blueprint in database
     *
     * @param array $blueprint Blueprint data to store
     * @return bool|WP_Error
     */
    private function store_blueprint(array $blueprint): bool|WP_Error {
        try {
            $db = $this->get_db_manager();
            
            error_log("POD Manager: Storing blueprint {$blueprint['id']}");
            
            $data = array(
                'blueprint_id' => $blueprint['id'],
                'title' => $blueprint['title'],
                'data' => json_encode($blueprint),
                'last_updated' => current_time('mysql')
            );
            
            $result = $db->insert_data('pod_printify_blueprints', $data);
            
            if ($result === false) {
                throw new Exception('Failed to store blueprint in database');
            }
            
            return true;
        } catch (Exception $e) {
            error_log('POD Manager: Error storing blueprint: ' . $e->getMessage());
            return new WP_Error('store_failed', 'Failed to store blueprint: ' . $e->getMessage());
        }
    }

    /**
     * Store provider in database
     *
     * @param array $provider Provider data to store
     * @param string $blueprint_id Associated blueprint ID
     * @return bool|WP_Error
     */
    private function store_provider(array $provider, string $blueprint_id): bool|WP_Error {
        try {
            $db = $this->get_db_manager();
            
            error_log("POD Manager: Storing provider {$provider['id']} for blueprint {$blueprint_id}");
            
            $data = array(
                'provider_id' => $provider['id'],
                'blueprint_id' => $blueprint_id,
                'title' => $provider['title'],
                'data' => json_encode($provider),
                'last_updated' => current_time('mysql')
            );
            
            $result = $db->insert_data('pod_printify_providers', $data);
            
            if ($result === false) {
                throw new Exception('Failed to store provider in database');
            }
            
            return true;
        } catch (Exception $e) {
            error_log('POD Manager: Error storing provider: ' . $e->getMessage());
            return new WP_Error('store_failed', 'Failed to store provider: ' . $e->getMessage());
        }
    }

    /**
     * Store variant in database
     *
     * @param array $variant Variant data to store
     * @param string $blueprint_id Associated blueprint ID
     * @param string $provider_id Associated provider ID
     * @return bool|WP_Error
     */
    private function store_variant(array $variant, string $blueprint_id, string $provider_id): bool|WP_Error {
        try {
            $db = $this->get_db_manager();
            
            error_log("POD Manager: Storing variant {$variant['id']} for blueprint {$blueprint_id} and provider {$provider_id}");
            
            $data = array(
                'variant_id' => $variant['id'],
                'blueprint_id' => $blueprint_id,
                'provider_id' => $provider_id,
                'data' => json_encode($variant),
                'last_updated' => current_time('mysql')
            );
            
            $result = $db->insert_data('pod_printify_variants', $data);
            
            if ($result === false) {
                throw new Exception('Failed to store variant in database');
            }
            
            return true;
        } catch (Exception $e) {
            error_log('POD Manager: Error storing variant: ' . $e->getMessage());
            return new WP_Error('store_failed', 'Failed to store variant: ' . $e->getMessage());
        }
    }

    /**
     * Get the database manager instance
     *
     * @return POD_DB_Manager
     */
    private function get_db_manager(): POD_DB_Manager {
        return POD_DB_Manager::get_instance();
    }

    /**
     * Check if the system is healthy enough to continue processing
     *
     * @return bool
     */
    private function is_healthy(): bool {
        // Check memory usage
        $memory_usage = memory_get_usage(true) / 1024 / 1024;
        if ($memory_usage > $this->max_memory) {
            error_log("POD Manager: Memory usage too high: {$memory_usage}MB");
            return false;
        }

        // Check rate limit with a buffer
        $key = 'pod_printify_api_calls';
        $calls = get_transient($key) ?: 0;
        if ($calls >= $this->rate_limit) {
            error_log("POD Manager: Rate limit approaching ({$calls} calls), pausing for 10 seconds");
            sleep(10); // Brief pause to let the rate limit window slide
            delete_transient($key); // Reset the counter
            return true; // Continue after waiting
        }

        return true;
    }

    /**
     * Increment the API call counter
     *
     * @return void
     */
    private function track_api_call(): void {
        $key = 'pod_printify_api_calls';
        $calls = get_transient($key) ?: 0;
        set_transient($key, $calls + 1, 60);
    }

    /**
     * Get the currently running cache update process ID
     * 
     * @return int|null Process ID if running, null if not
     */
    private function get_running_process(): ?int {
        $process_id = get_transient('pod_printify_cache_process');
        if (!$process_id) {
            return null;
        }

        // Check if the process actually exists
        if (function_exists('posix_kill')) {
            // On Unix systems, we can check if process exists
            if (!posix_kill($process_id, 0)) {
                error_log("POD Manager: Process {$process_id} no longer exists");
                delete_transient('pod_printify_cache_process');
                return null;
            }
        }

        return intval($process_id);
    }

    /**
     * Set the current cache update process ID
     */
    private function set_running_process(): void {
        $process_id = getmypid();
        if ($process_id) {
            set_transient('pod_printify_cache_process', $process_id, 3600); // 1 hour max
            error_log("POD Manager: Set running process ID to {$process_id}");
        }
    }

    /**
     * Clear the running process ID
     */
    private function clear_running_process(): void {
        delete_transient('pod_printify_cache_process');
        error_log('POD Manager: Cleared running process ID');
    }

    /**
     * Check if cache update is in progress
     *
     * @return bool
     */
    public function is_cache_updating(): bool {
        // First check if there's an actual process running
        $process_id = $this->get_running_process();
        if (!$process_id) {
            // No process running, clean up any stale flags
            if (get_option('pod_printify_cache_updating', false)) {
                error_log('POD Manager: Found stale cache flags with no running process, cleaning up');
                $this->cancel_cache_update();
            }
            return false;
        }

        // Process exists, check if it's still active
        $last_activity = get_option('pod_printify_last_activity', 0);
        $current_time = time();
        
        // If no activity for more than 5 minutes, terminate the process
        if ($current_time - $last_activity > 300) {
            error_log('POD Manager: Process appears stuck, attempting to terminate');
            $this->terminate_cache_update();
            return false;
        }

        return true;
    }

    /**
     * Terminate any running cache update process
     */
    private function terminate_cache_update(): void {
        $process_id = $this->get_running_process();
        if (!$process_id) {
            return;
        }

        error_log("POD Manager: Attempting to terminate process {$process_id}");

        // Try to terminate the process
        if (function_exists('posix_kill')) {
            posix_kill($process_id, SIGTERM);
            sleep(1); // Give it a second to clean up
            
            // If it's still running, force kill
            if (posix_kill($process_id, 0)) {
                error_log("POD Manager: Process {$process_id} still running, force killing");
                posix_kill($process_id, SIGKILL);
            }
        }

        // Clean up our tracking
        $this->clear_running_process();
        $this->cancel_cache_update();
    }

    /**
     * Cancel cache update and clean up
     *
     * @return bool
     */
    public function cancel_cache_update(): bool {
        error_log('POD Manager: Canceling cache update');
        
        // Update progress
        $progress = get_option('pod_printify_cache_progress', array());
        $progress['status'] = 'cancelled';
        $progress['phase'] = 'cancelled';
        $progress['message'] = 'Cache update cancelled';
        $progress['cancelled_at'] = current_time('mysql');
        update_option('pod_printify_cache_progress', $progress);
        
        // Clear all processing flags
        delete_option('pod_printify_cache_updating');
        delete_option('pod_printify_cache_current_blueprint');
        delete_option('pod_printify_cache_blueprints');
        delete_option('pod_printify_cache_start_time');
        delete_option('pod_printify_force_stop');
        delete_transient('pod_printify_emergency_stop');
        
        // Clear process ID
        $this->clear_running_process();
        
        // Clear any scheduled tasks
        wp_clear_scheduled_hook('pod_printify_process_cache_chunk');
        
        error_log('POD Manager: Cache update cancelled successfully');
        return true;
    }

    /**
     * Complete cache update
     *
     * @return void
     */
    private function complete_cache_update(): void {
        error_log('POD Manager: Completing cache update');
        
        // Update progress
        $progress = array(
            'status' => 'complete',
            'phase' => 'completed',
            'current' => get_option('pod_printify_cache_total_blueprints', 0),
            'total' => get_option('pod_printify_cache_total_blueprints', 0),
            'percentage' => 100,
            'message' => 'Cache update completed',
            'completed_at' => current_time('mysql')
        );
        update_option('pod_printify_cache_progress', $progress);
        
        // Clear processing flags
        delete_option('pod_printify_cache_updating');
        delete_option('pod_printify_cache_current_blueprint');
        delete_option('pod_printify_cache_blueprints');
        delete_option('pod_printify_cache_start_time');
        
        // Record completion time
        update_option('pod_printify_last_cache_update', current_time('mysql'));
        update_option('pod_printify_last_activity', time());
        
        // Clear process ID
        $this->clear_running_process();
        
        error_log('POD Manager: Cache update completed successfully');
    }

    /**
     * Get all cached blueprints
     *
     * @return array
     */
    public function get_cached_blueprints(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pod_printify_blueprints';
        
        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY title ASC", ARRAY_A);
        if (!$results) {
            return array();
        }

        return array_map(function($row) {
            return json_decode($row['data'], true);
        }, $results);
    }

    /**
     * Get cached blueprint by ID
     *
     * @param string $blueprint_id Blueprint ID
     * @return array|null
     */
    public function get_cached_blueprint(string $blueprint_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'pod_printify_blueprints';
        
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE blueprint_id = %s",
                $blueprint_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return json_decode($row['data'], true);
    }

    /**
     * Get cached providers for a blueprint
     *
     * @param string $blueprint_id Blueprint ID
     * @return array
     */
    public function get_cached_blueprint_providers(string $blueprint_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pod_printify_providers';
        
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE blueprint_id = %s",
            $blueprint_id
        );
        
        $results = $wpdb->get_results($query);
        if (!$results) {
            return array();
        }
        
        $providers = array();
        foreach ($results as $row) {
            $providers[] = json_decode($row->data, true);
        }
        
        return $providers;
    }

    /**
     * Get cached variants for a blueprint and provider
     *
     * @param string $blueprint_id Blueprint ID
     * @param string $provider_id Provider ID
     * @return array
     */
    public function get_cached_variants(string $blueprint_id, string $provider_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pod_printify_variants';
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE blueprint_id = %s AND provider_id = %s",
                $blueprint_id,
                $provider_id
            ),
            ARRAY_A
        );

        if (!$results) {
            return array();
        }

        return array_map(function($row) {
            return json_decode($row['data'], true);
        }, $results);
    }

    /**
     * Get all cached product details
     * This maintains compatibility with the old format
     *
     * @return array
     */
    public function get_all_cached_products(): array {
        $all_products = array();
        
        // Get all blueprints
        $blueprints = $this->get_cached_blueprints();
        
        foreach ($blueprints as $blueprint) {
            $providers = $this->get_cached_blueprint_providers($blueprint['id']);
            
            foreach ($providers as $provider) {
                $variants = $this->get_cached_variants($blueprint['id'], $provider['id']);
                
                if (!empty($variants)) {
                    $all_products[] = array(
                        'blueprint' => $blueprint,
                        'provider' => $provider,
                        'variants' => $variants
                    );
                }
            }
        }
        
        return $all_products;
    }

    /**
     * Check if the cache update process is stuck
     * 
     * @return bool
     */
    public function is_cache_stuck() {
        $last_update = get_option('pod_printify_cache_last_update', 0);
        $current_time = time();
        
        // If no update time is set, not stuck
        if (empty($last_update)) {
            return false;
        }
        
        // Consider stuck if no activity for 2 minutes
        $timeout = 120; // 2 minutes
        return ($current_time - $last_update) > $timeout;
    }

    /**
     * Update the last activity timestamp for cache updates
     */
    private function update_last_activity() {
        update_option('pod_printify_cache_last_update', time());
    }
}
