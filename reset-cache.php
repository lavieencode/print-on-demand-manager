<?php
require_once(dirname(dirname(dirname(__DIR__))) . '/wp-load.php');

echo "Starting cache reset...\n";

// Delete all cache-related options
$options_to_delete = array(
    'pod_printify_cache_updating',
    'pod_printify_cache_progress',
    'pod_printify_all_products_detailed',
    'pod_printify_all_products_detailed_temp',
    'pod_printify_last_cache_update',
    'pod_printify_products_cache',
    'pod_printify_products_cache_time'
);

foreach ($options_to_delete as $option) {
    $result = delete_option($option);
    echo "Deleting option {$option}: " . ($result ? 'success' : 'not found') . "\n";
}

// Clear any scheduled cron jobs
$timestamp = wp_next_scheduled('pod_printify_cache_products');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'pod_printify_cache_products');
    echo "Unscheduled cache update cron job at timestamp: " . date('Y-m-d H:i:s', $timestamp) . "\n";
}
wp_clear_scheduled_hook('pod_printify_cache_products');
echo "Cleared all scheduled cache update hooks\n";

// Clear any transients
delete_transient('pod_printify_all_products_detailed');
echo "Cleared cache-related transients\n";

echo "Cache reset complete!\n";
