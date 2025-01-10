<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
$printify = new POD_Printify_Platform();
$providers = $printify->get_cached_providers(false);
?>

<div class="wrap">
    <h1 class="wp-heading-inline">QUICK ADD PRODUCTS</h1>

    <!-- Multi-step wizard container -->
    <div class="pod-wizard-container">
        <!-- Step progress bar -->
        <div class="pod-wizard-progress">
            <div class="pod-step-indicator active" data-step="1">
                <span class="pod-step-number">1</span>
                <span class="pod-step-label">Select Background Patterns</span>
            </div>
            <div class="pod-step-indicator" data-step="2">
                <span class="pod-step-number">2</span>
                <span class="pod-step-label">Main Pattern</span>
            </div>
            <div class="pod-step-indicator" data-step="3">
                <span class="pod-step-number">3</span>
                <span class="pod-step-label">Select Products</span>
            </div>
            <div class="pod-step-indicator" data-step="4">
                <span class="pod-step-number">4</span>
                <span class="pod-step-label">Configure Products</span>
            </div>
            <div class="pod-step-indicator" data-step="5">
                <span class="pod-step-number">5</span>
                <span class="pod-step-label">Design Layout</span>
            </div>
            <div class="pod-step-indicator" data-step="6">
                <span class="pod-step-number">6</span>
                <span class="pod-step-label">Final Settings</span>
            </div>
        </div>

        <!-- Step 1: Background Patterns -->
        <div class="pod-wizard-step" data-step="1">
            <h2>Step 1: Select Background Patterns</h2>
            
            <div class="pod-step-content">
                <div class="pod-patterns-grid">
                    <!-- Left column: Available Patterns -->
                    <div class="pod-pattern-section">
                        <div class="pod-section-header">
                            <h3>Available Patterns</h3>
                        </div>
                        <div class="pod-pattern-upload">
                            <div class="pod-dropzone" id="pod-pattern-dropzone">
                                <div class="pod-dropzone-message">
                                    <i class="dashicons dashicons-upload"></i>
                                    <p>Drag and drop pattern files here or click to select</p>
                                </div>
                                <input type="file" id="pod-pattern-files" multiple accept="image/*" style="display: none;">
                            </div>
                            <div class="pod-pattern-preview" id="pod-pattern-preview"></div>
                        </div>
                        <div class="pod-pattern-requirements">
                            <h4>Requirements:</h4>
                            <ul>
                                <li>High resolution (2000x2000px recommended)</li>
                                <li>PNG or JPG format</li>
                                <li>Max 10MB per file</li>
                                <li>Seamless/tileable patterns recommended</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Transfer buttons -->
                    <div class="pod-transfer-buttons">
                        <button type="button" class="button pod-transfer-right" title="Add selected patterns">
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                        <button type="button" class="button pod-transfer-left" title="Remove selected patterns">
                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                        </button>
                    </div>

                    <!-- Right column: Selected Patterns -->
                    <div class="pod-pattern-section">
                        <div class="pod-section-header">
                            <h3>Selected Patterns</h3>
                            <span class="pod-patterns-counter">0/10</span>
                        </div>
                        <div class="pod-selected-patterns">
                            <div class="pod-no-patterns">No patterns available</div>
                            <div class="pod-selected-preview" id="pod-selected-preview"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Main Pattern Selection -->
        <div class="pod-wizard-step" data-step="2" style="display: none;">
            <h2>Step 2: Select Main Pattern</h2>
            <div class="pod-main-pattern-selection">
                <p>Choose the main pattern that will be used as the primary design:</p>
                <div class="pod-pattern-grid" id="pod-main-pattern-grid"></div>
            </div>
        </div>

        <!-- Step 3: Product Selection -->
        <div class="pod-wizard-step" data-step="3" style="display: none;">
            <h2>Step 3: Select Products</h2>
            <div class="pod-product-filters">
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
                <input type="text" id="pod-search-query" placeholder="Search products...">
                <button type="button" class="button button-primary pod-search-button">Search</button>
            </div>
            <div class="pod-product-grid" id="pod-product-grid"></div>
        </div>

        <!-- Step 4: Product Configuration -->
        <div class="pod-wizard-step" data-step="4" style="display: none;">
            <h2>Step 4: Configure Products</h2>
            <div class="pod-product-config" id="pod-product-config">
                <div class="pod-selected-products"></div>
            </div>
        </div>

        <!-- Step 5: Design Layout -->
        <div class="pod-wizard-step" data-step="5" style="display: none;">
            <h2>Step 5: Design Layout</h2>
            <div class="pod-designer">
                <div class="pod-preview-area">
                    <div class="pod-preview-canvas"></div>
                    <div class="pod-preview-controls">
                        <button type="button" class="button pod-rotate-left">↶</button>
                        <button type="button" class="button pod-zoom-out">-</button>
                        <button type="button" class="button pod-zoom-in">+</button>
                        <button type="button" class="button pod-rotate-right">↷</button>
                    </div>
                </div>
                <div class="pod-design-controls">
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
                </div>
            </div>
        </div>

        <!-- Step 6: Final Settings -->
        <div class="pod-wizard-step" data-step="6" style="display: none;">
            <h2>Step 6: Final Settings</h2>
            <div class="pod-final-settings">
                <div class="pod-settings-group">
                    <label for="pod-product-title">Product Title Format</label>
                    <input type="text" id="pod-product-title" placeholder="e.g., {pattern_name} {product_name}">
                    <p class="description">Use {pattern_name} and {product_name} as placeholders</p>
                </div>
                <div class="pod-settings-group">
                    <label for="pod-product-description">Product Description Template</label>
                    <textarea id="pod-product-description" rows="4" placeholder="Enter product description template..."></textarea>
                    <p class="description">Use {pattern_name}, {product_name}, and {materials} as placeholders</p>
                </div>
                <div class="pod-settings-group">
                    <label>Price Adjustment</label>
                    <select id="pod-price-adjustment">
                        <option value="percentage">Percentage Markup</option>
                        <option value="fixed">Fixed Amount</option>
                    </select>
                    <input type="number" id="pod-price-value" step="0.01" min="0">
                </div>
            </div>
        </div>

        <!-- Navigation buttons -->
        <div class="pod-wizard-navigation">
            <button type="button" class="button pod-prev-step" style="display: none;">Previous</button>
            <button type="button" class="button button-primary pod-next-step">Next Step</button>
            <button type="button" class="button button-primary pod-create-products" style="display: none;">Create Products</button>
        </div>
    </div>
</div>
