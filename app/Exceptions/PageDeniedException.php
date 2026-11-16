<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A direct visit to a page the user's role can't open (rendered as StateDenied). */
final class PageDeniedException extends HttpException
{
    public function __construct(public readonly ?string $page = null)
    {
        parent::__construct(403, 'This page is not available for your role.');
    }
}
