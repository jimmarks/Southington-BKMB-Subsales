<?php
// Test what fgetcsv returns for the problematic lines

$handle = fopen('/workspaces/Southington-BKMB-Subsales/subsales-users-teams-2026-01-06.csv', 'r');
$line_num = 0;

while (($row = fgetcsv($handle)) !== false) {
    $line_num++;
    
    // Lines 90-92 are the problematic ones
    if ($line_num >= 90 && $line_num <= 92) {
        echo "Line {$line_num}:\n";
        echo "  Full row: " . print_r($row, true);
        echo "  Column count: " . count($row) . "\n";
        echo "  row[0] (name): '{$row[0]}'\n";
        echo "  row[4] (teams): '{$row[4]}'\n";
        echo "  row[4] length: " . strlen($row[4]) . "\n";
        echo "  After explode by comma: " . print_r(explode(',', $row[4]), true);
        echo "\n";
    }
    
    if ($line_num > 92) break;
}

fclose($handle);
