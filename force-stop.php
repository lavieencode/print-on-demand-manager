<?php
require_once(dirname(dirname(dirname(__DIR__))) . '/wp-load.php');

echo "Setting force stop flag...\n";

// Set the force stop flag
update_option('pod_printify_force_stop', true);

// Also clean up any existing flags
delete_option('pod_printify_cache_updating');
delete_option('pod_printify_cache_progress');
delete_option('pod_printify_last_cache_update');

echo "Force stop flag set. Any running cache updates should stop on their next iteration.\n";
