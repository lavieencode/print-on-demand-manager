<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

$printify = new POD_Printify_Platform();
$api_key = $printify->get_api_key();
$is_updating = $printify->is_cache_updating();
$progress = $printify->get_cache_progress();
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
            <div class="pod-cache-info">
                <?php if ($last_update): ?>
                    <div class="pod-info-item">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        Last Update: <?php echo esc_html(human_time_diff(strtotime($last_update), current_time('timestamp'))); ?> ago
                    </div>
                <?php endif; ?>
                
                <div class="pod-info-item">
                    <span class="dashicons dashicons-info"></span>
                    Cache updates improve performance by storing product data locally
                </div>
            </div>

            <div class="pod-cache-status">
                <div id="pod-cache-progress" style="<?php echo $is_updating ? '' : 'display: none;'; ?>">
                    <div class="pod-progress">
                        <div class="pod-progress-bar">
                            <div class="pod-progress-fill" style="width: <?php echo esc_attr(($progress['total'] > 0) ? ($progress['current'] / $progress['total'] * 100) : 0); ?>%"></div>
                        </div>
                        <div class="pod-progress-stats">
                            <span class="pod-progress-percentage">
                                <?php echo esc_html(($progress['total'] > 0) ? round(($progress['current'] / $progress['total'] * 100)) : 0); ?>%
                            </span>
                            <span class="pod-progress-numbers">
                                <?php echo esc_html($progress['current']); ?> / <?php echo esc_html($progress['total']); ?> products
                            </span>
                        </div>
                    </div>
                    <button type="button" class="button pod-cancel-cache">
                        <span class="dashicons dashicons-dismiss"></span> Cancel Update
                    </button>
                </div>

                <div id="pod-cache-controls" style="<?php echo $is_updating ? 'display: none;' : ''; ?>">
                    <button type="button" class="button button-primary pod-refresh-cache">
                        <span class="dashicons dashicons-update"></span> Update Cache Now
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
