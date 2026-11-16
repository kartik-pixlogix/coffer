<?php

declare(strict_types=1);

namespace App\Ledger\Exceptions;

/** Spec §4.1: every entry balances; anything else is refused (and means a bug upstream). */
final class UnbalancedEntryException extends LedgerException {}
