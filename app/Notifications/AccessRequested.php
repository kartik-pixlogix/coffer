<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AccessRequest;
use App\Models\Company;
use App\Models\User;
use App\Support\Permissions\Catalog;

/** Tells a company's Admins someone asked for a page they can't open (bell and email). */
final class AccessRequested extends CofferNotification
{
    public static function for(AccessRequest $accessRequest, User $requester, Company $company): self
    {
        $page = Catalog::PAGES[$accessRequest->page]['label'];
        $reason = $accessRequest->reason !== null ? " · “{$accessRequest->reason}”" : '';

        return new self(
            $company->id,
            "{$requester->name} asked to open {$page}",
            "In {$company->name}{$reason}. Nothing changes until you update their role or the permission matrix.",
            route('settings.roles'),
        );
    }

    public function event(): string
    {
        return 'access.requested';
    }

    public function icon(): string
    {
        return 'user-plus';
    }

    public function linkLabel(): string
    {
        return 'Open roles and permissions';
    }

    public function mailAction(): string
    {
        return 'Open roles and permissions';
    }
}
