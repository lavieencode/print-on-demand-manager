<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

$printify = new POD_Printify_Platform();
$providers = $printify->get_cached_providers(false); // Add false parameter to prevent auto-refresh

// Add cache refresh button and progress UI
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Quick Add Products</h1>

    <!-- Cache Controls -->
    <div class="pod-cache-controls" id="pod-cache-controls">
        <?php 
        // Create nonces for all AJAX actions
        $nonces = array(
            'refresh_cache' => wp_create_nonce('pod_refresh_cache'),
            'cancel_cache' => wp_create_nonce('pod_cancel_cache'),
            'get_cache_status' => wp_create_nonce('pod_get_cache_status'),
            'debug_cache' => wp_create_nonce('pod_debug_cache')
        );
        ?>
        <script>
            var podNonces = <?php echo json_encode($nonces); ?>;
        </script>
        
        <div class="pod-cache-status">
            <?php
            $last_update = get_option('pod_printify_last_cache_update');
            if ($last_update) {
                echo '<p>Last cache update: ' . human_time_diff(strtotime($last_update), current_time('timestamp')) . ' ago</p>';
            }
            ?>
        </div>
        <button type="button" class="button button-primary pod-refresh-cache">
            <span class="dashicons dashicons-update"></span> Update Cache Now
        </button>
        <button type="button" class="button pod-debug-cache">
            <span class="dashicons dashicons-code-standards"></span> View Cache Data
        </button>
    </div>

    <!-- Cache Progress -->
    <div id="pod-cache-progress" class="pod-cache-progress" style="display: none;">
        <div class="pod-progress-bar">
            <div class="pod-progress-fill"></div>
        </div>
        <div class="pod-progress-info">
            <span class="pod-progress-percentage">0%</span>
            <span class="pod-phase-label"></span>
            <span class="pod-phase-item"></span>
            <span class="pod-progress-numbers"></span>
        </div>
        <button type="button" class="button pod-cancel-cache" <?php echo wp_create_nonce('pod_cancel_cache'); ?>>
            Cancel Update
        </button>
    </div>

    <div class="pod-quick-add-container">
        <!-- Search Form -->
        <div class="pod-search-form">
            <div class="pod-search-input">
                <input type="text" id="pod-search-query" placeholder="Search products...">
            </div>

            <div class="pod-search-filters">
                <select id="pod-provider-filter">
                    <option value="">All Providers</option>
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?php echo esc_attr($provider['id']); ?>">
                            <?php echo esc_html($provider['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="pod-category-filter">
                    <option value="">All Categories</option>
                    <option value="t-shirts">T-Shirts</option>
                    <option value="hoodies">Hoodies</option>
                    <option value="mugs">Mugs</option>
                    <option value="posters">Posters</option>
                </select>
            </div>

            <button type="button" class="button button-primary pod-search-button">Search</button>
        </div>

        <!-- Results Container -->
        <div class="pod-search-results">
            <div class="pod-results-grid"></div>
            <div class="pod-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <span>Loading...</span>
            </div>
            <div class="pod-no-results" style="display: none;">
                <p>No products found. Try adjusting your search criteria.</p>
            </div>
        </div>

        <!-- Product Designer Modal -->
        <div id="pod-designer-modal" class="pod-modal" style="display: none;">
            <div class="pod-modal-content">
                <span class="pod-modal-close">&times;</span>
                <h2>Design Product</h2>

                <div class="pod-designer-container">
                    <!-- Product Preview -->
                    <div class="pod-preview">
                        <div class="pod-preview-image">
                            <img src="" alt="Product Preview" id="pod-preview-img">
                        </div>
                        <div class="pod-preview-controls">
                            <button type="button" class="button pod-rotate-left">↶</button>
                            <button type="button" class="button pod-zoom-out">-</button>
                            <button type="button" class="button pod-zoom-in">+</button>
                            <button type="button" class="button pod-rotate-right">↷</button>
                        </div>
                    </div>

                    <!-- Design Controls -->
                    <div class="pod-controls">
                        <div class="pod-control-group">
                            <label>Upload Design</label>
                            <input type="file" id="pod-design-upload" accept="image/*">
                        </div>

                        <div class="pod-control-group">
                            <label>Position</label>
                            <div class="pod-position-controls">
                                <button type="button" class="button pod-position" data-position="top">Top</button>
                                <button type="button" class="button pod-position" data-position="center">Center</button>
                                <button type="button" class="button pod-position" data-position="bottom">Bottom</button>
                            </div>
                        </div>

                        <div class="pod-control-group">
                            <label>Size</label>
                            <input type="range" id="pod-design-size" min="10" max="100" value="50">
                        </div>

                        <div class="pod-control-group">
                            <label>Product Title</label>
                            <input type="text" id="pod-product-title" placeholder="Enter product title">
                        </div>

                        <div class="pod-control-group">
                            <label>Description</label>
                            <textarea id="pod-product-description" placeholder="Enter product description"></textarea>
                        </div>

                        <div class="pod-control-group">
                            <button type="button" class="button button-primary pod-create-product">Create Product</button>
                            <div class="pod-create-status" style="display: none;">
                                <span class="spinner is-active"></span>
                                <span>Creating product...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
