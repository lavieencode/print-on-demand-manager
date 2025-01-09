<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

$printify = new POD_Printify_Platform();
$api_key = $printify->get_api_key();
$shop_id = get_option('pod_printify_shop_id');
$is_updating = $printify->is_cache_updating();
$progress = $printify->get_cache_progress();
$last_update = get_option('pod_printify_last_cache_update');
?>

<div class="wrap">
    <h1>Print on Demand Manager Settings</h1>

    <form method="post" action="options.php">
        <?php settings_fields('pod_manager_settings'); ?>
        <?php do_settings_sections('pod_manager_settings'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="pod_printify_api_key">Printify API Key</label>
                </th>
                <td>
                    <input type="password" 
                           id="pod_printify_api_key" 
                           name="pod_printify_api_key" 
                           value="<?php echo esc_attr($api_key); ?>" 
                           class="regular-text">
                    <p class="description">
                        Get your API key from the <a href="https://printify.com/app/api" target="_blank">Printify API settings</a>.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="pod_printify_shop_id">Shop ID</label>
                </th>
                <td>
                    <input type="text" 
                           id="pod_printify_shop_id" 
                           name="pod_printify_shop_id" 
                           value="<?php echo esc_attr($shop_id); ?>" 
                           class="regular-text">
                    <p class="description">
                        Your Printify shop ID.
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <hr>

    <h2>Product Cache Management</h2>
    <p>
        The plugin caches product data from Printify to improve performance. 
        You can manually refresh the cache here.
    </p>

    <div class="pod-cache-status">
        <?php if ($last_update): ?>
            <p>Last cache update: <?php echo esc_html(human_time_diff(strtotime($last_update), current_time('timestamp'))); ?> ago</p>
        <?php endif; ?>

        <div id="pod-cache-progress" style="<?php echo $is_updating ? '' : 'display: none;'; ?>">
            <div class="pod-progress-bar">
                <div class="pod-progress-fill" style="width: <?php echo esc_attr(($progress['total'] > 0) ? ($progress['current'] / $progress['total'] * 100) : 0); ?>%"></div>
            </div>
            <p class="pod-progress-text">
                Processing <?php echo esc_html($progress['current']); ?> of <?php echo esc_html($progress['total']); ?> products...
            </p>
            <button type="button" class="button pod-cancel-cache">Cancel Update</button>
        </div>

        <div id="pod-cache-controls" style="<?php echo $is_updating ? 'display: none;' : ''; ?>">
            <button type="button" class="button button-primary pod-refresh-cache">Refresh Cache</button>
        </div>
    </div>
</div>
