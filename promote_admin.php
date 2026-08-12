<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

$admin = User::query()->where('email_normalized', 'admin@acme.test')->first();
if (!$admin) {
    echo "No admin@acme.test user found\n";
    exit(1);
}

$admin->role = 'super_admin';
$admin->save();

echo $admin->id . PHP_EOL;
