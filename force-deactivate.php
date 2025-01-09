<?php
require_once(dirname(dirname(dirname(__DIR__))) . '/wp-load.php');

echo "Forcing plugin deactivation...\n";

// Call the deactivation function directly
pod_deactivate();

// Also call the full deactivation function
pod_manager_deactivate();

// Force remove the plugin from active plugins list
$active_plugins = get_option('active_plugins');
$plugin_file = 'print-on-demand-manager/print-on-demand-manager.php';
$active_plugins = array_diff($active_plugins, array($plugin_file));
update_option('active_plugins', $active_plugins);

echo "Plugin deactivated!\n";
