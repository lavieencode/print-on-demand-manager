<?php
error_log("Starting emergency process kill...");

// Get all running processes
$output = shell_exec('ps aux | grep php');
$lines = explode("\n", $output);

foreach ($lines as $line) {
    if (strpos($line, 'print-on-demand-manager') !== false || 
        strpos($line, 'wp-cron.php') !== false ||
        strpos($line, 'printify') !== false) {
        
        preg_match('/^\S+\s+(\d+)/', $line, $matches);
        if (isset($matches[1])) {
            $pid = $matches[1];
            error_log("Killing process $pid: $line");
            posix_kill($pid, SIGKILL);
        }
    }
}

// Also try to kill via Apache
if (function_exists('apache_reset_timeout')) {
    apache_reset_timeout();
}

// Force PHP to stop
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Delete all options and transients
require_once(dirname(dirname(dirname(__DIR__))) . '/wp-load.php');
global $wpdb;

$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%pod_printify%'");
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_pod_printify%'");

error_log("Emergency process kill complete");
