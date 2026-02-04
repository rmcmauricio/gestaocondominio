<?php
$line = '[23-Jan-2026 12:06:45 UTC] PHP Fatal error: Uncaught PDOException: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'i.metadata\' in \'field list\' in C:\xampp\htdocs\predio\app\Controllers\SuperAdminController.php:397';

if (preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4}) (\d{2}:\d{2}:\d{2}) UTC\] (.+)$/', $line, $matches)) {
    echo "Match found!\n";
    echo "Date: " . $matches[1] . "\n";
    echo "Time: " . $matches[2] . "\n";
    echo "Message: " . substr($matches[3], 0, 50) . "...\n";
} else {
    echo "No match\n";
}
