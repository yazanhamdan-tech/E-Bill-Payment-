<?php

/**
 * Fix User Permissions Script
 * This script ensures all users have the correct permissions based on their roles
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

echo "Fixing User Permissions...\n";
echo str_repeat("=", 50) . "\n\n";

// Ensure permissions exist
$permissions = [
    'view invoices',
    'create invoices',
    'edit invoices',
    'delete invoices',
    'pay invoices',
    'download invoices',
    'archive invoices',
];

foreach ($permissions as $permissionName) {
    Permission::firstOrCreate(['name' => $permissionName]);
    echo "✓ Permission: {$permissionName}\n";
}

echo "\n";

// Get all roles
$adminRole = Role::firstOrCreate(['name' => 'admin']);
$providerRole = Role::firstOrCreate(['name' => 'service_provider']);
$customerRole = Role::firstOrCreate(['name' => 'customer']);
$supportRole = Role::firstOrCreate(['name' => 'support_agent']);

// Assign permissions to roles
$adminRole->syncPermissions(Permission::all());
echo "✓ Admin role has all permissions\n";

$providerRole->syncPermissions([
    'view invoices',
    'create invoices',
    'edit invoices',
    'delete invoices',
    'view payments',
]);
echo "✓ Service provider role has invoice permissions\n";

$customerRole->syncPermissions([
    'view invoices',
    'pay invoices',
    'download invoices',
    'view payments',
]);
echo "✓ Customer role has invoice permissions\n";

echo "\n";

// Fix existing users
$users = User::with('roles')->get();
$fixed = 0;

foreach ($users as $user) {
    $needsFix = false;
    
    if ($user->hasRole('admin')) {
        // Admin should have all permissions
        if (!$user->hasPermissionTo('create invoices')) {
            $user->givePermissionTo('create invoices');
            $needsFix = true;
        }
    } elseif ($user->hasRole('service_provider')) {
        // Service provider should have create invoices permission
        if (!$user->hasPermissionTo('create invoices')) {
            $user->givePermissionTo('create invoices');
            $needsFix = true;
        }
    }
    
    if ($needsFix) {
        $fixed++;
        echo "✓ Fixed permissions for: {$user->name} ({$user->email})\n";
    }
}

echo "\n";
echo str_repeat("=", 50) . "\n";
echo "✓ Fixed permissions for {$fixed} users\n";
echo "✓ All roles have correct permissions\n";
echo "\nDone!\n";

