<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the design's demo workspace (spec §24).
     * Model events stay on: BelongsToCompany stamps company_id through them.
     */
    public function run(): void
    {
        $this->call(DemoWorkspaceSeeder::class);
    }
}
