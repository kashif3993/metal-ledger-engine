<?php

declare(strict_types=1);

namespace App\Exceptions\MetalLedger;

use App\Services\MetalLedger\RateBookService;
use RuntimeException;

/**
 * The Day 1 design note's "rate-change tolerance": if the live rate has
 * moved more than a set % (default 0.5%, owner-configurable via the
 * `rate_change_tolerance_percent` setting) since the line was quoted, the
 * brief's answer is that "the cashier is forced to confirm before printing"
 * — not blocked outright. Unlike {@see QuoteExpiredException}, this one CAN
 * be passed by calling
 * {@see RateBookService::assertQuoteStillValid()}
 * again with `confirmed: true`, once the cashier has actually seen and
 * accepted the new figure.
 */
final class RateDriftedException extends RuntimeException
{
    public function __construct(
        public readonly float $quotedRatePerGram,
        public readonly float $currentRatePerGram,
        public readonly float $driftPercent,
        public readonly float $tolerancePercent,
    ) {
        parent::__construct(sprintf(
            'Rate moved %.2f%% since this was quoted (Rs %.2f/g -> Rs %.2f/g), past the %.2f%% tolerance — the cashier must confirm before this can be finalized.',
            $driftPercent,
            $quotedRatePerGram,
            $currentRatePerGram,
            $tolerancePercent,
        ));
    }
}
