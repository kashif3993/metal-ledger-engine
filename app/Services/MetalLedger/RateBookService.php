<?php

declare(strict_types=1);

namespace App\Services\MetalLedger;

use App\Exceptions\MetalLedger\QuoteExpiredException;
use App\Exceptions\MetalLedger\RateDriftedException;
use App\Models\MetalLedger\MetalLedgerRate;
use App\Models\Setting;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Task 2 of the Metal Ledger brief — the rate book. Every price traces back
 * to one rate, for one metal and one purity, in force at one moment.
 *
 * Nothing in here ever updates or deletes a row: a correction is a new row
 * that takes over as current, and the one it replaces stays in history
 * forever (see {@see recordManualRate()}/{@see recordFetchedRate()}).
 */
final class RateBookService
{
    private const RATE_TYPES = ['buy', 'sell'];

    private const DEFAULT_STALE_AFTER_HOURS = 12;

    /** Owner-configurable defaults from the Day 1 design note's answer to the brief's mandatory Day 1 question. */
    private const DEFAULT_QUOTE_VALIDITY_MINUTES = 15;

    private const DEFAULT_RATE_CHANGE_TOLERANCE_PERCENT = 0.5;

    /**
     * An Admin/manual entry for one metal + purity + buy-or-sell rate.
     * Always becomes the new current rate — a manual entry is never
     * refused, since it's exactly how an owner corrects an automatic fetch.
     */
    public function recordManualRate(
        int $metalTypeId,
        int $purityId,
        string $rateType,
        float $ratePerGram,
        ?int $enteredByUserId = null,
        ?CarbonInterface $effectiveAt = null,
    ): MetalLedgerRate {
        $this->assertValidRateType($rateType);
        $effectiveAt ??= now();

        $this->retireCurrent($metalTypeId, $purityId, $rateType);

        return MetalLedgerRate::create([
            'metal_type_id' => $metalTypeId,
            'purity_id' => $purityId,
            'rate_type' => $rateType,
            'rate_per_gram' => $ratePerGram,
            'effective_at' => $effectiveAt,
            'source' => 'manual',
            'entered_by_user_id' => $enteredByUserId,
            'is_current' => true,
            'superseded_by_manual' => false,
        ]);
    }

    /**
     * An automatic fetch for one metal + purity + buy-or-sell rate. If the
     * owner has already corrected this exact combination today, the fetch
     * is written to history but never applied — it must not quietly undo
     * the owner's decision. Otherwise it becomes the new current rate, same
     * as any other entry.
     *
     * Check the returned row's `superseded_by_manual` to see whether it
     * actually took effect.
     */
    public function recordFetchedRate(
        int $metalTypeId,
        int $purityId,
        string $rateType,
        float $ratePerGram,
        ?CarbonInterface $effectiveAt = null,
    ): MetalLedgerRate {
        $this->assertValidRateType($rateType);
        $effectiveAt ??= now();

        $supersededByManual = $this->hasManualEntryOn($metalTypeId, $purityId, $rateType, $effectiveAt);

        if (! $supersededByManual) {
            $this->retireCurrent($metalTypeId, $purityId, $rateType);
        }

        return MetalLedgerRate::create([
            'metal_type_id' => $metalTypeId,
            'purity_id' => $purityId,
            'rate_type' => $rateType,
            'rate_per_gram' => $ratePerGram,
            'effective_at' => $effectiveAt,
            'source' => 'fetch',
            'entered_by_user_id' => null,
            'is_current' => ! $supersededByManual,
            'superseded_by_manual' => $supersededByManual,
        ]);
    }

    public function currentRate(int $metalTypeId, int $purityId, string $rateType): ?MetalLedgerRate
    {
        $this->assertValidRateType($rateType);

        return MetalLedgerRate::query()
            ->where('metal_type_id', $metalTypeId)
            ->where('purity_id', $purityId)
            ->where('rate_type', $rateType)
            ->where('is_current', true)
            ->first();
    }

    /**
     * "What was the rate for this metal + purity + buy-or-sell type at this
     * exact moment?" — the latest entry that was actually in force at or
     * before $moment (a fetch the owner's correction superseded was never
     * in force, so it's excluded here regardless of its own timestamp).
     */
    public function rateAt(int $metalTypeId, int $purityId, string $rateType, CarbonInterface $moment): ?MetalLedgerRate
    {
        $this->assertValidRateType($rateType);

        return MetalLedgerRate::query()
            ->where('metal_type_id', $metalTypeId)
            ->where('purity_id', $purityId)
            ->where('rate_type', $rateType)
            ->where('superseded_by_manual', false)
            ->where('effective_at', '<=', $moment)
            ->orderByDesc('effective_at')
            ->first();
    }

    public function isStale(
        int $metalTypeId,
        int $purityId,
        string $rateType,
        int $staleAfterHours = self::DEFAULT_STALE_AFTER_HOURS,
    ): bool {
        $current = $this->currentRate($metalTypeId, $purityId, $rateType);

        if ($current === null) {
            return true;
        }

        return $current->effective_at->lt(now()->subHours($staleAfterHours));
    }

