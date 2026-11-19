<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Internal\SetUpUnboundGroup;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Creates our own workspace, Unbound Group of Companies (D145), with its three companies,
 * partners, equity and a login per partner, plus sample books unless --structure-only.
 * Runs once; it prints each new login's password a single time.
 */
final class SetUpUnboundGroupCommand extends Command
{
    protected $signature = 'coffer:unbound
        {--domain=unbound.test : Email domain for the partner logins}
        {--structure-only : Companies, partners and logins only, no sample books}';

    protected $description = 'Set up the internal Unbound Group workspace (free, unlimited) with sample data';

    public function handle(SetUpUnboundGroup $setUp): int
    {
        if (Workspace::query()->where('name', SetUpUnboundGroup::WORKSPACE)->exists()) {
            $this->components->error(SetUpUnboundGroup::WORKSPACE.' is already set up.');

            return self::FAILURE;
        }

        $logins = $setUp->handle(ltrim((string) $this->option('domain'), '@'), withSample: ! $this->option('structure-only'));

        $this->components->info(SetUpUnboundGroup::WORKSPACE.' is ready: Unbound Solutions, Unbound Services and Unbound Innovation.');
        $this->table(['Name', 'Email', 'Password'], array_map(fn (array $login) => [
            $login['name'],
            $login['email'],
            $login['password'] ?? '(existing login, password unchanged)',
        ], $logins));
        $this->components->warn('Save these passwords now: they are not shown again. Admins (Kartik, Karan, Shyam) set up 2FA from Profile.');

        return self::SUCCESS;
    }
}
