<?php

declare(strict_types=1);

namespace App\Enums;

enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';

    /** Name of the plain (unencrypted) cookie the inline head script and middleware read. */
    public const string COOKIE = 'theme';
}
