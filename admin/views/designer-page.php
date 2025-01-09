<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>

<div class="wrap">
    <h1>Product Designer</h1>
    
    <div class="pod-designer-container">
        <div class="pod-designer-sidebar">
            <div class="pod-design-controls">
                <h2>Design Controls</h2>
                <div class="pod-control-group">
                    <label for="pod-design-upload">Upload Design</label>
                    <input type="file" id="pod-design-upload" accept="image/*">
                </div>
                
                <div class="pod-control-group">
                    <label for="pod-design-position">Position</label>
                    <select id="pod-design-position">
                        <option value="center">Center</option>
                        <option value="top">Top</option>
                        <option value="bottom">Bottom</option>
                        <option value="left">Left</option>
                        <option value="right">Right</option>
                    </select>
                </div>
                
                <div class="pod-control-group">
                    <label for="pod-design-size">Size</label>
                    <input type="range" id="pod-design-size" min="10" max="100" value="50">
                    <span class="pod-size-value">50%</span>
                </div>
            </div>
            
            <div class="pod-product-details">
                <h2>Product Details</h2>
                <div class="pod-control-group">
                    <label for="pod-product-title">Title</label>
                    <input type="text" id="pod-product-title" class="regular-text">
                </div>
                
                <div class="pod-control-group">
                    <label for="pod-product-description">Description</label>
                    <textarea id="pod-product-description" rows="4"></textarea>
                </div>
                
                <div class="pod-control-group">
                    <label for="pod-product-price">Price</label>
                    <input type="number" id="pod-product-price" step="0.01" min="0">
                </div>
                
                <button class="button button-primary pod-create-product">
                    Create Product
                </button>
                
                <div class="pod-create-status notice notice-info" style="display: none;">
                    <p>Creating product...</p>
                </div>
            </div>
        </div>
        
        <div class="pod-designer-preview">
            <h2>Preview</h2>
            <div class="pod-preview-container">
                <img src="" alt="Product Preview" id="pod-preview-image" style="display: none;">
                <div class="pod-preview-placeholder">
                    Select a product to start designing
                </div>
            </div>
        </div>
    </div>
</div>
