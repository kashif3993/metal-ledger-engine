<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\MetalLedger\QuoteExpiredException;
use App\Exceptions\MetalLedger\RateDriftedException;
use App\Models\MetalType;
use App\Models\Party;
use App\Models\PartyType;
use App\Models\Purity;
use App\Services\MetalLedger\KarigarWorkOrderService;
use App\Services\MetalLedger\LedgerService;
use App\Services\MetalLedger\RateBookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * A real, clickable demo of the rate-lock mechanism and the karigar
 * wastage flag — the browser-page equivalent of `php artisan
 * demo:walkthrough`. Every button here calls the actual service classes,
 * nothing is faked or hand-typed; each run happens inside a transaction
 * rolled back at the end, so it's safe to click repeatedly and never
 * leaves demo rows behind.
 */
class DemoController extends Controller
{
    public function index()
    {
        return view('demo', ['result' => session('demo_result')]);
    }

    public function run(string $scenario): RedirectResponse
    {
        $lines = match ($scenario) {
            'normal' => $this->rateLockScenario(expired: false, drifted: false),
            'expired' => $this->rateLockScenario(expired: true, drifted: false),
            'drifted' => $this->rateLockScenario(expired: false, drifted: true),
            'karigar' => $this->karigarScenario(),
            default => ['Unknown scenario.'],
        };

        return redirect()->route('demo.index')->with('demo_result', [
            'scenario' => $scenario,
            'lines' => $lines,
        ]);
    }

    /** @return string[] */
    private function rateLockScenario(bool $expired, bool $drifted): array
    {
        $lines = [];

        DB::beginTransaction();
        try {
            $gold = MetalType::firstOrCreate(['name' => 'Gold']);
            $g22 = Purity::firstOrCreate(
                ['metal_type_id' => $gold->id, 'name' => '22K'],
                ['fineness_percent' => 91.66],
            );

            $rateBook = app(RateBookService::class);
            $rateBook->recordManualRate($gold->id, $g22->id, 'sell', 22000.00);

            $quote = $rateBook->quote($gold->id, $g22->id, 'sell');
            $lines[] = 'Line quoted at Rs 22,000.00/g (just now).';

            if ($drifted) {
                $rateBook->recordManualRate($gold->id, $g22->id, 'sell', 22400.00);
                $lines[] = 'Owner updates the rate to Rs 22,400.00/g.';
            }

            $finalizeAt = $expired
                ? $quote['quoted_at']->copy()->addMinutes(20)
                : $quote['quoted_at']->copy()->addMinutes(5);

            $lines[] = sprintf('Finalizing the bill %d minutes later...', $expired ? 20 : 5);

            try {
                $rateBook->assertQuoteStillValid($quote, $finalizeAt);
                $lines[] = '✅ PASSED — the locked price (Rs 22,000.00/g) is printed on the receipt, unchanged.';
            } catch (QuoteExpiredException $e) {
                $lines[] = '⛔ REJECTED — '.$e->getMessage();
            } catch (RateDriftedException $e) {
                $lines[] = '⚠️ NEEDS CONFIRMATION — '.$e->getMessage();
                $rateBook->assertQuoteStillValid($quote, $finalizeAt, confirmed: true);
                $lines[] = '✅ Cashier confirms the new rate — now finalizes successfully.';
            }
        } finally {
            DB::rollBack();
        }

        $lines[] = '';
        $lines[] = '(Nothing above was saved — rolled back automatically.)';

        return $lines;
    }

    /** @return string[] */
    private function karigarScenario(): array
    {
        $lines = [];

        DB::beginTransaction();
        try {
            $gold = MetalType::firstOrCreate(['name' => 'Gold']);
            $g22 = Purity::firstOrCreate(
                ['metal_type_id' => $gold->id, 'name' => '22K'],
                ['fineness_percent' => 91.66],
            );
            $karigarType = PartyType::firstOrCreate(['name' => 'Karigar']);
            $karigar = Party::create(['party_type_id' => $karigarType->id, 'name' => 'Ustad Rafiq (demo)']);

            $ledger = app(LedgerService::class);
            $workOrders = app(KarigarWorkOrderService::class);

            $ledger->recordMovement(
                partyId: $karigar->id, direction: 'out',
                metalTypeId: $gold->id, purityId: $g22->id,
                weightGrams: 40, ratePerGram: 22000, amount: 880000,
                movementDate: now(),
            );
            $lines[] = 'Issued 40g (22K) raw gold to Ustad Rafiq.';
            $lines[] = 'His balance: he holds '.$ledger->metalBalance($karigar->id, $gold->id).'g of the shop\'s pure gold.';

            $result = $workOrders->recordSettlement(
                karigarPartyId: $karigar->id, metalTypeId: $gold->id, purityId: $g22->id,
                kachaWeightGrams: 8.01, cutPerTolaRatti: 11.0, ratePerGram: 22000,
                movementDate: now(), labourCost: 1500,
            );
            $lines[] = 'He returns one finished piece: '.$result['settlement']['explanation'];

            if ($result['settlement']['wastage_flagged']) {
                $lines[] = '🚩 FLAGGED — above the owner-confirmed 4.00 ratti/tola fee; the shop and karigar need to settle the difference.';
            } else {
                $lines[] = '✅ Within his normal fee — nothing flagged.';
            }

            $lines[] = 'Balance now: '.$ledger->metalBalance($karigar->id, $gold->id).'g metal owed back, plus Rs '.$result['labour_movement']->amount.' labour owed to him — two separate figures.';
        } finally {
            DB::rollBack();
        }

        $lines[] = '';
        $lines[] = '(Nothing above was saved — rolled back automatically.)';

        return $lines;
    }
}
