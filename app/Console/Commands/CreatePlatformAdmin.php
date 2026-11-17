<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates (or resets the password of) a super-admin for /admin (spec §22, D85). Reads
 * --email / --name / --password, then PLATFORM_ADMIN_EMAIL / _NAME / _PASSWORD from the
 * environment, and asks for anything still missing when run interactively.
 */
final class CreatePlatformAdmin extends Command
{
    protected $signature = 'coffer:platform-admin
        {--email= : Email address (else PLATFORM_ADMIN_EMAIL)}
        {--name= : Display name (else PLATFORM_ADMIN_NAME)}
        {--password= : Password, at least 12 characters (else PLATFORM_ADMIN_PASSWORD)}';

    protected $description = 'Create or update a platform admin for the /admin panel';

    public function handle(): int
    {
        $email = $this->value('email') ?? text('Email', required: true);
        $name = $this->value('name') ?? ($this->input->isInteractive() ? text('Name', default: Str::before($email, '@'), required: true) : Str::before($email, '@'));
        $password = $this->value('password') ?? password('Password (12+ characters)', required: true);

        $validator = Validator::make(compact('email', 'name', 'password'), [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $admin = PlatformAdmin::query()->firstOrNew(['email' => Str::lower($email)]);
        $created = ! $admin->exists;
        $admin->fill(['name' => $name, 'password' => $password])->save();

        $this->components->info(($created ? 'Created' : 'Updated').' platform admin '.$admin->email.'. Sign in at '.url('/admin').'.');

        return self::SUCCESS;
    }

    private function value(string $option): ?string
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            $value = config('platform.admin.'.$option);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
