<?php
require_once(dirname(dirname(dirname(__DIR__))) . '/wp-load.php');

echo "Current time: " . current_time('mysql') . "\n";
echo "\nChecking pod_printify_cache_products specifically:\n";
$next_scheduled = wp_next_scheduled('pod_printify_cache_products');
echo "Next scheduled time: " . ($next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled') . "\n";

echo "\nChecking cache update status:\n";
echo "pod_printify_cache_updating option: " . (get_option('pod_printify_cache_updating') ? 'true' : 'false') . "\n";
echo "Last cache update: " . get_option('pod_printify_last_cache_update', 'never') . "\n";
echo "Cache progress: " . print_r(get_option('pod_printify_cache_progress', array()), true) . "\n";
