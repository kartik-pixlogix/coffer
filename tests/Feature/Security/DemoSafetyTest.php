<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBooksSeeder;
use Database\Seeders\DemoVaultSeeder;
use Database\Seeders\DemoWorkspaceSeeder;

/*
| Demo data never reaches production (D93): it carries known passwords, the shared demo
| 2FA key and a platform admin. Plans come from their migration, so production needs no seeder.
*/

test('the demo seeders refuse to run in production', function (string $seeder) {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app($seeder)->run())->toThrow(RuntimeException::class, 'do not run in production');
    expect(User::query()->count())->toBe(0);
})->with([DatabaseSeeder::class, DemoWorkspaceSeeder::class, DemoBooksSeeder::class, DemoVaultSeeder::class]);

test('the demo 2FA helper refuses in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('coffer:demo-2fa')->assertFailed();
});
