<?php
$log = ini_get('error_log');
echo "Log: $log<br><br>";
if (file_exists($log)) {
    echo "<pre>" . file_get_contents($log) . "</pre>";
}
?>