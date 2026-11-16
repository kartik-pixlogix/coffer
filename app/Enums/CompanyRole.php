<?php

declare(strict_types=1);

namespace App\Enums;

/** A user's role in one company (spec §2). */
enum CompanyRole: string
{
    case Admin = 'admin';
    case Partner = 'partner';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** "an Admin", "a Partner" … for copy like "Kabir Shah is now a Partner". */
    public function withArticle(): string
    {
        return ($this === self::Admin ? 'an ' : 'a ').$this->label();
    }

    /**
     * Roles a partner record can hold (wizard and Settings › Partners).
     *
     * @return list<self>
     */
    public static function partnerRoles(): array
    {
        return [self::Admin, self::Partner];
    }
}
