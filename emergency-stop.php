<?php
require_once(dirname(dirname(dirname(__DIR__))) . '/wp-load.php');

echo "Emergency stopping all cache processes...\n";

// Delete all cache-related options
delete_option('pod_printify_cache_updating');
delete_option('pod_printify_cache_progress');
delete_option('pod_printify_last_cache_update');
delete_option('pod_printify_all_products_detailed');
delete_option('pod_printify_force_stop');

// Set transient that will be immediately available to all processes
set_transient('pod_printify_emergency_stop', true, 3600);

// Delete all transients related to our cache
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_pod_printify%'");
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_pod_printify%'");

echo "Emergency stop complete. All cache processes should stop immediately.\n";
