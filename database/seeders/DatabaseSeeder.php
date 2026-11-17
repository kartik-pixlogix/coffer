<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use Concerns\DemoOnly;

    /**
     * Seed the application's database with the design's demo workspace (spec §24).
     * Model events stay on: BelongsToCompany stamps company_id through them.
     */
    public function run(): void
    {
        $this->refuseInProduction();

        $this->call([DemoWorkspaceSeeder::class, DemoBooksSeeder::class, DemoVaultSeeder::class]);
    }
}
