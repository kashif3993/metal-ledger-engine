<?php

declare(strict_types=1);

namespace App\Exceptions\MetalLedger;

use App\Services\MetalLedger\RateBookService;
use RuntimeException;

/**
 * The Day 1 design note's "quote validity window": a locked rate only holds
 * for N minutes (default 15, owner-configurable via the
 * `quote_validity_minutes` setting). Past that, the brief's answer is that
 * the line "must be re-quoted" — not silently kept, not auto-confirmed. This
 * can never be bypassed by a confirmation flag; the only way past it is a
 * fresh call to {@see RateBookService::quote()}.
 */
final class QuoteExpiredException extends RuntimeException
{
    public function __construct(
        public readonly float $minutesElapsed,
        public readonly int $validityMinutes,
    ) {
        parent::__construct(sprintf(
            'Quote is %.1f minutes old, past the %d-minute validity window — it must be re-quoted, not finalized as-is.',
            $minutesElapsed,
            $validityMinutes,
        ));
    }
}
