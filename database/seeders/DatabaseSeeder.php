<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo owner from spec §24. The full demo workspace arrives with Phase 2.
        User::factory()->create([
            'name' => 'Aarav Mehta',
            'email' => 'aarav@vertexlabs.in',
        ]);
    }
}