    /**
     * A purity with no rate of its own, derived from a basis purity's
     * current rate by the ratio of fineness — e.g. pricing a 20K item off
     * the 24K rate until the owner sets 20K's own rate directly. Never
     * applied silently: the result always carries `is_derived` so the
     * caller labels it, and this never writes a row to the rate book itself
     * — a derived figure is computed fresh each time, not stored as if it
     * were a real quoted rate.
     *
     * @return array{rate_per_gram: float, is_derived: true, basis: MetalLedgerRate}|null
     *                                                                                    null when the basis purity itself has no current rate —
     *                                                                                    refuse to price rather than guess.
     */
    public function deriveRate(
        int $basisMetalTypeId,
        int $basisPurityId,
        string $rateType,
        float $basisFinenessPercent,
        float $targetFinenessPercent,
    ): ?array {
        $this->assertValidRateType($rateType);

        if ($basisFinenessPercent <= 0) {
            throw new InvalidArgumentException('Basis fineness must be greater than 0.');
        }

        $basis = $this->currentRate($basisMetalTypeId, $basisPurityId, $rateType);

        if ($basis === null) {
            return null;
        }

        $derivedRatePerGram = round((float) $basis->rate_per_gram * $targetFinenessPercent / $basisFinenessPercent, 2);

        return ['rate_per_gram' => $derivedRatePerGram, 'is_derived' => true, 'basis' => $basis];
    }

    /** Owner-set, default 15 — a locked rate only holds this long before the line must be re-quoted. */
    public function quoteValidityMinutes(): int
    {
        return (int) Setting::get('quote_validity_minutes', self::DEFAULT_QUOTE_VALIDITY_MINUTES);
    }

    /** Owner-set, default 0.5% — beyond this much drift since quoting, the cashier must confirm before finalizing. */
    public function rateChangeTolerancePercent(): float
    {
        return (float) Setting::get('rate_change_tolerance_percent', self::DEFAULT_RATE_CHANGE_TOLERANCE_PERCENT);
    }

    /**
     * The Day 1 question, answered: lock a rate for one bill line, the
     * moment it is added — not at print time, not for the whole bill. The
     * returned quote is a plain array so the caller (the counter/POS side,
     * which this module has no screen of its own for) can hold it for
     * however long the line sits on the bill, then hand it back to
     * {@see assertQuoteStillValid()} when the bill is finalized.
     *
     * Refuses to quote rather than guess, same as {@see currentRate()} —
     * there is nothing sensible to lock if no rate is in force.
     *
     * @return array{metal_type_id:int, purity_id:int, rate_type:string, rate_per_gram:float, quoted_at: CarbonInterface}
     */
    public function quote(int $metalTypeId, int $purityId, string $rateType, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $current = $this->currentRate($metalTypeId, $purityId, $rateType);

        if ($current === null) {
            throw new RuntimeException(
                'No current rate for this metal and purity — refuse to quote rather than guess.'
            );
        }

        return [
            'metal_type_id' => $metalTypeId,
            'purity_id' => $purityId,
            'rate_type' => $rateType,
            'rate_per_gram' => (float) $current->rate_per_gram,
            'quoted_at' => $at,
        ];
    }

    /**
     * Checked when a bill is finalized (printed) — exactly where the design
     * note places this check. Two independent rules, checked in this order:
     *
     * 1. Quote validity window — if more than {@see quoteValidityMinutes()}
     *    has passed since the quote, it is too old to finalize at all. This
     *    cannot be bypassed by `$confirmed` — the only way past it is a
     *    fresh {@see quote()}.
     * 2. Rate-change tolerance — if the live rate has since moved more than
     *    {@see rateChangeTolerancePercent()}, the cashier must explicitly
     *    confirm (`$confirmed: true`) before this passes.
     *
     * Passes silently (returns void) when the quote is still good as-is —
     * nothing to confirm, nothing to re-quote.
     */
    public function assertQuoteStillValid(array $quote, ?CarbonInterface $finalizeAt = null, bool $confirmed = false): void
    {
        $finalizeAt ??= now();
        $quotedAt = $quote['quoted_at'];

        $minutesElapsed = $quotedAt->diffInRealSeconds($finalizeAt) / 60;
        $validityMinutes = $this->quoteValidityMinutes();

        if ($minutesElapsed > $validityMinutes) {
            throw new QuoteExpiredException($minutesElapsed, $validityMinutes);
        }

        $current = $this->currentRate($quote['metal_type_id'], $quote['purity_id'], $quote['rate_type']);
        if ($current === null) {
            // The rate disappeared entirely since quoting — treat exactly
            // like a validity failure; there is nothing to compare drift
            // against, and finalizing on a now-nonexistent rate is worse
            // than forcing a re-quote.
            throw new QuoteExpiredException($minutesElapsed, $validityMinutes);
        }

        $quotedRate = (float) $quote['rate_per_gram'];
        $currentRate = (float) $current->rate_per_gram;
        $driftPercent = $quotedRate == 0.0 ? 0.0 : abs($currentRate - $quotedRate) / $quotedRate * 100;
        $tolerancePercent = $this->rateChangeTolerancePercent();

        if ($driftPercent > $tolerancePercent && ! $confirmed) {
            throw new RateDriftedException($quotedRate, $currentRate, $driftPercent, $tolerancePercent);
        }
    }

    private function hasManualEntryOn(int $metalTypeId, int $purityId, string $rateType, CarbonInterface $onDate): bool
    {
        return MetalLedgerRate::query()
            ->where('metal_type_id', $metalTypeId)
            ->where('purity_id', $purityId)
            ->where('rate_type', $rateType)
            ->where('source', 'manual')
            ->whereDate('effective_at', $onDate->toDateString())
            ->exists();
    }

    private function retireCurrent(int $metalTypeId, int $purityId, string $rateType): void
    {
        MetalLedgerRate::query()
            ->where('metal_type_id', $metalTypeId)
            ->where('purity_id', $purityId)
            ->where('rate_type', $rateType)
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }

    private function assertValidRateType(string $rateType): void
    {
        if (! in_array($rateType, self::RATE_TYPES, true)) {
            throw new InvalidArgumentException(
                "Unknown rate type [{$rateType}]. Expected one of: ".implode(', ', self::RATE_TYPES)
            );
        }
    }
}
