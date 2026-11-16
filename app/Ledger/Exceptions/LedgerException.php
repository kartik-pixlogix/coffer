<?php

declare(strict_types=1);

namespace App\Ledger\Exceptions;

use DomainException;

/** A posting the ledger refuses. The message is written for people (it can reach the UI). */
class LedgerException extends DomainException {}
