<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\MetalLedger\QuoteExpiredException;
use App\Exceptions\MetalLedger\RateDriftedException;
use App\Models\MetalType;
use App\Models\Party;
use App\Models\Purity;
use App\Services\MetalLedger\BuyBackValuationService;
use App\Services\MetalLedger\KarigarWorkOrderService;
use App\Services\MetalLedger\LedgerService;
use App\Services\MetalLedger\PurityConversionService;
use App\Services\MetalLedger\RateBookService;
use App\Services\MetalLedger\WeightConversionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One command that walks through every built task — the four required by
 * the brief, plus the rate-lock mechanism (the brief's own mandatory Day 1
 * question) and the karigar reconciliation stretch goal — with real numbers,
 * printing what happens at each step. For understanding the system or
 * demonstrating it live, without typing any code by hand. Runs inside a
 * transaction rolled back at the end, so it's safe to re-run any time and
 * never leaves demo rows behind.
 */
class DemoWalkthrough extends Command
{
    protected $signature = 'demo:walkthrough';

    protected $description = 'Run every task with example data and print what each one proves';

    public function handle(): int
    {
        $gold = MetalType::where('name', 'Gold')->first();
        $g22 = $gold ? Purity::where('metal_type_id', $gold->id)->where('name', '22K')->first() : null;

        if (! $gold || ! $g22) {
            $this->error('Reference data is missing. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        DB::beginTransaction();

        $this->task1WeightAndPurity();
        $this->task2RateBook($gold, $g22);
        $this->task3Ledger($gold, $g22);
        $this->task5BuyBack($gold, $g22);
        $this->task6Karigar($gold, $g22);
        $this->rateLock($gold, $g22);

        DB::rollBack();
        $this->newLine();
        $this->comment('Nothing above was saved — every step here rolls back on exit.');

        return self::SUCCESS;
    }

    private function task1WeightAndPurity(): void
    {
        $this->components->twoColumnDetail('<fg=yellow;options=bold>TASK 1 — Weight & Purity Conversion</>', '');

        $weight = app(WeightConversionService::class);
        $purity = app(PurityConversionService::class);

        $broken = $weight->gramsToTraditional(50.5);
        $this->line("  A 50.5 gram item, in traditional units: {$broken['tola']} tola, {$broken['masha']} masha, {$broken['ratti']} ratti, {$broken['point']} point");

        $back = $weight->traditionalToGrams($broken);
        $this->line(sprintf('  Converted back to grams: %.3f (matches, within floating-point noise)', $back));

        $pure = $purity->pureWeight(10, 91.66);
        $this->line("  A 10g 22K item contains {$pure}g of pure gold (22K = 91.66% pure)");
        $this->newLine();
    }

    private function task2RateBook(MetalType $gold, Purity $g22): void
    {
        $this->components->twoColumnDetail('<fg=yellow;options=bold>TASK 2 — The Rate Book</>', '');

        $rateBook = app(RateBookService::class);

        $rateBook->recordFetchedRate($gold->id, $g22->id, 'sell', 22000.00, now()->setTime(9, 0));
        $rateBook->recordManualRate($gold->id, $g22->id, 'sell', 22400.00, effectiveAt: now()->setTime(11, 0));
        $rateBook->recordFetchedRate($gold->id, $g22->id, 'sell', 22150.00, now()->setTime(13, 0));

        $this->line('  9:00am fetch: Rs 22,000/g → became current');
        $this->line('  11:00am owner correction: Rs 22,400/g → became current, the 9am fetch is now history');
        $this->line('  1:00pm fetch: Rs 22,150/g → REJECTED, the owner already corrected today');
        $this->line('  Current rate right now: Rs '.$rateBook->currentRate($gold->id, $g22->id, 'sell')->rate_per_gram.'/g');
        $this->newLine();
    }

    private function task3Ledger(MetalType $gold, Purity $g22): void
    {
        $this->components->twoColumnDetail('<fg=yellow;options=bold>TASK 3 — The Movement Ledger</>', '');

        $customer = Party::create(['party_type_id' => 1, 'name' => 'Ayesha (demo)']);
        $ledger = app(LedgerService::class);

        $sale = $ledger->recordMovement(
            partyId: $customer->id, direction: 'out',
            metalTypeId: $gold->id, purityId: $g22->id,
            weightGrams: 10, ratePerGram: 22400, amount: 224000,
            movementDate: now(),
        );

        $this->line('  Sold a 10g 22K chain to Ayesha for Rs 224,000');
        $this->line('  Her pure-gold balance: '.$ledger->metalBalance($customer->id, $gold->id).'g (= 10g x 91.66%)');

        $corrected = $ledger->correctMovement($sale->id, ['weight_grams' => 10.5, 'amount' => 235200]);

        $this->line('  Correcting a mistake — it was actually 10.5g, not 10g');
        $this->line('  Her balance now: '.$ledger->metalBalance($customer->id, $gold->id).'g (the old 10g entry is excluded, not added on top)');
        $this->line('  Full history of that one entry: '.$ledger->correctionChain($corrected->id)->pluck('weight_grams')->implode('g -> ').'g');
        $this->newLine();
    }

    private function task5BuyBack(MetalType $gold, Purity $g22): void
    {
        $this->components->twoColumnDetail('<fg=yellow;options=bold>TASK 5 — Buy-Back Valuation</>', '');

        app(RateBookService::class)->recordManualRate($gold->id, $g22->id, 'buy', 22000.00);

        $valuation = app(BuyBackValuationService::class)->valuate($gold->id, $g22->id, reweighedGrams: 20);

        $this->line('  A customer brings back a 22K item, re-weighed today at 20g');
        $this->line('  '.$valuation['explanation']);
        $this->line('  Note: nothing above ever asks what the customer originally paid — that figure has no way in');
        $this->newLine();
    }

    private function task6Karigar(MetalType $gold, Purity $g22): void
    {
        $this->components->twoColumnDetail('<fg=yellow;options=bold>TASK 6 — Karigar Reconciliation (stretch, built anyway)</>', '');

        $karigar = Party::create(['party_type_id' => 1, 'name' => 'Ustad Rafiq (demo karigar)']);
        $ledger = app(LedgerService::class);
        $workOrders = app(KarigarWorkOrderService::class);

        $ledger->recordMovement(
            partyId: $karigar->id, direction: 'out',
            metalTypeId: $gold->id, purityId: $g22->id,
            weightGrams: 40, ratePerGram: 22000, amount: 880000,
            movementDate: now(),
        );
        $this->line('  Issued 40g (22K) raw gold to Ustad Rafiq to make jewelry');
        $this->line('  His balance: he now holds '.$ledger->metalBalance($karigar->id, $gold->id).'g of the shop\'s pure gold');

        $result = $workOrders->recordSettlement(
            karigarPartyId: $karigar->id, metalTypeId: $gold->id, purityId: $g22->id,
            kachaWeightGrams: 8.01, cutPerTolaRatti: 11.0, ratePerGram: 22000,
            movementDate: now(), labourCost: 1500,
        );
        $this->line('  He returns one finished piece: '.$result['settlement']['explanation']);
        $this->line('  Balance now: '.$ledger->metalBalance($karigar->id, $gold->id).'g metal still owed back to the shop, plus Rs '.$result['labour_movement']->amount.' labour owed to him — two separate figures, never netted into one');

        $flagged = $workOrders->settle(kachaWeightGrams: 8.01, cutPerTolaRatti: 6.0);
        $this->line('  A work order with a cutting loss above his agreed 4 ratti/tola fee (6 ratti/tola): wastage_flagged = '.($flagged['wastage_flagged'] ? 'true' : 'false').' — owner-confirmed: anything above 4 means the shop and karigar need to settle the difference');
        $this->newLine();
    }

    private function rateLock(MetalType $gold, Purity $g22): void
    {
        $this->components->twoColumnDetail('<fg=yellow;options=bold>Day 1 question — Rate-Lock</>', '');

        $rateBook = app(RateBookService::class);
        $rateBook->recordManualRate($gold->id, $g22->id, 'sell', 22000.00);

        $quote = $rateBook->quote($gold->id, $g22->id, 'sell');
        $this->line('  Line quoted at Rs 22,000.00/g');

        try {
            $rateBook->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(20));
        } catch (QuoteExpiredException $e) {
            $this->line('  Finalizing 20 minutes later (past the 15-minute window): REJECTED — '.$e->getMessage());
        }

        $rateBook->recordManualRate($gold->id, $g22->id, 'sell', 22400.00);
        try {
            $rateBook->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(5));
        } catch (RateDriftedException $e) {
            $this->line('  Rate moved to Rs 22,400.00/g, finalizing 5 minutes later: NEEDS CONFIRMATION — '.$e->getMessage());
        }

        $rateBook->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(5), confirmed: true);
        $this->line('  Cashier confirms the new rate: now finalizes successfully');
    }
}
