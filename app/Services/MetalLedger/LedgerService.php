<?php

declare(strict_types=1);

namespace App\Services\MetalLedger;

use App\Models\MetalLedger\MetalLedgerMovement;
use App\Models\Purity;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Task 3 of the Metal Ledger brief — the movement ledger. One record shape
 * for every gram that moves, whoever it moves to or from: a customer sale,
 * a buy-back, raw metal to a karigar, a wholesaler purchase, buying a tola
 * off another shopkeeper — all go through {@see recordMovement()}.
 *
 * Nothing here ever updates or deletes a row. A mistake is corrected with
 * {@see correctMovement()}, which writes a new row pointing back at the one
 * it replaces — the original stays in the table, untouched, forever.
 */
final class LedgerService
{
    private const DIRECTIONS = ['in', 'out'];

    public function __construct(private readonly PurityConversionService $purity) {}

    /**
     * Record one movement of metal — the same method for a sale, a
     * buy-back, a karigar issue, a wholesaler purchase, or buying off
     * another shop. `direction` is from the shop's own point of view: 'out'
     * = metal leaves the shop, 'in' = metal arrives at the shop.
     */
    public function recordMovement(
        int $partyId,
        string $direction,
        int $metalTypeId,
        int $purityId,
        float $weightGrams,
        float $ratePerGram,
        float $amount,
        CarbonInterface|string $movementDate,
        ?int $enteredByUserId = null,
        ?string $itemReference = null,
        ?string $documentReference = null,
        ?string $notes = null,
        CarbonInterface|string|null $quotedAt = null,
    ): MetalLedgerMovement {
        $this->assertValidDirection($direction);

        $purity = Purity::findOrFail($purityId);

        return MetalLedgerMovement::create([
            'party_id' => $partyId,
            'direction' => $direction,
            'metal_type_id' => $metalTypeId,
            'purity_id' => $purityId,
            'weight_grams' => $weightGrams,
            'pure_weight_grams' => $this->purity->pureWeight($weightGrams, (float) $purity->fineness_percent),
            'rate_per_gram' => $ratePerGram,
            'amount' => $amount,
            'movement_date' => $movementDate,
            // The moment this rate was locked (see RateBookService::quote())
            // — defaults to now() for an instant transaction (a buy-back or
            // karigar settlement, priced and recorded in the same breath),
            // which trivially satisfies the quote-validity/tolerance checks.
            'quoted_at' => $quotedAt ?? now(),
            'entered_by_user_id' => $enteredByUserId,
            'item_reference' => $itemReference,
            'document_reference' => $documentReference,
            'notes' => $notes,
            'corrects_movement_id' => null,
        ]);
    }

    /**
     * Correct a movement entered wrong. Writes a brand-new row carrying
     * whichever fields changed (anything left out of `$changes` keeps the
     * original's value) and points it back at the movement it corrects.
     * The original row is never updated or deleted — it stays exactly as
     * entered, for whoever asks "what did this say before, and who fixed
     * it."
     *
     * @param  array<string,mixed>  $changes  only the fields that changed —
     *                                        any of party_id, direction,
     *                                        metal_type_id, purity_id,
     *                                        weight_grams, rate_per_gram,
     *                                        amount, movement_date,
     *                                        quoted_at, item_reference,
     *                                        document_reference
     */
    public function correctMovement(
        int $originalMovementId,
        array $changes,
        ?int $enteredByUserId = null,
        ?string $notes = null,
    ): MetalLedgerMovement {
        $original = MetalLedgerMovement::findOrFail($originalMovementId);

        $partyId = $changes['party_id'] ?? $original->party_id;
        $direction = $changes['direction'] ?? $original->direction;
        $metalTypeId = $changes['metal_type_id'] ?? $original->metal_type_id;
        $purityId = $changes['purity_id'] ?? $original->purity_id;
        $weightGrams = array_key_exists('weight_grams', $changes) ? $changes['weight_grams'] : (float) $original->weight_grams;
        $ratePerGram = array_key_exists('rate_per_gram', $changes) ? $changes['rate_per_gram'] : (float) $original->rate_per_gram;
        $amount = array_key_exists('amount', $changes) ? $changes['amount'] : (float) $original->amount;
        $movementDate = $changes['movement_date'] ?? $original->movement_date;
        // A correction fixes a mistake in what was recorded — it doesn't
        // re-quote the rate, so it keeps the original's quoted_at unless the
        // caller explicitly passes a new one in $changes.
        $quotedAt = $changes['quoted_at'] ?? $original->quoted_at;
        $itemReference = array_key_exists('item_reference', $changes) ? $changes['item_reference'] : $original->item_reference;
        $documentReference = array_key_exists('document_reference', $changes) ? $changes['document_reference'] : $original->document_reference;

        $this->assertValidDirection($direction);
        $purity = Purity::findOrFail($purityId);

        return MetalLedgerMovement::create([
            'party_id' => $partyId,
            'direction' => $direction,
            'metal_type_id' => $metalTypeId,
            'purity_id' => $purityId,
            'weight_grams' => $weightGrams,
            'pure_weight_grams' => $this->purity->pureWeight((float) $weightGrams, (float) $purity->fineness_percent),
            'rate_per_gram' => $ratePerGram,
            'amount' => $amount,
            'movement_date' => $movementDate,
            'quoted_at' => $quotedAt,
            'entered_by_user_id' => $enteredByUserId,
            'item_reference' => $itemReference,
            'document_reference' => $documentReference,
            'notes' => $notes,
            'corrects_movement_id' => $original->id,
        ]);
    }

