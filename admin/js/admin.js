jQuery(document).ready(function($) {
    // Cache elements
    const $cacheProgress = $('#pod-cache-progress');
    const $cacheControls = $('#pod-cache-controls');
    const $progressBar = $('.pod-progress-fill');
    const $progressPercentage = $('.pod-progress-percentage');
    const $progressText = $('.pod-progress-text');
    const $searchResults = $('.pod-results-grid');
    const $loading = $('.pod-loading');
    const $noResults = $('.pod-no-results');
    const $modal = $('#pod-designer-modal');
    const $connectionNotice = $('.pod-connection-notice');
    
    let updateTimer = null;
    let currentProduct = null;
    let pendingRequest = false;
    const MIN_REQUEST_INTERVAL = 2000; // Minimum 2 seconds between requests

    let cacheUpdateTimer = null;
    let lastStatus = null;
    let consecutiveErrors = 0;
    let pollInterval = 1000; // Start with 1 second
    const MAX_POLL_INTERVAL = 5000; // Max 5 seconds between polls
    const ERROR_BACKOFF_MULTIPLIER = 1.5;

    // API Connection Verification
    $(document).on('click', '.pod-verify-connection', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $button = $(this);
        const nonce = podManagerAdmin.nonces.verify_connection;
        const api_key = $('#pod_printify_api_key').val();
        
        if (!api_key) {
            $connectionNotice.html(`
                <div class="pod-notice pod-notice-error">
                    <p><strong>Error:</strong> Please enter an API key first.</p>
                </div>
            `);
            return;
        }
        
        $button.prop('disabled', true).addClass('updating-message');
        
        // Clear any existing notice
        $connectionNotice.empty();
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_verify_connection',
                nonce: nonce,
                api_key: api_key
            },
            success: function(response) {
                if (response.success) {
                    $connectionNotice.html(`
                        <div class="pod-notice pod-notice-success">
                            <p><strong>Connection successful!</strong> Connected to shop: ${response.data.selected_shop.title}</p>
                        </div>
                    `);
                } else {
                    $connectionNotice.html(`
                        <div class="pod-notice pod-notice-error">
                            <p><strong>Connection failed!</strong> ${response.data.message}</p>
                        </div>
                    `);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $connectionNotice.html(`
                    <div class="pod-notice pod-notice-error">
                        <p><strong>Connection failed!</strong> ${errorThrown}</p>
                    </div>
                `);
            },
            complete: function() {
                $button.prop('disabled', false).removeClass('updating-message');
            }
        });
        
        return false;
    });

    // Cache Refresh
    $(document).on('click', '.pod-refresh-cache', function(e) {
        e.preventDefault();
        const $button = $(this);
        
        // Show progress UI and hide controls
        $cacheProgress.show();
        $cacheControls.hide();
        
        // Reset UI state
        $progressBar.css('width', '0%');
        $progressPercentage.text('0%');
        $('.pod-progress-numbers').text('Starting cache refresh...');
        $('.pod-phase-label').text('initializing');
        $('.pod-phase-item').text('');
        
        // Disable button and show spinner
        $button.prop('disabled', true).addClass('updating-message');
        
        console.log('POD Manager: Starting cache refresh');
        console.log('POD Manager: Nonce:', podManagerAdmin.nonces.refresh_cache);
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_refresh_cache',
                nonce: podManagerAdmin.nonces.refresh_cache
            },
            success: function(response) {
                console.log('POD Manager: Cache refresh response:', response);
                if (response.success) {
                    console.log('POD Manager: Cache refresh started successfully');
                    // Start checking progress
                    if (updateTimer) {
                        clearInterval(updateTimer);
                    }
                    updateCacheProgress();
                    updateTimer = setInterval(updateCacheProgress, 5000);
                } else {
                    console.error('POD Manager: Failed to start cache refresh:', response.data.message);
                    $button.prop('disabled', false).removeClass('updating-message');
                    $('.pod-progress-numbers').text('Error: ' + (response.data.message || 'Failed to start cache refresh'));
                    $('.pod-phase-label').text('error');
                    $('.pod-phase-item').text('');
                    $cacheControls.show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('POD Manager: AJAX error:', textStatus, errorThrown);
                $button.prop('disabled', false).removeClass('updating-message');
                $('.pod-progress-numbers').text('Error: ' + (errorThrown || 'Failed to start cache refresh'));
                $('.pod-phase-label').text('error');
                $('.pod-phase-item').text('');
                $cacheControls.show();
            }
        });
        
        return false;
    });

    // Cancel cache update
    $(document).on('click', '.pod-cancel-cache', function(e) {
        e.preventDefault();
        
        console.log('POD Manager: Canceling cache update');
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_cancel_cache',
                nonce: podManagerAdmin.nonces.cancel_cache
            },
            success: function(response) {
                console.log('POD Manager: Cancel cache response:', response);
                if (response.success) {
                    // Clear update timer
                    if (updateTimer) {
                        clearInterval(updateTimer);
                        updateTimer = null;
                    }
                    
                    // Show cancellation in UI
                    $('.pod-progress-numbers').text('Cache update cancelled');
                    $('.pod-phase-label').text('cancelled');
                    $('.pod-phase-item').text('');
                    
                    // Reset UI after delay
                    setTimeout(function() {
                        $('#pod-cache-progress').hide();
                        $('#pod-cache-controls').show();
                        $('.pod-refresh-cache').prop('disabled', false).removeClass('updating-message');
                        $('.pod-progress-fill').css('width', '0%');
                        $('.pod-progress-percentage').text('0%');
                    }, 3000);
                } else {
                    console.error('POD Manager: Failed to cancel cache:', response.data.message);
                    alert('Failed to cancel cache update: ' + response.data.message);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('POD Manager: Failed to cancel cache:', textStatus, errorThrown);
                alert('Failed to cancel cache update: ' + errorThrown);
            }
        });
        
        return false;
    });
    
    function updateCacheProgress() {
        // Don't make a new request if one is pending
        if (pendingRequest) {
            console.log('POD Manager: Skipping request - previous request still pending');
            return;
        }

        console.log('POD Manager: Checking cache status');
        pendingRequest = true;
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_get_cache_status',
                nonce: podManagerAdmin.nonces.get_cache_status
            },
            success: function(response) {
                pendingRequest = false;
                
                if (!response.success) {
                    console.error('POD Manager: Failed to get cache status:', response);
                    if (updateTimer) {
                        clearInterval(updateTimer);
                        updateTimer = null;
                    }
                    // Show error but keep progress visible
                    $('.pod-progress-numbers').text('Error: ' + (response.data.message || 'Failed to get cache status'));
                    $('.pod-phase-label').text('error');
                    $('.pod-phase-item').text('');
                    $progressBar.css('width', '0%');
                    $progressPercentage.text('0%');
                    
                    // Show controls but keep progress visible
                    $cacheControls.show();
                    $('.pod-refresh-cache').prop('disabled', false).removeClass('updating-message');
                    return;
                }

                const progress = response.data.progress;
                const isUpdating = response.data.is_updating;
                const debugInfo = response.data.debug_info;
                
                console.log('POD Manager: Cache status:', {
                    progress: progress,
                    isUpdating: isUpdating,
                    debugInfo: debugInfo
                });

                // If we're not updating but have a process ID, we're still initializing
                if (!isUpdating && debugInfo.process_id) {
                    console.log('POD Manager: Cache update initializing, process:', debugInfo.process_id);
                    $('.pod-progress-numbers').text('Starting cache update... (Process ' + debugInfo.process_id + ')');
                    $('.pod-phase-label').text('initializing');
                    $('.pod-phase-item').text('Last activity: ' + debugInfo.last_activity_age);
                    return;
                }

                if (!isUpdating) {
                    // Check if we have any progress data
                    if (progress && (progress.current_blueprint || progress.total_blueprints)) {
                        console.log('POD Manager: Cache update completed');
                        if (updateTimer) {
                            clearInterval(updateTimer);
                            updateTimer = null;
                        }
                        $('.pod-progress-numbers').text('Cache update completed');
                        $('.pod-phase-label').text('completed');
                        $('.pod-phase-item').text('');
                        
                        // Show controls but keep progress visible
                        $cacheControls.show();
                        $('.pod-refresh-cache').prop('disabled', false).removeClass('updating-message');
                    } else {
                        // Still initializing or stopped
                        console.log('POD Manager: Cache update not running');
                        if (updateTimer) {
                            clearInterval(updateTimer);
                            updateTimer = null;
                        }
                        $('.pod-progress-numbers').text('Cache update not running');
                        $('.pod-phase-label').text('stopped');
                        $('.pod-phase-item').text('');
                        $cacheControls.show();
                        $('.pod-refresh-cache').prop('disabled', false).removeClass('updating-message');
                    }
                    return;
                }

                // Update progress UI
                let percentage = calculatePercentage(progress);
                $progressBar.css('width', percentage + '%');
                $progressPercentage.text(percentage + '%');

                // Update progress text
                let statusText = '';
                if (progress.phase === 'blueprint_details') {
                    statusText = `Processing blueprint ${progress.current_blueprint} of ${progress.total_blueprints}: ${progress.current_item}`;
                } else if (progress.phase === 'print_providers') {
                    statusText = `Getting print providers for blueprint ${progress.current_blueprint}/${progress.total_blueprints}`;
                } else if (progress.phase === 'variants') {
                    statusText = `Getting variants for blueprint ${progress.current_blueprint}/${progress.total_blueprints}, Provider ${progress.current_provider}/${progress.total_providers}: ${progress.current_item}`;
                } else if (progress.phase === 'shipping') {
                    statusText = `Getting shipping info for blueprint ${progress.current_blueprint}/${progress.total_blueprints}, Provider ${progress.current_provider}/${progress.total_providers}`;
                } else {
                    statusText = progress.current_item || 'Processing...';
                }
                
                // Add process info if available
                if (debugInfo.process_id) {
                    statusText += ` (Process ${debugInfo.process_id}, Last activity: ${debugInfo.last_activity_age})`;
                }
                
                $('.pod-progress-numbers').text(statusText);

                // Update phase display
                if (progress.phase) {
                    $('.pod-phase-label').text(progress.phase.replace(/_/g, ' '));
                }
                if (progress.current_item) {
                    $('.pod-phase-item').text(progress.current_item);
                }

                // Keep progress visible while updating
                $cacheProgress.show();
            },
            error: function(jqXHR, textStatus, errorThrown) {
                pendingRequest = false;
                console.error('POD Manager: Failed to check cache status:', textStatus, errorThrown);
                
                // Don't clear timer on network errors, just skip this update
                $('.pod-progress-numbers').text('Error checking status: ' + errorThrown);
            }
        });
    }

    function calculatePercentage(progress) {
        if (!progress.total_blueprints) {
            return 0;
        }
        
        let percentage = 0;
        const blueprintWeight = 100 / progress.total_blueprints;
        
        if (progress.phase === 'init' || progress.phase === 'blueprints') {
            percentage = 0;
        } else if (progress.phase === 'blueprint_details') {
            percentage = ((progress.current_blueprint - 1) * blueprintWeight);
        } else if (progress.phase === 'print_providers' || progress.phase === 'variants' || progress.phase === 'shipping') {
            const blueprintProgress = (progress.current_blueprint - 1) * blueprintWeight;
            const providerProgress = progress.total_providers ? 
                (progress.current_provider / progress.total_providers) * blueprintWeight : 0;
            percentage = blueprintProgress + providerProgress;
        } else if (progress.phase === 'complete') {
            percentage = 100;
        }
        
        return Math.min(Math.round(percentage), 100);
    }

    function updateCacheStatus() {
        const nonce = podManagerAdmin.nonces.get_cache_status;
        if (!nonce) {
            return;
        }

        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_get_cache_status',
                nonce: nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    const status = response.data;
                    const $updateButton = $('.pod-update-cache');
                    const $resetButton = $('.pod-debug-cache');
                    const $viewButton = $('.pod-view-cache');
                    
                    if (status.is_running) {
                        $updateButton.addClass('updating-message').prop('disabled', true);
                        $resetButton.prop('disabled', true);
                        $viewButton.prop('disabled', true);
                        
                        // Show progress message
                        showStatusMessage(`Updating cache... ${status.progress || 0}% complete`, 'info');
                        
                        // Continue checking status
                        setTimeout(updateCacheStatus, 2000);
                    } else {
                        $updateButton.removeClass('updating-message').prop('disabled', false);
                        $resetButton.prop('disabled', false);
                        $viewButton.prop('disabled', false);
                        
                        if (status.last_error) {
                            showStatusMessage(status.last_error, 'error');
                        } else if (status.is_complete) {
                            showStatusMessage('Cache update completed successfully', 'success');
                            // Refresh cache data display if it's open
                            if ($('#pod-cache-data').is(':visible')) {
                                $('.pod-view-cache').trigger('click');
                            }
                        }
                    }
                }
            }
        });
    }

    // Update Cache button
    $(document).on('click', '.pod-update-cache', function(e) {
        e.preventDefault();
        const $button = $(this);
        
        if ($button.hasClass('updating-message')) {
            return;
        }
        
        const nonce = podManagerAdmin.nonces.refresh_cache;
        if (!nonce) {
            showStatusMessage('Missing security token. Please refresh the page.', 'error');
            return;
        }
        
        // Hide any existing cache data
        $('#pod-cache-data').hide();
        
        $button.addClass('updating-message').prop('disabled', true);
        $('.pod-debug-cache, .pod-view-cache').prop('disabled', true);
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_refresh_cache',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    showStatusMessage('Cache update started...', 'info');
                    updateCacheStatus();
                } else {
                    showStatusMessage(response.data?.message || 'Failed to start cache update', 'error');
                    $button.removeClass('updating-message').prop('disabled', false);
                    $('.pod-debug-cache, .pod-view-cache').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = 'Failed to start cache update';
                if (xhr.status === 403) {
                    errorMsg = 'Security check failed. Please refresh the page and try again.';
                }
                showStatusMessage(errorMsg, 'error');
                $button.removeClass('updating-message').prop('disabled', false);
                $('.pod-debug-cache, .pod-view-cache').prop('disabled', false);
            }
        });
    });

    // Reset Cache Flags button
    $(document).on('click', '.pod-debug-cache', function(e) {
        e.preventDefault();
        const $button = $(this);
        
        if ($button.hasClass('updating-message')) {
            return;
        }
        
        const nonce = podManagerAdmin.nonces.debug_cache;
        if (!nonce) {
            showStatusMessage('Missing security token. Please refresh the page.', 'error');
            return;
        }
        
        $button.addClass('updating-message').prop('disabled', true);
        $('.pod-update-cache, .pod-view-cache').prop('disabled', true);
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_debug_cache',
                nonce: nonce
            },
            success: function(response) {
                console.log('POD Manager: Debug cache response:', response);
                
                if (response.success) {
                    // Hide cache data display since it's now outdated
                    $('#pod-cache-data').hide();
                    showStatusMessage('Cache flags reset successfully. Any running cache updates have been cancelled.', 'success');
                } else {
                    showStatusMessage(response.data?.message || 'Failed to reset cache flags', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('POD Manager: Debug cache error:', {xhr, status, error});
                let errorMsg = 'Failed to reset cache flags';
                if (xhr.status === 403) {
                    errorMsg = 'Security check failed. Please refresh the page and try again.';
                }
                showStatusMessage(errorMsg, 'error');
            },
            complete: function() {
                $button.removeClass('updating-message').prop('disabled', false);
                $('.pod-update-cache, .pod-view-cache').prop('disabled', false);
            }
        });
    });

    // View Cache Data button
    $(document).on('click', '.pod-view-cache', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $cacheData = $('#pod-cache-data');
        
        if ($button.hasClass('updating-message')) {
            return;
        }
        
        const nonce = podManagerAdmin.nonces.view_cache;
        if (!nonce) {
            showStatusMessage('Missing security token. Please refresh the page.', 'error');
            return;
        }
        
        $button.addClass('updating-message').prop('disabled', true);
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_get_cache_data',
                nonce: nonce
            },
            success: function(response) {
                if (response && response.success && response.data && response.data.data) {
                    const data = response.data.data;
                    let html = '<div class="pod-cache-summary">';
                    
                    // Blueprints section
                    html += '<div class="pod-cache-section">';
                    html += '<h3>Blueprints</h3>';
                    if (data.blueprints && typeof data.blueprints === 'object') {
                        html += `<p>Count: <strong>${data.blueprints.count || 0}</strong></p>`;
                        html += `<p>Last Updated: <strong>${data.blueprints.last_updated || 'Never'}</strong></p>`;
                        if (data.blueprints.sample) {
                            html += '<div class="pod-sample-data">';
                            html += '<p>Latest Blueprint:</p>';
                            html += `<pre>${JSON.stringify(data.blueprints.sample, null, 2)}</pre>`;
                            html += '</div>';
                        }
                    } else {
                        html += '<p>No blueprint data available</p>';
                    }
                    html += '</div>';
                    
                    // Providers section
                    html += '<div class="pod-cache-section">';
                    html += '<h3>Providers</h3>';
                    if (data.providers && typeof data.providers === 'object') {
                        html += `<p>Count: <strong>${data.providers.count || 0}</strong></p>`;
                        html += `<p>Last Updated: <strong>${data.providers.last_updated || 'Never'}</strong></p>`;
                        if (data.providers.sample) {
                            html += '<div class="pod-sample-data">';
                            html += '<p>Latest Provider:</p>';
                            html += `<pre>${JSON.stringify(data.providers.sample, null, 2)}</pre>`;
                            html += '</div>';
                        }
                    } else {
                        html += '<p>No provider data available</p>';
                    }
                    html += '</div>';
                    
                    // Variants section
                    html += '<div class="pod-cache-section">';
                    html += '<h3>Variants</h3>';
                    if (data.variants && typeof data.variants === 'object') {
                        html += `<p>Count: <strong>${data.variants.count || 0}</strong></p>`;
                        html += `<p>Last Updated: <strong>${data.variants.last_updated || 'Never'}</strong></p>`;
                        if (data.variants.sample) {
                            html += '<div class="pod-sample-data">';
                            html += '<p>Latest Variant:</p>';
                            html += `<pre>${JSON.stringify(data.variants.sample, null, 2)}</pre>`;
                            html += '</div>';
                        }
                    } else {
                        html += '<p>No variant data available</p>';
                    }
                    html += '</div>';
                    
                    html += `<p class="pod-cache-timestamp">Last checked: ${new Date().toLocaleString()}</p>`;
                    html += '</div>';
                    
                    $cacheData.html(html).show();
                    showStatusMessage('Cache data retrieved successfully', 'success');
                } else {
                    console.error('POD Manager: Invalid cache data response:', response);
                    $cacheData.html('<p class="pod-error">No cache data available.</p>').show();
                    showStatusMessage(response.data?.message || 'Failed to get cache data', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('POD Manager: Cache data error:', {xhr, status, error});
                let errorMsg = 'Failed to get cache data';
                if (xhr.status === 403) {
                    errorMsg = 'Security check failed. Please refresh the page and try again.';
                }
                showStatusMessage(errorMsg, 'error');
                $cacheData.html('<p class="pod-error">Failed to retrieve cache data.</p>').show();
            },
            complete: function() {
                $button.removeClass('updating-message').prop('disabled', false);
            }
        });
    });

    // Product Search
    $(document).on('click', '.pod-search-button', function() {
        const query = $('#pod-search-query').val();
        const provider = $('#pod-provider-filter').val();
        const category = $('#pod-category-filter').val();

        searchProducts(query, {
            provider: provider,
            category: category
        });
    });

    function searchProducts(query, filters) {
        $loading.show();
        $searchResults.hide();
        $noResults.hide();

        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_search_products',
                nonce: podManagerAdmin.nonces.search_products,
                query: query,
                filters: filters
            },
            success: function(response) {
                $loading.hide();
                
                if (response.success && response.data.length > 0) {
                    displayResults(response.data);
                    $searchResults.show();
                } else {
                    $noResults.show();
                }
            },
            error: function() {
                $loading.hide();
                alert('Failed to search products');
            }
        });
    }

    function displayResults(products) {
        $searchResults.empty();
        
        products.forEach(product => {
            const $product = $(`
                <div class="pod-product-card">
                    <img src="${product.thumbnail}" alt="${product.title}">
                    <h3>${product.title}</h3>
                    <p class="pod-provider">${product.print_provider.title}</p>
                    <button type="button" class="button button-primary pod-design-product" data-product='${JSON.stringify(product)}'>
                        Design Product
                    </button>
                </div>
            `);
            
            $searchResults.append($product);
        });
    }

    // Product Designer
    $(document).on('click', '.pod-design-product', function() {
        currentProduct = JSON.parse($(this).data('product'));
        openDesigner(currentProduct);
    });

    function openDesigner(product) {
        // Reset form
        $('#pod-product-title').val(product.title);
        $('#pod-product-description').val(product.description);
        $('#pod-design-upload').val('');
        $('#pod-preview-img').attr('src', product.thumbnail);
        
        // Show modal
        $modal.show();
    }

    $(document).on('click', '.pod-modal-close', function() {
        $modal.hide();
        currentProduct = null;
    });

    // Design Controls
    $('#pod-design-upload').on('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('action', 'pod_upload_image');
        formData.append('nonce', podManagerAdmin.nonces.upload_image);
        formData.append('image', file);

        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    // Handle successful upload
                    // TODO: Update preview with uploaded image
                } else {
                    alert('Failed to upload image: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to upload image');
            }
        });
    });

    $('.pod-create-product').on('click', function() {
        if (!currentProduct) return;

        const title = $('#pod-product-title').val();
        const description = $('#pod-product-description').val();

        if (!title) {
            alert('Please enter a product title');
            return;
        }

        const productData = {
            blueprint_id: currentProduct.id,
            title: title,
            description: description,
            // Add other product data as needed
        };

        $('.pod-create-status').show();
        $(this).prop('disabled', true);

        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_create_product',
                nonce: podManagerAdmin.nonces.create_product,
                product_data: JSON.stringify(productData)
            },
            success: function(response) {
                $('.pod-create-status').hide();
                $('.pod-create-product').prop('disabled', false);
                
                if (response.success) {
                    alert('Product created successfully!');
                    $modal.hide();
                } else {
                    alert('Failed to create product: ' + response.data);
                }
            },
            error: function() {
                $('.pod-create-status').hide();
                $('.pod-create-product').prop('disabled', false);
                alert('Failed to create product');
            }
        });
    });

    // Password Toggle
    $('.pod-toggle-password').on('click', function() {
        const $button = $(this);
        const $input = $('#pod_printify_api_key');
        const $icon = $button.find('.dashicons');
        
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $input.attr('type', 'password');
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    // Debug Cache - Reset stuck flags
    $(document).on('click', '.pod-debug-cache', function(e) {
        e.preventDefault();
        const $button = $(this);
        
        console.log('POD Manager: Debug cache button clicked');
        console.log('POD Manager: Available nonces:', podManagerAdmin.nonces);
        
        if ($button.hasClass('updating-message')) {
            return;
        }
        
        const nonce = podManagerAdmin.nonces.debug_cache;
        console.log('POD Manager: Using debug cache nonce:', nonce);
        
        if (!nonce) {
            console.error('POD Manager: Missing debug cache nonce');
            showStatusMessage('Missing security token. Please refresh the page.', 'error');
            return;
        }
        
        $button.addClass('updating-message').prop('disabled', true);
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_debug_cache',
                nonce: nonce
            },
            success: function(response) {
                console.log('POD Manager: Debug cache response:', response);
                if (response.success) {
                    showStatusMessage('Cache flags reset successfully. Any running cache updates have been cancelled.');
                    
                    // Refresh the cache data display if it's visible
                    if ($('#pod-cache-data').is(':visible')) {
                        setTimeout(() => {
                            console.log('POD Manager: Refreshing cache data display');
                            $('.pod-view-cache').trigger('click');
                        }, 500); // Add a small delay to ensure all flags are reset
                    }
                } else {
                    console.error('POD Manager: Debug cache failed:', response);
                    showStatusMessage(response.data.message || 'Failed to reset cache flags', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('POD Manager: Debug cache error:', {xhr, status, error});
                console.error('POD Manager: Debug cache error response:', xhr.responseText);
                
                let errorMsg = 'Failed to reset cache flags';
                if (xhr.status === 403) {
                    errorMsg = 'Security check failed. Please refresh the page and try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                showStatusMessage(errorMsg, 'error');
            },
            complete: function() {
                $button.removeClass('updating-message').prop('disabled', false);
            }
        });
    });

    // View Cache Data button
    $(document).on('click', '.pod-view-cache', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $cacheData = $('#pod-cache-data');
        
        console.log('POD Manager: View cache button clicked');
        
        if ($button.hasClass('updating-message')) {
            return;
        }
        
        const nonce = podManagerAdmin.nonces.view_cache;
        if (!nonce) {
            showStatusMessage('Missing security token. Please refresh the page.', 'error');
            return;
        }
        
        $button.addClass('updating-message').prop('disabled', true);
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_get_cache_data',
                nonce: nonce
            },
            success: function(response) {
                console.log('POD Manager: Raw response:', response);
                
                if (response && response.success && response.data && response.data.data) {
                    const data = response.data.data; // Access the inner data object
                    console.log('POD Manager: Cache data:', data);
                    let html = '<div class="pod-cache-summary">';
                    
                    // Blueprints section
                    html += '<div class="pod-cache-section">';
                    html += '<h3>Blueprints</h3>';
                    if (data.blueprints && typeof data.blueprints === 'object') {
                        html += `<p>Count: <strong>${data.blueprints.count || 0}</strong></p>`;
                        html += `<p>Last Updated: <strong>${data.blueprints.last_updated || 'Never'}</strong></p>`;
                        if (data.blueprints.sample) {
                            html += '<div class="pod-sample-data">';
                            html += '<p>Latest Blueprint:</p>';
                            html += `<pre>${JSON.stringify(data.blueprints.sample, null, 2)}</pre>`;
                            html += '</div>';
                        }
                    } else {
                        html += '<p>No blueprint data available</p>';
                    }
                    html += '</div>';
                    
                    // Providers section
                    html += '<div class="pod-cache-section">';
                    html += '<h3>Providers</h3>';
                    if (data.providers && typeof data.providers === 'object') {
                        html += `<p>Count: <strong>${data.providers.count || 0}</strong></p>`;
                        html += `<p>Last Updated: <strong>${data.providers.last_updated || 'Never'}</strong></p>`;
                        if (data.providers.sample) {
                            html += '<div class="pod-sample-data">';
                            html += '<p>Latest Provider:</p>';
                            html += `<pre>${JSON.stringify(data.providers.sample, null, 2)}</pre>`;
                            html += '</div>';
                        }
                    } else {
                        html += '<p>No provider data available</p>';
                    }
                    html += '</div>';
                    
                    // Variants section
                    html += '<div class="pod-cache-section">';
                    html += '<h3>Variants</h3>';
                    if (data.variants && typeof data.variants === 'object') {
                        html += `<p>Count: <strong>${data.variants.count || 0}</strong></p>`;
                        html += `<p>Last Updated: <strong>${data.variants.last_updated || 'Never'}</strong></p>`;
                        if (data.variants.sample) {
                            html += '<div class="pod-sample-data">';
                            html += '<p>Latest Variant:</p>';
                            html += `<pre>${JSON.stringify(data.variants.sample, null, 2)}</pre>`;
                            html += '</div>';
                        }
                    } else {
                        html += '<p>No variant data available</p>';
                    }
                    html += '</div>';
                    
                    html += `<p class="pod-cache-timestamp">Last checked: ${new Date().toLocaleString()}</p>`;
                    html += '</div>';
                    
                    $cacheData.html(html).show();
                    showStatusMessage('Cache data retrieved successfully', 'success');
                } else {
                    console.error('POD Manager: Invalid cache data response:', response);
                    $cacheData.html('<p class="pod-error">No cache data available.</p>').show();
                    showStatusMessage(response.data?.message || 'Failed to get cache data', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('POD Manager: Cache data error:', {xhr, status, error});
                let errorMsg = 'Failed to get cache data';
                if (xhr.status === 403) {
                    errorMsg = 'Security check failed. Please refresh the page and try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                showStatusMessage(errorMsg, 'error');
                $cacheData.html('<p class="pod-error">Failed to retrieve cache data.</p>').show();
            },
            complete: function() {
                $button.removeClass('updating-message').prop('disabled', false);
            }
        });
    });

    // Debug cache button
    $(document).on('click', '.pod-debug-cache', function(e) {
        e.preventDefault();
        const $button = $(this);
        
        console.log('POD Manager: Debug cache button clicked');
        console.log('POD Manager: Available nonces:', podManagerAdmin.nonces);
        
        if ($button.hasClass('updating-message')) {
            return;
        }
        
        const nonce = podManagerAdmin.nonces.debug_cache;
        console.log('POD Manager: Using debug cache nonce:', nonce);
        
        if (!nonce) {
            console.error('POD Manager: Missing debug cache nonce');
            showNotification('Missing security token. Please refresh the page.', 'error');
            return;
        }
        
        $button.addClass('updating-message').prop('disabled', true);
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_debug_cache',
                nonce: nonce
            },
            success: function(response) {
                console.log('POD Manager: Debug cache response:', response);
                if (response.success) {
                    showNotification(response.data.message || 'Cache flags reset successfully', 'success');
                    
                    // Refresh the cache data display if it's visible
                    if ($('#pod-cache-data').is(':visible')) {
                        setTimeout(() => {
                            console.log('POD Manager: Refreshing cache data display');
                            $('.pod-view-cache').trigger('click');
                        }, 500); // Add a small delay to ensure all flags are reset
                    }
                } else {
                    console.error('POD Manager: Debug cache failed:', response);
                    showNotification(response.data.message || 'Failed to reset cache flags', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('POD Manager: Debug cache error:', {xhr, status, error});
                console.error('POD Manager: Debug cache error response:', xhr.responseText);
                
                let errorMsg = 'Failed to reset cache flags';
                if (xhr.status === 403) {
                    errorMsg = 'Security check failed. Please refresh the page and try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                showNotification(errorMsg, 'error');
            },
            complete: function() {
                $button.removeClass('updating-message').prop('disabled', false);
            }
        });
    });

    // Start cache update
    $('#pod-update-cache').on('click', function() {
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_refresh_cache',
                nonce: podManagerAdmin.nonces.refresh_cache
            },
            success: function(response) {
                if (response.success) {
                    startCacheUpdate();
                } else {
                    showNotification('Failed to start cache update: ' + response.data, 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotification('Failed to start cache update: ' + error, 'error');
            }
        });
    });

    // Add cancel button handler
    $('#pod-cancel-cache').on('click', function() {
        if (!confirm('Are you sure you want to cancel the cache update?')) {
            return;
        }
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_cancel_cache',
                nonce: podManagerAdmin.nonces.cancel_cache
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Cache update cancelled', 'warning');
                } else {
                    showNotification('Failed to cancel cache update: ' + response.data, 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotification('Failed to cancel cache update: ' + error, 'error');
            }
        });
    });
});
