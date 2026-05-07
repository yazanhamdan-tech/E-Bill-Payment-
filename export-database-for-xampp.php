<?php

/**
 * Export SQLite Database to MySQL Format for XAMPP
 * 
 * This script exports the SQLite database to MySQL-compatible SQL format
 * that can be imported into phpMyAdmin in XAMPP.
 * 
 * Usage: php export-database-for-xampp.php
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$dbPath = database_path('database.sqlite');

if (!file_exists($dbPath)) {
    die("Error: SQLite database file not found at: {$dbPath}\n");
}

// Connect to SQLite
$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$outputFile = __DIR__ . '/database_export_for_xampp.sql';
$output = fopen($outputFile, 'w');

// Write SQL header
fwrite($output, "-- eBill Payment Platform Database Export for XAMPP/MySQL\n");
fwrite($output, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($output, "-- \n");
fwrite($output, "-- Instructions:\n");
fwrite($output, "-- 1. Open phpMyAdmin in XAMPP (http://localhost/phpmyadmin)\n");
fwrite($output, "-- 2. Create a new database (e.g., 'ebill_payment_platform')\n");
fwrite($output, "-- 3. Select the database\n");
fwrite($output, "-- 4. Go to 'Import' tab\n");
fwrite($output, "-- 5. Choose this file and click 'Go'\n");
fwrite($output, "-- \n\n");
fwrite($output, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
fwrite($output, "START TRANSACTION;\n");
fwrite($output, "SET time_zone = \"+00:00\";\n\n");

// Get all tables
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    echo "Exporting table: {$table}...\n";
    
    // Get table structure
    $createTable = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
    
    if (!$createTable) {
        continue;
    }
    
    // Convert SQLite CREATE TABLE to MySQL
    $mysqlCreate = convertSqliteToMysql($createTable, $table);
    
    fwrite($output, "-- Table structure for table `{$table}`\n");
    fwrite($output, "DROP TABLE IF EXISTS `{$table}`;\n");
    fwrite($output, $mysqlCreate . ";\n\n");
    
    // Get table data
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($rows) > 0) {
        fwrite($output, "-- Dumping data for table `{$table}`\n");
        fwrite($output, "LOCK TABLES `{$table}` WRITE;\n");
        
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_map(function($value) use ($pdo) {
                if ($value === null) {
                    return 'NULL';
                }
                return $pdo->quote($value);
            }, array_values($row));
            
            $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
            fwrite($output, $sql);
        }
        
        fwrite($output, "UNLOCK TABLES;\n\n");
    }
}

fwrite($output, "COMMIT;\n");

fclose($output);

echo "\n✓ Database exported successfully!\n";
echo "Output file: {$outputFile}\n";
echo "\nNext steps:\n";
echo "1. Open phpMyAdmin: http://localhost/phpmyadmin\n";
echo "2. Create a new database (e.g., 'ebill_payment_platform')\n";
echo "3. Select the database\n";
echo "4. Click 'Import' tab\n";
echo "5. Choose file: {$outputFile}\n";
echo "6. Click 'Go' to import\n\n";

function convertSqliteToMysql($sqliteSql, $tableName) {
    // Basic conversion from SQLite to MySQL syntax
    $mysql = $sqliteSql;
    
    // Remove SQLite-specific syntax
    $mysql = preg_replace('/INTEGER PRIMARY KEY AUTOINCREMENT/i', 'INT AUTO_INCREMENT PRIMARY KEY', $mysql);
    $mysql = preg_replace('/INTEGER/i', 'INT', $mysql);
    $mysql = preg_replace('/TEXT/i', 'TEXT', $mysql);
    $mysql = preg_replace('/BLOB/i', 'BLOB', $mysql);
    $mysql = preg_replace('/REAL/i', 'DOUBLE', $mysql);
    $mysql = preg_replace('/NUMERIC/i', 'DECIMAL', $mysql);
    
    // Convert foreign key constraints
    $mysql = preg_replace('/FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s+(\w+)\s*\(([^)]+)\)\s*ON DELETE\s+(CASCADE|SET NULL|RESTRICT)/i', 
        'FOREIGN KEY ($1) REFERENCES $2($3) ON DELETE $4', $mysql);
    
    // Add ENGINE and CHARSET
    if (strpos($mysql, 'ENGINE') === false) {
        $mysql = rtrim($mysql, ';') . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
    
    return $mysql;
}

