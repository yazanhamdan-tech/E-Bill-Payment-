<?php

/**
 * Simple Database Export Script for XAMPP/MySQL
 * Exports SQLite database to MySQL-compatible SQL file
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$dbPath = database_path('database.sqlite');

if (!file_exists($dbPath)) {
    die("Error: SQLite database not found at: {$dbPath}\n");
}

$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$outputFile = __DIR__ . '/ebill_database_export.sql';
$fh = fopen($outputFile, 'w');

// Write header
fwrite($fh, "-- eBill Payment Platform - MySQL Export\n");
fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
fwrite($fh, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
fwrite($fh, "SET AUTOCOMMIT = 0;\n");
fwrite($fh, "START TRANSACTION;\n");
fwrite($fh, "SET time_zone = \"+00:00\";\n\n");

// Get all tables
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($tables) . " tables to export...\n\n";

foreach ($tables as $table) {
    echo "Processing table: {$table}...\n";
    
    // Get table info
    $columns = $pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        continue;
    }
    
    // Create table structure
    fwrite($fh, "\n-- --------------------------------------------------------\n");
    fwrite($fh, "-- Table structure for table `{$table}`\n");
    fwrite($fh, "-- --------------------------------------------------------\n\n");
    fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
    fwrite($fh, "CREATE TABLE `{$table}` (\n");
    
    $columnDefs = [];
    $primaryKeys = [];
    
    foreach ($columns as $col) {
        $name = $col['name'];
        $type = strtoupper($col['type']);
        $notnull = $col['notnull'] ? 'NOT NULL' : 'NULL';
        $default = $col['dflt_value'] !== null ? "DEFAULT " . ($col['dflt_value'] === '' ? "''" : $col['dflt_value']) : '';
        $pk = $col['pk'] ? 1 : 0;
        
        // Convert SQLite types to MySQL
        if (stripos($type, 'INT') !== false) {
            $mysqlType = 'INT';
            if ($pk && stripos($type, 'AUTO') !== false) {
                $mysqlType = 'INT AUTO_INCREMENT';
            }
        } elseif (stripos($type, 'TEXT') !== false) {
            $mysqlType = 'TEXT';
        } elseif (stripos($type, 'VARCHAR') !== false || stripos($type, 'CHAR') !== false) {
            $mysqlType = $type;
        } elseif (stripos($type, 'DECIMAL') !== false || stripos($type, 'NUMERIC') !== false) {
            $mysqlType = $type;
        } elseif (stripos($type, 'REAL') !== false || stripos($type, 'FLOAT') !== false || stripos($type, 'DOUBLE') !== false) {
            $mysqlType = 'DOUBLE';
        } elseif (stripos($type, 'BLOB') !== false) {
            $mysqlType = 'BLOB';
        } elseif (stripos($type, 'BOOLEAN') !== false || stripos($type, 'BOOL') !== false) {
            $mysqlType = 'TINYINT(1)';
        } elseif (stripos($type, 'DATE') !== false) {
            $mysqlType = 'DATE';
        } elseif (stripos($type, 'TIME') !== false) {
            $mysqlType = 'TIME';
        } elseif (stripos($type, 'DATETIME') !== false || stripos($type, 'TIMESTAMP') !== false) {
            $mysqlType = 'TIMESTAMP';
        } else {
            $mysqlType = 'TEXT';
        }
        
        $def = "  `{$name}` {$mysqlType} {$notnull}";
        if ($default) {
            $def .= " {$default}";
        }
        
        $columnDefs[] = $def;
        
        if ($pk) {
            $primaryKeys[] = $name;
        }
    }
    
    // Add primary key
    if (!empty($primaryKeys)) {
        $columnDefs[] = "  PRIMARY KEY (`" . implode('`, `', $primaryKeys) . "`)";
    }
    
    fwrite($fh, implode(",\n", $columnDefs) . "\n");
    fwrite($fh, ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n");
    
    // Export data
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($rows) > 0) {
        fwrite($fh, "-- Dumping data for table `{$table}`\n\n");
        fwrite($fh, "LOCK TABLES `{$table}` WRITE;\n");
        
        $insertCount = 0;
        foreach ($rows as $row) {
            $cols = array_keys($row);
            $vals = array_map(function($val) use ($pdo) {
                if ($val === null) {
                    return 'NULL';
                }
                // Escape and quote
                return $pdo->quote($val);
            }, array_values($row));
            
            fwrite($fh, "INSERT INTO `{$table}` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $vals) . ");\n");
            $insertCount++;
        }
        
        fwrite($fh, "UNLOCK TABLES;\n\n");
        echo "  ✓ Exported {$insertCount} rows\n";
    } else {
        echo "  ✓ Table structure exported (no data)\n";
    }
}

fwrite($fh, "\nCOMMIT;\n");

fclose($fh);

echo "\n" . str_repeat("=", 50) . "\n";
echo "✓ Export completed successfully!\n";
echo "✓ Output file: {$outputFile}\n";
echo "\nNext steps to import into XAMPP:\n";
echo "1. Open phpMyAdmin: http://localhost/phpmyadmin\n";
echo "2. Create a new database (e.g., 'ebill_payment_platform')\n";
echo "3. Select the database\n";
echo "4. Click 'Import' tab\n";
echo "5. Choose file: {$outputFile}\n";
echo "6. Click 'Go' button\n";
echo str_repeat("=", 50) . "\n\n";

