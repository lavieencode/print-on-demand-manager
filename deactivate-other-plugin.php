<?php
require_once(dirname(dirname(dirname(__DIR__))) . '/wp-load.php');

echo "Deactivating Printify for WooCommerce plugin...\n";

// Deactivate the plugin
deactivate_plugins('printify-for-woocommerce/printify.php');

// Remove it from active plugins list
$active_plugins = get_option('active_plugins');
$plugin_file = 'printify-for-woocommerce/printify.php';
if (($key = array_search($plugin_file, $active_plugins)) !== false) {
    unset($active_plugins[$key]);
    update_option('active_plugins', $active_plugins);
    echo "Successfully deactivated Printify for WooCommerce!\n";
} else {
    echo "Plugin was not active.\n";
}
