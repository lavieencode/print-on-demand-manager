<?php
require_once(dirname(dirname(dirname(__DIR__))) . '/wp-load.php');

// Disable WP Cron
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

// Set extremely short timeout
if (!defined('WP_HTTP_TIMEOUT')) {
    define('WP_HTTP_TIMEOUT', 5);
}

// Force debug mode to catch all errors
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

echo "WordPress configuration updated!\n";

// Force clear all caches
wp_cache_flush();
echo "Cache flushed!\n";

// Clear all scheduled cron events
$crons = _get_cron_array();
if (!empty($crons)) {
    foreach ($crons as $timestamp => $cron) {
        foreach ($cron as $hook => $events) {
            if (strpos($hook, 'pod_') !== false || strpos($hook, 'printify') !== false) {
                wp_clear_scheduled_hook($hook);
                echo "Cleared scheduled hook: {$hook}\n";
            }
        }
    }
}

echo "All plugin cron events cleared!\n";
