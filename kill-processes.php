<?php
// Get all running PHP processes
$output = array();
exec('ps aux | grep php', $output);

echo "Looking for PHP processes...\n";
foreach ($output as $line) {
    if (strpos($line, 'print-on-demand-manager') !== false || 
        strpos($line, 'pod_printify') !== false || 
        strpos($line, 'wp-cron.php') !== false) {
        
        // Extract PID
        $parts = preg_split('/\s+/', trim($line));
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $pid = $parts[1];
            echo "Killing process {$pid}: {$line}\n";
            exec("kill -9 {$pid}");
        }
    }
}

echo "Process cleanup complete!\n";
