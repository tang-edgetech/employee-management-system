<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Roles, permissions and the settings row are
     * created by migrations, so a client install needs no seeding. Local and test
     * databases get the demo company.
     */
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoCompanySeeder::class);
        }
    }
}
