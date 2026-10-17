<?php

namespace Tests\Feature\MetalLedger;

use App\Models\MetalLedger\MetalLedgerMovement;
use App\Models\MetalType;
use App\Models\Party;
use App\Models\PartyType;
use App\Models\Purity;
use App\Services\MetalLedger\KarigarWorkOrderService;
use App\Services\MetalLedger\LedgerService;
use App\Services\MetalLedger\PurityConversionService;
use App\Services\MetalLedger\WeightConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class KarigarWorkOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private KarigarWorkOrderService $workOrders;

    private LedgerService $ledger;

    private MetalType $gold;

    private Purity $gold22k;

    private Party $karigar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new LedgerService(new PurityConversionService);
        $this->workOrders = new KarigarWorkOrderService(new WeightConversionService, $this->ledger);

        $this->gold = MetalType::create(['name' => 'Gold']);
        $this->gold22k = Purity::create(['metal_type_id' => $this->gold->id, 'name' => '22K', 'fineness_percent' => 91.66]);

        $karigarType = PartyType::create(['name' => 'Karigar']);
        $this->karigar = Party::create(['party_type_id' => $karigarType->id, 'name' => 'Ustad Rafiq', 'phone' => '0321']);
    }

    /**
     * A real karigar's own paper ledger: 8.01g kacha, 11 ratti/tola cutting
     * loss, no polish, nets to 7.093g by hand. This reproduces that
     * arithmetic — landing at 7.092g, a single gram of rounding off the
     * hand-written slip, which hand-rounds at each intermediate step
     * rather than carrying full precision through.
     */
    public function test_cutting_loss_matches_a_real_karigar_slips_own_numbers(): void
    {
        $settlement = $this->workOrders->settle(kachaWeightGrams: 8.01, cutPerTolaRatti: 11.0);

        $this->assertEquals(0.918, $settlement['cutting_loss_grams']);
        $this->assertEquals(0.0, $settlement['polish_grams']);
        $this->assertEquals(7.092, $settlement['net_weight_grams']);
    }

    public function test_polish_adds_weight_back_on_top_of_the_cutting_loss(): void
    {
        $settlement = $this->workOrders->settle(kachaWeightGrams: 50.0, cutPerTolaRatti: 5.0, polishPerTolaRatti: 2.0);

        $this->assertEquals(2.604, $settlement['cutting_loss_grams']);
        $this->assertEquals(1.042, $settlement['polish_grams']);
        $this->assertEquals(48.438, $settlement['net_weight_grams']);
    }

    public function test_zero_or_negative_kacha_weight_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->workOrders->settle(kachaWeightGrams: 0, cutPerTolaRatti: 11.0);
    }

    public function test_negative_rates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->workOrders->settle(kachaWeightGrams: 10, cutPerTolaRatti: -1.0);
    }

    public function test_record_settlement_writes_one_metal_movement_and_no_labour_row_when_none_is_owed(): void
    {
        $result = $this->workOrders->recordSettlement(
            karigarPartyId: $this->karigar->id,
            metalTypeId: $this->gold->id,
            purityId: $this->gold22k->id,
            kachaWeightGrams: 8.01,
            cutPerTolaRatti: 11.0,
            ratePerGram: 22000.00,
            movementDate: '2026-10-17',
        );

        $this->assertNull($result['labour_movement']);
        $this->assertEquals(1, MetalLedgerMovement::count());
        $this->assertEquals(7.092, (float) $result['metal_movement']->weight_grams);
    }

    /**
     * The brief's own rule, proven here: a karigar holding the shop's gold
     * while also being owed labour is two facts, never netted into one.
     */
    public function test_labour_owed_is_a_separate_zero_weight_movement_never_netted_with_metal(): void
    {
        $result = $this->workOrders->recordSettlement(
            karigarPartyId: $this->karigar->id,
            metalTypeId: $this->gold->id,
            purityId: $this->gold22k->id,
            kachaWeightGrams: 8.01,
            cutPerTolaRatti: 11.0,
            ratePerGram: 22000.00,
            movementDate: '2026-10-17',
            labourCost: 1500.00,
        );

        $this->assertNotNull($result['labour_movement']);
        $this->assertEquals(0.0, (float) $result['labour_movement']->weight_grams);
        $this->assertEquals(1500.00, (float) $result['labour_movement']->amount);
        $this->assertEquals(2, MetalLedgerMovement::count());

        // Metal balance reflects only the 7.092g returned, in PURE weight at
        // 22K (91.66%) — the labour row's weight is 0, so it can't leak in.
        $this->assertEquals(-6.501, $this->ledger->metalBalance($this->karigar->id, $this->gold->id));

        // Money balance carries both the metal's own valuation (156,024.00)
        // and the labour (1,500.00) — that's correct, it's the same "amount"
        // every other movement in the system carries. What the brief asks
        // is that this money figure and the metal figure above are never
        // collapsed into one number — and they aren't: two separate method
        // calls, two separate units (grams vs Rupees).
        $this->assertEquals(-157524.00, $this->ledger->moneyBalance($this->karigar->id));
    }

    /**
     * The real slip's own "Sabiqa / Mojooda / Sona Jama" structure:
     * opening balance, this transaction, new running balance — which is
     * exactly what metalBalance() before and after a settlement already
     * gives, in pure-metal weight, with no extra storage needed.
     */
    public function test_sabiqa_plus_mojooda_equals_sona_jama(): void
    {
        // An opening balance: 40g worn (22K) issued earlier = 36.664g pure.
        $this->ledger->recordMovement(
            partyId: $this->karigar->id, direction: 'out',
            metalTypeId: $this->gold->id, purityId: $this->gold22k->id,
            weightGrams: 40.0, ratePerGram: 22000.00, amount: 880000.00,
            movementDate: '2026-10-01',
        );
        $sabiqa = $this->ledger->metalBalance($this->karigar->id, $this->gold->id);
        $this->assertEquals(36.664, $sabiqa);

        // He returns finished pieces: the real slip's own kacha/cutting-loss figures.
        $this->workOrders->recordSettlement(
            karigarPartyId: $this->karigar->id,
            metalTypeId: $this->gold->id,
            purityId: $this->gold22k->id,
            kachaWeightGrams: 8.01,
            cutPerTolaRatti: 11.0,
            ratePerGram: 22000.00,
            movementDate: '2026-10-17',
        );
        $mojooda = 6.501; // pure weight of the 7.092g net, at 22K
        $sonaJama = $this->ledger->metalBalance($this->karigar->id, $this->gold->id);

        $this->assertEquals(round($sabiqa - $mojooda, 3), $sonaJama);
    }
}
