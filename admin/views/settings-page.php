<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

$printify = new POD_Printify_Platform();
$api_key = $printify->get_api_key();
$is_updating = get_option('pod_printify_cache_updating', false); // Use option directly instead of method call
$progress = get_option('pod_printify_cache_progress', array(
    'total_blueprints' => 0,
    'current_blueprint' => 0,
    'total_providers' => 0,
    'current_provider' => 0,
    'status' => 'idle',
    'phase' => '',
    'current_item' => ''
));
$last_update = get_option('pod_printify_last_cache_update');
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Print on Demand Manager</h1>
    
    <div class="pod-settings-grid">
        <!-- API Configuration -->
        <div class="pod-settings-card">
            <h2><span class="dashicons dashicons-admin-generic"></span> API Configuration</h2>
            <form method="post" action="options.php">
                <?php settings_fields('pod_manager_settings'); ?>
                <?php do_settings_sections('pod_manager_settings'); ?>

                <div class="pod-form-group">
                    <label for="pod_printify_api_key">Printify API Key</label>
                    <div class="pod-input-group">
                        <input type="password" 
                               id="pod_printify_api_key" 
                               name="pod_printify_api_key" 
                               value="<?php echo esc_attr($api_key); ?>" 
                               class="regular-text">
                        <button type="button" class="button pod-toggle-password">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                    <p class="description">
                        Get your API key from the <a href="https://printify.com/app/api" target="_blank">Printify API settings</a>
                    </p>
                </div>

                <div class="pod-form-actions">
                    <?php submit_button('Save API Key', 'primary', 'submit', false); ?>
                    <?php wp_nonce_field('pod_verify_connection', 'pod_verify_connection_nonce'); ?>
                    <button type="button" class="button button-secondary pod-verify-connection">
                        <span class="dashicons dashicons-yes-alt"></span> Verify Connection
                    </button>
                </div>

                <div class="pod-connection-notice"></div>

            </form>
        </div>

        <!-- Cache Management -->
        <div class="pod-settings-card">
            <h2><span class="dashicons dashicons-database"></span> Cache Management</h2>
            <?php 
            $last_update = get_option('pod_printify_last_cache_update');
            $is_updating = get_option('pod_printify_cache_updating', false);
            ?>
            
            <div class="pod-cache-status">
                <div class="pod-info-message">
                    <?php if ($last_update): ?>
                        <p>Last successful update: <?php echo human_time_diff(strtotime($last_update), current_time('timestamp')); ?> ago</p>
                    <?php else: ?>
                        <p>Cache has not been initialized yet. Click "Update Cache Now" to build the cache.</p>
                    <?php endif; ?>
                </div>

                <div id="pod-cache-progress" style="display: none;">
                    <div class="pod-progress">
                        <div class="pod-progress-bar">
                            <div class="pod-progress-fill" style="width: 0%"></div>
                        </div>
                        <div class="pod-progress-stats">
                            <span class="pod-progress-percentage">0%</span>
                            <span class="pod-progress-numbers">Initializing...</span>
                        </div>
                        <div class="pod-progress-phase">
                            <span class="pod-phase-label"></span>: 
                            <span class="pod-phase-item"></span>
                        </div>
                    </div>
                    <button type="button" class="button pod-cancel-cache">
                        <span class="dashicons dashicons-dismiss"></span> Cancel Update
                    </button>
                </div>

                <div class="pod-cache-controls" id="pod-cache-controls">
                    <button class="button button-primary pod-refresh-cache">
                        <span class="dashicons dashicons-update"></span> Update Cache Now
                    </button>
                    <button class="button pod-debug-cache">
                        <span class="dashicons dashicons-admin-tools"></span> Reset Cache Flags
                    </button>
                    <button class="button pod-view-cache">
                        <span class="dashicons dashicons-visibility"></span> View Cache Data
                    </button>
                </div>

                <div id="pod-cache-data" style="display: none; margin-top: 20px;">
                    <h3>Cache Data</h3>
                    <pre class="pod-cache-data-content" style="background: #f8f9fa; padding: 15px; border-radius: 4px; overflow: auto; max-height: 400px;"></pre>
                </div>
            </div>
        </div>
    </div>
</div>
