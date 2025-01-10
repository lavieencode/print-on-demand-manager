jQuery(document).ready(function($) {
    // Constants
    const MIN_REQUEST_INTERVAL = 2000; // 2 seconds between status checks

    // Cache elements
    const $cacheProgress = $('.pod-cache-progress');
    const $cacheControls = $('.pod-cache-controls');
    const $progressBar = $('.pod-progress-fill');
    const $progressPercentage = $('.pod-progress-percentage');
    const $progressNumbers = $('.pod-progress-numbers');
    const $refreshButton = $('.pod-refresh-cache');
    const $cancelButton = $('.pod-cancel-cache');
    const $searchResults = $('.pod-results-grid');
    const $loading = $('.pod-loading');
    const $noResults = $('.pod-no-results');
    const $modal = $('#pod-designer-modal');
    const $connectionNotice = $('.pod-connection-notice');

    // State variables
    let updateTimer = null;
    let pendingRequest = false;

    // Helper function to show messages
    function showMessage(message, type = 'info') {
        const $notice = $('.pod-status-message');
        $notice.removeClass('notice-success notice-error notice-info notice-warning')
            .addClass('notice notice-' + type)
            .html('<p>' + message + '</p>')
            .show();
    }

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
                
                if (!response || !response.success) {
                    console.error('POD Manager: Failed to get cache status:', response);
                    if (updateTimer) {
                        clearInterval(updateTimer);
                        updateTimer = null;
                    }
                    showMessage('Failed to get cache status: ' + (response?.data?.message || 'Unknown error'), 'error');
                    return;
                }

                const progress = response.data.progress;
                const isRunning = response.data.is_running;
                const debugInfo = response.data.debug_info;
                
                console.log('POD Manager: Cache status:', {
                    progress: progress,
                    isRunning: isRunning,
                    debugInfo: debugInfo
                });

                // Update progress UI
                if (progress.percentage !== undefined) {
                    $progressBar.css('width', progress.percentage + '%');
                    $progressPercentage.text(Math.round(progress.percentage) + '%');
                }

                // Update progress text
                let statusText = progress.current_item || 'Processing...';
                $progressNumbers.text(statusText);

                // Handle different states
                if (isRunning) {
                    // Keep progress visible while updating
                    $cacheProgress.show();
                    $cacheControls.hide();
                    $cancelButton.show().prop('disabled', false);
                    
                    // Start polling if not already started
                    if (!updateTimer) {
                        updateTimer = setInterval(updateCacheProgress, MIN_REQUEST_INTERVAL);
                    }
                } else {
                    // Show controls when not running
                    $cacheControls.show();
                    
                    // Handle specific states
                    switch (progress.status) {
                        case 'complete':
                            $progressNumbers.text('Cache update completed');
                            $refreshButton.prop('disabled', false).removeClass('updating-message');
                            $cancelButton.hide();
                            showMessage('Cache update completed successfully', 'success');
                            break;
                            
                        case 'cancelled':
                            $progressNumbers.text('Cache update cancelled');
                            $progressBar.css('width', '0%');
                            $progressPercentage.text('0%');
                            $refreshButton.prop('disabled', false).removeClass('updating-message');
                            $cancelButton.hide();
                            showMessage('Cache update cancelled', 'info');
                            break;
                            
                        case 'error':
                            const errorMsg = progress.error || 'Unknown error occurred';
                            $progressNumbers.text('Error: ' + errorMsg);
                            $refreshButton.prop('disabled', false).removeClass('updating-message');
                            $cancelButton.hide();
                            showMessage('Cache update error: ' + errorMsg, 'error');
                            break;
                    }
                    
                    // Stop timer for terminal states
                    if (['complete', 'cancelled', 'error'].includes(progress.status)) {
                        if (updateTimer) {
                            clearInterval(updateTimer);
                            updateTimer = null;
                        }
                    }
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                pendingRequest = false;
                console.error('POD Manager: Failed to check cache status:', textStatus, errorThrown);
                if (updateTimer) {
                    clearInterval(updateTimer);
                    updateTimer = null;
                }
                showMessage('Error checking status: ' + errorThrown, 'error');
            }
        });
    }

    // Cache Refresh
    $refreshButton.on('click', function(e) {
        e.preventDefault();
        
        // Show progress UI and hide controls
        $cacheProgress.show();
        $cacheControls.hide();
        $cancelButton.show().prop('disabled', false);
        
        // Reset UI state
        $progressBar.css('width', '0%');
        $progressPercentage.text('0%');
        $progressNumbers.text('Starting cache refresh...');
        // Disable button and show spinner
        $refreshButton.prop('disabled', true).addClass('updating-message');
        
        console.log('POD Manager: Starting cache refresh');
        
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
                    updateTimer = setInterval(updateCacheProgress, MIN_REQUEST_INTERVAL);
                } else {
                    console.error('POD Manager: Failed to start cache refresh:', response.data.message);
                    showMessage('Failed to start cache refresh: ' + (response.data ? response.data.message : 'Unknown error'), 'error');
                    $refreshButton.prop('disabled', false).removeClass('updating-message');
                    $progressNumbers.text('Error: ' + (response.data ? response.data.message : 'Failed to start cache refresh'));
                    $cacheControls.show();
                    $cancelButton.hide();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('POD Manager: AJAX error:', textStatus, errorThrown);
                showMessage('Failed to start cache refresh: ' + errorThrown, 'error');
                $refreshButton.prop('disabled', false).removeClass('updating-message');
                $progressNumbers.text('Error: ' + (errorThrown || 'Failed to start cache refresh'));
                $cacheControls.show();
                $cancelButton.hide();
            }
        });
        
        return false;
    });

    // Cancel Cache Update
    $('.pod-cancel-cache').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to cancel the cache update?')) {
            return false;
        }
        
        // Disable the button to prevent double-clicks
        const $button = $(this);
        $button.prop('disabled', true);
        
        console.log('POD Manager: Sending cancel request');
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_cancel_cache',
                nonce: podManagerAdmin.nonces.cancel_cache
            },
            success: function(response) {
                console.log('POD Manager: Cancel response:', response);
                
                if (response.success) {
                    showMessage('Cache update cancelled', 'warning');
                    // Force immediate status check
                    updateCacheProgress();
                    // Hide cancel button
                    $button.hide();
                    // Show refresh button
                    $refreshButton.prop('disabled', false).removeClass('updating-message').show();
                    // Clear any update timer
                    if (updateTimer) {
                        clearInterval(updateTimer);
                        updateTimer = null;
                    }
                    // Reset progress display
                    $progressBar.css('width', '0%');
                    $progressPercentage.text('0%');
                    $progressNumbers.text('Cache update cancelled');
                    $cacheControls.show();
                } else {
                    const errorMsg = response.data?.message || 'Unknown error';
                    console.error('POD Manager: Failed to cancel cache:', errorMsg);
                    showMessage('Failed to cancel cache update: ' + errorMsg, 'error');
                    $button.prop('disabled', false);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('POD Manager: Failed to cancel cache:', textStatus, errorThrown);
                showMessage('Failed to cancel cache update: ' + errorThrown, 'error');
                $button.prop('disabled', false);
            }
        });
        
        return false;
    });

    // API Connection Verification
    $(document).on('click', '#pod-verify-connection', function(e) {
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

    // Product Search
    $(document).on('click', '#pod-search-button', function() {
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
        const product = JSON.parse($(this).data('product'));
        openDesigner(product);
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

    $('#pod-create-product').on('click', function() {
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

        $(this).prop('disabled', true);
        $('.pod-create-status').show();

        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_create_product',
                nonce: podManagerAdmin.nonces.create_product,
                product_data: JSON.stringify(productData)
            },
            success: function(response) {
                $(this).prop('disabled', false);
                $('.pod-create-status').hide();
                
                if (response.success) {
                    alert('Product created successfully!');
                    $modal.hide();
                } else {
                    alert('Failed to create product: ' + response.data);
                }
            },
            error: function() {
                $(this).prop('disabled', false);
                $('.pod-create-status').hide();
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
                            $('#pod-view-cache').trigger('click');
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

    // View Cache Data button
    $(document).on('click', '#pod-view-cache', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $cacheData = $('#pod-cache-data');
        
        console.log('POD Manager: View cache button clicked');
        
        if ($button.hasClass('updating-message')) {
            return;
        }
        
        const nonce = podManagerAdmin.nonces.view_cache;
        if (!nonce) {
            showNotification('Missing security token. Please refresh the page.', 'error');
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
                    showNotification('Cache data retrieved successfully', 'success');
                } else {
                    console.error('POD Manager: Invalid cache data response:', response);
                    $cacheData.html('<p class="pod-error">No cache data available.</p>').show();
                    showNotification(response.data?.message || 'Failed to get cache data', 'error');
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
                showNotification(errorMsg, 'error');
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
                            $('#pod-view-cache').trigger('click');
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
        const $button = $(this);
        $button.addClass('updating-message').prop('disabled', true);
        
        $.ajax({
            url: podManagerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_refresh_cache',
                nonce: podManagerAdmin.nonces.refresh_cache
            },
            success: function(response) {
                if (response.success) {
                    // Show the progress UI
                    $cacheProgress.show();
                    $cacheControls.hide();
                    $cancelButton.show().prop('disabled', false);
                    
                    // Start progress updates
                    updateCacheProgress();
                    if (!updateTimer) {
                        updateTimer = setInterval(updateCacheProgress, MIN_REQUEST_INTERVAL);
                    }
                    
                    showMessage('Cache update started successfully', 'success');
                } else {
                    showMessage('Failed to start cache update: ' + response.data.message, 'error');
                    $button.removeClass('updating-message').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                showMessage('Failed to start cache update: ' + error, 'error');
                $button.removeClass('updating-message').prop('disabled', false);
            }
        });
    });

    // Check initial status
    if ($cacheProgress.is(':visible')) {
        updateCacheProgress();
        updateTimer = setInterval(updateCacheProgress, MIN_REQUEST_INTERVAL);
    }

    // Quick Add Wizard functionality
    (function($) {
        // State management
        const wizardState = {
            currentStep: 1,
            totalSteps: 6,
            patterns: [],
            mainPattern: null,
            selectedProducts: [],
            productConfigs: {},
            designSettings: {},
            finalSettings: {}
        };

        // Initialize wizard
        function initWizard() {
            updateStepIndicators();
            initDropzone();
            bindWizardEvents();
        }

        // Update step indicators
        function updateStepIndicators() {
            $('.pod-step-indicator').removeClass('active completed');
            $(`.pod-step-indicator[data-step="${wizardState.currentStep}"]`).addClass('active');
            for (let i = 1; i < wizardState.currentStep; i++) {
                $(`.pod-step-indicator[data-step="${i}"]`).addClass('completed');
            }
        }

        // Initialize dropzone for pattern upload
        function initDropzone() {
            const dropzone = $('#pod-pattern-dropzone');
            const fileInput = $('#pod-pattern-files');

            dropzone.on('click', () => fileInput.click());
            dropzone.on('dragover dragenter', (e) => {
                e.preventDefault();
                dropzone.addClass('dragging');
            });
            dropzone.on('dragleave dragend drop', (e) => {
                e.preventDefault();
                dropzone.removeClass('dragging');
            });
            dropzone.on('drop', (e) => {
                e.preventDefault();
                const files = e.originalEvent.dataTransfer.files;
                handleFiles(files);
            });
            fileInput.on('change', (e) => handleFiles(e.target.files));
        }

        // Handle uploaded files
        function handleFiles(files) {
            const validFiles = Array.from(files).filter(file => {
                const validTypes = ['image/jpeg', 'image/png', 'image/jpg'];
                const maxSize = 10 * 1024 * 1024; // 10MB
                return validTypes.includes(file.type) && file.size <= maxSize;
            });

            if (validFiles.length === 0) {
                alert('Please upload valid image files (PNG, JPG) under 10MB each.');
                return;
            }

            const preview = $('#pod-pattern-preview');
            preview.empty();

            validFiles.forEach(file => {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const img = $('<img>').addClass('pod-pattern-thumbnail');
                    img.attr('src', e.target.result);
                    preview.append(img);
                    wizardState.patterns.push({
                        file: file,
                        dataUrl: e.target.result
                    });
                };
                reader.readAsDataURL(file);
            });

            updateNextButtonState();
        }

        // Bind wizard navigation events
        function bindWizardEvents() {
            $('.pod-next-step').on('click', nextStep);
            $('.pod-prev-step').on('click', prevStep);
            $('.pod-create-products').on('click', createProducts);

            // Step-specific events
            $(document).on('click', '.pod-pattern-thumbnail', function() {
                if (wizardState.currentStep === 2) {
                    $('.pod-pattern-thumbnail').removeClass('selected');
                    $(this).addClass('selected');
                    wizardState.mainPattern = wizardState.patterns[$(this).index()];
                    updateNextButtonState();
                }
            });

            $('.pod-search-button').on('click', function() {
                if (wizardState.currentStep === 3) {
                    searchProducts();
                }
            });
        }

        // Handle next step
        function nextStep() {
            if (!validateCurrentStep()) {
                return;
            }

            if (wizardState.currentStep < wizardState.totalSteps) {
                $(`.pod-wizard-step[data-step="${wizardState.currentStep}"]`).hide();
                wizardState.currentStep++;
                $(`.pod-wizard-step[data-step="${wizardState.currentStep}"]`).show();
                updateStepIndicators();
                updateNavigationButtons();
                initializeCurrentStep();
            }
        }

        // Handle previous step
        function prevStep() {
            if (wizardState.currentStep > 1) {
                $(`.pod-wizard-step[data-step="${wizardState.currentStep}"]`).hide();
                wizardState.currentStep--;
                $(`.pod-wizard-step[data-step="${wizardState.currentStep}"]`).show();
                updateStepIndicators();
                updateNavigationButtons();
                initializeCurrentStep();
            }
        }

        // Validate current step
        function validateCurrentStep() {
            switch (wizardState.currentStep) {
                case 1:
                    return wizardState.patterns.length > 0;
                case 2:
                    return wizardState.mainPattern !== null;
                case 3:
                    return wizardState.selectedProducts.length > 0;
                case 4:
                    return Object.keys(wizardState.productConfigs).length === wizardState.selectedProducts.length;
                case 5:
                    return Object.keys(wizardState.designSettings).length > 0;
                default:
                    return true;
            }
        }

        // Initialize current step
        function initializeCurrentStep() {
            switch (wizardState.currentStep) {
                case 2:
                    initializeMainPatternSelection();
                    break;
                case 3:
                    initializeProductSelection();
                    break;
                case 4:
                    initializeProductConfiguration();
                    break;
                case 5:
                    initializeDesigner();
                    break;
                case 6:
                    initializeFinalSettings();
                    break;
            }
        }

        // Update navigation buttons
        function updateNavigationButtons() {
            const $prev = $('.pod-prev-step');
            const $next = $('.pod-next-step');
            const $create = $('.pod-create-products');

            $prev.toggle(wizardState.currentStep > 1);
            $next.toggle(wizardState.currentStep < wizardState.totalSteps);
            $create.toggle(wizardState.currentStep === wizardState.totalSteps);

            updateNextButtonState();
        }

        // Update next button state
        function updateNextButtonState() {
            const $next = $('.pod-next-step');
            const $create = $('.pod-create-products');
            const isValid = validateCurrentStep();

            $next.prop('disabled', !isValid);
            $create.prop('disabled', !isValid);
        }

        // Create products
        function createProducts() {
            const $createButton = $('.pod-create-products');
            $createButton.prop('disabled', true).text('Creating Products...');

            const formData = new FormData();
            formData.append('action', 'pod_create_products');
            formData.append('nonce', podManagerAdmin.nonces.create_products);
            formData.append('wizard_state', JSON.stringify(wizardState));

            // Append pattern files
            wizardState.patterns.forEach((pattern, index) => {
                formData.append(`pattern_${index}`, pattern.file);
            });

            $.ajax({
                url: podManagerAdmin.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showMessage('Products created successfully!', 'success');
                        // Reset wizard after successful creation
                        resetWizard();
                    } else {
                        showMessage('Failed to create products: ' + response.data.message, 'error');
                        $createButton.prop('disabled', false).text('Create Products');
                    }
                },
                error: function(xhr, status, error) {
                    showMessage('Error creating products: ' + error, 'error');
                    $createButton.prop('disabled', false).text('Create Products');
                }
            });
        }

        // Reset wizard state
        function resetWizard() {
            wizardState.currentStep = 1;
            wizardState.patterns = [];
            wizardState.mainPattern = null;
            wizardState.selectedProducts = [];
            wizardState.productConfigs = {};
            wizardState.designSettings = {};
            wizardState.finalSettings = {};

            // Reset UI
            $('.pod-pattern-preview').empty();
            $('.pod-pattern-thumbnail').removeClass('selected');
            $('.pod-product-grid').empty();
            $('.pod-selected-products').empty();
            
            // Show first step
            $('.pod-wizard-step').hide();
            $('.pod-wizard-step[data-step="1"]').show();
            
            updateStepIndicators();
            updateNavigationButtons();
        }

        // Initialize wizard when document is ready
        $(document).ready(function() {
            initWizard();
        });
    })(jQuery);
});
