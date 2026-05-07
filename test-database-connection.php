<?php

/**
 * Test Database Connection Script
 * This script helps diagnose database connection issues
 */

echo "eBill Payment Platform - Database Connection Test\n";
echo str_repeat("=", 50) . "\n\n";

// Check if .env file exists
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    echo "❌ ERROR: .env file not found!\n";
    echo "   Location: {$envPath}\n";
    echo "   Please create a .env file from .env.example\n\n";
    exit(1);
}

echo "✓ .env file found\n";

// Parse .env file
$envVars = [];
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') === false) continue;
    list($key, $value) = explode('=', $line, 2);
    $envVars[trim($key)] = trim($value);
}

// Get database config
$connection = $envVars['DB_CONNECTION'] ?? 'mysql';
$host = $envVars['DB_HOST'] ?? '127.0.0.1';
$port = $envVars['DB_PORT'] ?? '3306';
$database = $envVars['DB_DATABASE'] ?? '';
$username = $envVars['DB_USERNAME'] ?? 'root';
$password = $envVars['DB_PASSWORD'] ?? '';

echo "\nDatabase Configuration:\n";
echo "  Connection: {$connection}\n";
echo "  Host: {$host}\n";
echo "  Port: {$port}\n";
echo "  Database: " . ($database ?: '(not set)') . "\n";
echo "  Username: {$username}\n";
echo "  Password: " . ($password ? '(set)' : '(empty)') . "\n\n";

if ($connection !== 'mysql') {
    echo "⚠ WARNING: DB_CONNECTION is set to '{$connection}', not 'mysql'\n";
    echo "   For XAMPP, you need: DB_CONNECTION=mysql\n\n";
}

if (empty($database)) {
    echo "❌ ERROR: DB_DATABASE is not set!\n";
    echo "   Please set DB_DATABASE=ebill_payment_platform in your .env file\n\n";
    exit(1);
}

// Test MySQL connection
echo "Testing MySQL connection...\n";

try {
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✓ Successfully connected to MySQL server\n\n";
    
    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '{$database}'");
    $dbExists = $stmt->rowCount() > 0;
    
    if ($dbExists) {
        echo "✓ Database '{$database}' exists\n\n";
        
        // Connect to the database
        $pdo->exec("USE `{$database}`");
        
        // List tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        echo "Tables in database:\n";
        if (count($tables) > 0) {
            foreach ($tables as $table) {
                $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                echo "  ✓ {$table} ({$count} rows)\n";
            }
        } else {
            echo "  ⚠ No tables found. Run: php artisan migrate\n";
        }
        
    } else {
        echo "❌ Database '{$database}' does NOT exist!\n\n";
        echo "To create it:\n";
        echo "1. Open phpMyAdmin: http://localhost/phpmyadmin\n";
        echo "2. Click 'New' in the left sidebar\n";
        echo "3. Database name: {$database}\n";
        echo "4. Collation: utf8mb4_unicode_ci\n";
        echo "5. Click 'Create'\n";
        echo "6. Then run: php artisan migrate\n\n";
    }
    
    // List all databases
    echo "\nAvailable databases on MySQL server:\n";
    $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($databases as $db) {
        if (!in_array($db, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
            echo "  - {$db}\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Connection failed!\n";
    echo "   Error: " . $e->getMessage() . "\n\n";
    
    echo "Troubleshooting:\n";
    echo "1. Make sure XAMPP MySQL is running\n";
    echo "2. Check if MySQL service is started in XAMPP Control Panel\n";
    echo "3. Verify DB_HOST, DB_PORT, DB_USERNAME, and DB_PASSWORD in .env\n";
    echo "4. Default XAMPP MySQL settings:\n";
    echo "   - Host: 127.0.0.1 or localhost\n";
    echo "   - Port: 3306\n";
    echo "   - Username: root\n";
    echo "   - Password: (usually empty)\n\n";
    
    exit(1);
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Test completed!\n";

