<?php

declare(strict_types=1);

namespace App\Services\MetalLedger;

use App\Models\MetalLedger\MetalLedgerMovement;
use App\Models\Setting;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Task 6 of the Metal Ledger brief (a stretch goal, built here as an
 * extension on top of the four required tasks) — karigar reconciliation.
 *
 * Raw ("kacha") metal issued to a goldsmith comes back lighter: a little
 * is always lost as scrap while shaping, at an agreed rate per tola, and
 * polishing can add a little back. This turns that kacha weight into the
 * real net weight owed, the same arithmetic a karigar's own paper ledger
 * already does by hand — then records it on the existing ledger (Task 3)
 * as one metal movement plus, if labour is owed, a separate zero-weight
 * money movement. No new table: everything here is either a pure
 * calculation (mirrors {@see BuyBackValuationService::valuate()}, which
 * also never persists anything) or an ordinary {@see LedgerService}
 * movement, so the ledger and rate book never had to be reshaped for
 * this to land, exactly as the brief asked of every stretch goal.
 */
final class KarigarWorkOrderService
{
    /**
     * Owner-confirmed, same bar as `tola_grams` and the 22K purity factor.
     * The shop's real karigar agreement: he keeps 4 ratti of gold per tola
     * as his own fee — that is not loss, it is his payment, built into the
     * gold itself rather than paid in cash. Nothing above that is ever
     * expected; if a work order's cut comes in higher, it is not routine
     * wear, it means the shop and the karigar need to settle the
     * difference, which is exactly why it gets flagged here rather than
     * silently accepted. Still overridable
     * (`karigar_wastage_threshold_ratti_per_tola` in Settings) since a
     * individual karigar could have a different agreed rate.
     */
    private const FALLBACK_WASTAGE_THRESHOLD_RATTI_PER_TOLA = 4.0;

    public function __construct(
        private readonly WeightConversionService $weight,
        private readonly LedgerService $ledger,
    ) {}

    /** Owner-set (not yet confirmed — see the fallback's own doc comment above). */
    public function wastageThresholdRattiPerTola(): float
    {
        return (float) Setting::get(
            'karigar_wastage_threshold_ratti_per_tola',
            self::FALLBACK_WASTAGE_THRESHOLD_RATTI_PER_TOLA,
        );
    }

    /**
     * Price the cutting loss and polish for one kacha weight, without
     * recording anything. Rates are in ratti lost (or gained) per tola —
     * the unit a karigar agreement is actually struck in.
     *
     * `wastage_flagged` answers the brief's open question — "when is a loss
     * figure flagged to the owner" — by comparing this work order's own
     * cutting-loss rate against the shop-wide threshold. It never blocks or
     * changes the arithmetic above it; it only marks the result for the
     * owner's attention, the same way a derived rate is marked `is_derived`
     * rather than silently used.
     *
     * @return array{kacha_weight_grams: float, cutting_loss_grams: float, polish_grams: float, net_weight_grams: float, wastage_threshold_ratti_per_tola: float, wastage_flagged: bool, explanation: string}
     */
    public function settle(
        float $kachaWeightGrams,
        float $cutPerTolaRatti,
        float $polishPerTolaRatti = 0.0,
    ): array {
        if ($kachaWeightGrams <= 0) {
            throw new InvalidArgumentException('Kacha weight must be greater than 0.');
        }

        if ($cutPerTolaRatti < 0 || $polishPerTolaRatti < 0) {
            throw new InvalidArgumentException('Cutting-loss and polish rates cannot be negative.');
        }

        $tolaFraction = $kachaWeightGrams / $this->weight->tolaGrams();

        $cuttingLossGrams = round($this->weight->toGrams($tolaFraction * $cutPerTolaRatti, 'ratti'), 3);
        $polishGrams = round($this->weight->toGrams($tolaFraction * $polishPerTolaRatti, 'ratti'), 3);
        $netWeightGrams = round($kachaWeightGrams - $cuttingLossGrams + $polishGrams, 3);

        $threshold = $this->wastageThresholdRattiPerTola();
        $wastageFlagged = $cutPerTolaRatti > $threshold;

        return [
            'kacha_weight_grams' => $kachaWeightGrams,
            'cutting_loss_grams' => $cuttingLossGrams,
            'polish_grams' => $polishGrams,
            'net_weight_grams' => $netWeightGrams,
            'wastage_threshold_ratti_per_tola' => $threshold,
            'wastage_flagged' => $wastageFlagged,
            'explanation' => sprintf(
                '%.3fg kacha − %.3fg cutting loss (%.2f ratti/tola) + %.3fg polish (%.2f ratti/tola) = %.3fg net%s',
                $kachaWeightGrams, $cuttingLossGrams, $cutPerTolaRatti, $polishGrams, $polishPerTolaRatti, $netWeightGrams,
                $wastageFlagged ? sprintf(' — ABOVE the %.2f ratti/tola threshold, flagged for review', $threshold) : '',
            ),
        ];
    }

    /**
     * {@see settle()} the work order, then record it: the finished pieces
     * arriving back at the shop (direction 'in', so the karigar's metal
     * balance drops by the net weight), and — only if labour is owed — a
     * second, zero-weight 'in' movement carrying just the labour amount.
     * The two are always two rows. Metal owed and money owed are never
     * netted into one figure, per the brief.
     *
     * @return array{settlement: array, metal_movement: MetalLedgerMovement, labour_movement: ?MetalLedgerMovement}
     */
    public function recordSettlement(
        int $karigarPartyId,
        int $metalTypeId,
        int $purityId,
        float $kachaWeightGrams,
        float $cutPerTolaRatti,
        float $ratePerGram,
        CarbonInterface|string $movementDate,
        float $polishPerTolaRatti = 0.0,
        float $labourCost = 0.0,
        ?int $enteredByUserId = null,
    ): array {
        $settlement = $this->settle($kachaWeightGrams, $cutPerTolaRatti, $polishPerTolaRatti);

        $metalMovement = $this->ledger->recordMovement(
            partyId: $karigarPartyId,
            direction: 'in',
            metalTypeId: $metalTypeId,
            purityId: $purityId,
            weightGrams: $settlement['net_weight_grams'],
            ratePerGram: $ratePerGram,
            amount: round($settlement['net_weight_grams'] * $ratePerGram, 2),
            movementDate: $movementDate,
            enteredByUserId: $enteredByUserId,
            notes: $settlement['explanation'],
        );

        $labourMovement = null;
        if ($labourCost > 0) {
            $labourMovement = $this->ledger->recordMovement(
                partyId: $karigarPartyId,
                direction: 'in',
                metalTypeId: $metalTypeId,
                purityId: $purityId,
                weightGrams: 0,
                ratePerGram: 0,
                amount: $labourCost,
                movementDate: $movementDate,
                enteredByUserId: $enteredByUserId,
                notes: 'Labour owed for this work order — money only, never netted with the metal above.',
            );
        }

        return [
            'settlement' => $settlement,
            'metal_movement' => $metalMovement,
            'labour_movement' => $labourMovement,
        ];
    }
}
