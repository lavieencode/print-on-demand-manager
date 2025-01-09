jQuery(document).ready(function($) {
    // Cache elements
    const $cacheProgress = $('#pod-cache-progress');
    const $cacheControls = $('#pod-cache-controls');
    const $progressBar = $('.pod-progress-fill');
    const $progressText = $('.pod-progress-text');
    const $searchResults = $('.pod-results-grid');
    const $loading = $('.pod-loading');
    const $noResults = $('.pod-no-results');
    const $modal = $('#pod-designer-modal');
    
    let updateTimer = null;
    let currentProduct = null;

    // Cache Management
    $('.pod-refresh-cache').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_refresh_cache',
                nonce: podManagerAdmin.nonce.refresh_cache
            },
            success: function(response) {
                if (response.success) {
                    $cacheProgress.show();
                    $cacheControls.hide();
                    startProgressCheck();
                } else {
                    alert('Failed to start cache update: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to start cache update');
            }
        });
    });

    $('.pod-cancel-cache').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_cancel_cache',
                nonce: podManagerAdmin.nonce.cancel_cache
            },
            success: function(response) {
                if (response.success) {
                    stopProgressCheck();
                    $cacheProgress.hide();
                    $cacheControls.show();
                } else {
                    alert('Failed to cancel cache update: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to cancel cache update');
            }
        });
    });

    function startProgressCheck() {
        updateTimer = setInterval(checkProgress, 2000);
    }

    function stopProgressCheck() {
        if (updateTimer) {
            clearInterval(updateTimer);
            updateTimer = null;
        }
    }

    function checkProgress() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_get_cache_status',
                nonce: podManagerAdmin.nonce.get_cache_status
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    if (!data.is_updating) {
                        stopProgressCheck();
                        $cacheProgress.hide();
                        $cacheControls.show();
                        return;
                    }

                    const progress = data.progress;
                    const percent = (progress.total > 0) ? (progress.current / progress.total * 100) : 0;
                    
                    $progressBar.css('width', percent + '%');
                    $progressText.text(`Processing ${progress.current} of ${progress.total} products...`);
                }
            }
        });
    }

    // Product Search
    $('.pod-search-button').on('click', function() {
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
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_search_products',
                nonce: podManagerAdmin.nonce.search_products,
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

    $('.pod-modal-close').on('click', function() {
        $modal.hide();
        currentProduct = null;
    });

    // Design Controls
    $('#pod-design-upload').on('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('action', 'pod_upload_image');
        formData.append('nonce', podManagerAdmin.nonce.upload_image);
        formData.append('image', file);

        $.ajax({
            url: ajaxurl,
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
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pod_create_product',
                nonce: podManagerAdmin.nonce.create_product,
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
});