    /**
     * A party's metal balance for one metal, in pure-metal weight. Positive
     * = the party owes the shop metal (more has gone OUT to them than has
     * come IN from them); negative = the shop owes the party metal.
     * Optionally as of a past date — stable against anything entered later,
     * since it only ever looks at movements dated on or before it.
     */
    public function metalBalance(int $partyId, int $metalTypeId, CarbonInterface|string|null $asOfDate = null): float
    {
        $out = (float) $this->activeMovements($partyId, $metalTypeId, $asOfDate)
            ->where('direction', 'out')->sum('pure_weight_grams');
        $in = (float) $this->activeMovements($partyId, $metalTypeId, $asOfDate)
            ->where('direction', 'in')->sum('pure_weight_grams');

        return round($out - $in, 3);
    }

    /**
     * A party's money balance, across every metal. Positive = the party
     * owes the shop money; negative = the shop owes the party. Kept
     * entirely separate from {@see metalBalance()} — the two are never
     * netted into one figure.
     */
    public function moneyBalance(int $partyId, CarbonInterface|string|null $asOfDate = null): float
    {
        $out = (float) $this->activeMovements($partyId, null, $asOfDate)
            ->where('direction', 'out')->sum('amount');
        $in = (float) $this->activeMovements($partyId, null, $asOfDate)
            ->where('direction', 'in')->sum('amount');

        return round($out - $in, 2);
    }

    /**
     * Every version of one logical movement, oldest (the original) first —
     * the original plus every correction made to it in turn. Answers "what
     * did this say before, and who changed it."
     */
    public function correctionChain(int $movementId): Collection
    {
        $chain = collect();
        $current = MetalLedgerMovement::findOrFail($movementId);
        $chain->push($current);

        while ($current->corrects_movement_id !== null) {
            $current = MetalLedgerMovement::findOrFail($current->corrects_movement_id);
            $chain->push($current);
        }

        return $chain->reverse()->values();
    }

    /**
     * Movements that still count — excludes any row a later correction has
     * superseded, so a corrected entry's old figures are never
     * double-counted alongside its replacement.
     */
    private function activeMovements(int $partyId, ?int $metalTypeId, CarbonInterface|string|null $asOfDate)
    {
        $supersededIds = MetalLedgerMovement::query()
            ->whereNotNull('corrects_movement_id')
            ->pluck('corrects_movement_id');

        $query = MetalLedgerMovement::query()
            ->where('party_id', $partyId)
            ->whereNotIn('id', $supersededIds);

        if ($metalTypeId !== null) {
            $query->where('metal_type_id', $metalTypeId);
        }

        if ($asOfDate !== null) {
            $date = $asOfDate instanceof CarbonInterface ? $asOfDate->toDateString() : $asOfDate;
            $query->whereDate('movement_date', '<=', $date);
        }

        return $query;
    }

    private function assertValidDirection(string $direction): void
    {
        if (! in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException(
                "Unknown direction [{$direction}]. Expected one of: ".implode(', ', self::DIRECTIONS)
            );
        }
    }
}
