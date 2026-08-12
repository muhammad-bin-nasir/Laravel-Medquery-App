<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ChatApiTestSeeder::class,
            PlanSeeder::class,
            SiteUserDefaultsSeeder::class,
            AdminSeeder::class,
            NormalUserSeeder::class,
            TurnstileSettingsSeeder::class,
        ]);
    }
}
