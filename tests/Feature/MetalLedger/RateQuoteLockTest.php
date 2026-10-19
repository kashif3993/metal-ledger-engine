<?php

namespace Tests\Feature\MetalLedger;

use App\Exceptions\MetalLedger\QuoteExpiredException;
use App\Exceptions\MetalLedger\RateDriftedException;
use App\Models\MetalType;
use App\Models\Party;
use App\Models\PartyType;
use App\Models\Purity;
use App\Models\Setting;
use App\Services\MetalLedger\LedgerService;
use App\Services\MetalLedger\PurityConversionService;
use App\Services\MetalLedger\RateBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Day 1 design note's own answer to the brief's mandatory Day 1
 * question, made executable: a rate is locked per line, the moment it is
 * quoted — not at print time — and that lock is enforced by two owner-set
 * limits checked when the bill is finalized: a quote validity window and a
 * rate-change tolerance.
 */
class RateQuoteLockTest extends TestCase
{
    use RefreshDatabase;

    private RateBookService $rates;

    private LedgerService $ledger;

    private MetalType $gold;

    private Purity $gold22k;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rates = new RateBookService;
        $this->ledger = new LedgerService(new PurityConversionService);
        $this->gold = MetalType::create(['name' => 'Gold']);
        $this->gold22k = Purity::create(['metal_type_id' => $this->gold->id, 'name' => '22K', 'fineness_percent' => 91.66]);
    }

    private function makeCustomer(): Party
    {
        $type = PartyType::create(['name' => 'Customer']);

        return Party::create(['party_type_id' => $type->id, 'name' => 'Ayesha', 'phone' => '0300']);
    }

    public function test_a_quote_still_within_the_window_and_unchanged_rate_passes_silently(): void
    {
        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22000.00);
        $quote = $this->rates->quote($this->gold->id, $this->gold22k->id, 'sell');

        $this->rates->assertQuoteStillValid($quote, now()->addMinutes(5));

        $this->expectNotToPerformAssertions();
    }

    /**
     * Design note: "Quote validity window — a locked rate only holds for N
     * minutes (default 15). After that, the line must be re-quoted."
     */
    public function test_a_quote_past_the_default_15_minute_window_must_be_re_quoted(): void
    {
        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22000.00);
        $quote = $this->rates->quote($this->gold->id, $this->gold22k->id, 'sell');

        $this->expectException(QuoteExpiredException::class);
        $this->rates->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(16));
    }

    /** Expiry cannot be waved through by a confirmation — only a fresh quote clears it. */
    public function test_confirmed_does_not_rescue_an_expired_quote(): void
    {
        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22000.00);
        $quote = $this->rates->quote($this->gold->id, $this->gold22k->id, 'sell');

        $this->expectException(QuoteExpiredException::class);
        $this->rates->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(20), confirmed: true);
    }

    /**
     * Design note: "Rate-change tolerance — if the live rate has moved more
     * than a set % (default 0.5%) since the line was locked, the cashier is
     * forced to confirm before printing."
     */
    public function test_a_rate_drifted_beyond_tolerance_forces_confirmation(): void
    {
        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22000.00);
        $quote = $this->rates->quote($this->gold->id, $this->gold22k->id, 'sell');

        // 22400 is +1.818% over 22000 — past the 0.5% default tolerance.
        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22400.00);

        try {
            $this->rates->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(5));
            $this->fail('Expected RateDriftedException.');
        } catch (RateDriftedException $e) {
            $this->assertSame(22000.0, $e->quotedRatePerGram);
            $this->assertSame(22400.0, $e->currentRatePerGram);
            $this->assertEqualsWithDelta(1.818, $e->driftPercent, 0.01);
        }

        // The cashier confirms — now it passes (no exception thrown).
        $this->rates->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(5), confirmed: true);
    }

    public function test_a_drift_within_tolerance_does_not_require_confirmation(): void
    {
        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22000.00);
        $quote = $this->rates->quote($this->gold->id, $this->gold22k->id, 'sell');

        // 22050 is +0.227% — within the 0.5% default tolerance.
        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22050.00);

        $this->rates->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(5));
        $this->expectNotToPerformAssertions();
    }

    public function test_the_owner_can_configure_both_limits(): void
    {
        Setting::create(['key' => 'quote_validity_minutes', 'value' => '5']);
        Setting::create(['key' => 'rate_change_tolerance_percent', 'value' => '2']);

        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22000.00);
        $quote = $this->rates->quote($this->gold->id, $this->gold22k->id, 'sell');

        // Past the shop's own tighter 5-minute window (would have passed the 15-minute default).
        $this->expectException(QuoteExpiredException::class);
        $this->rates->assertQuoteStillValid($quote, $quote['quoted_at']->copy()->addMinutes(6));
    }

    public function test_refuses_to_quote_rather_than_guess_when_no_rate_is_in_force(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->rates->quote($this->gold->id, $this->gold22k->id, 'sell');
    }

    /**
     * End-to-end: the quote is what actually gets stored as the movement's
     * quoted_at, so a later audit can answer "what rate was this line
     * locked at, and when."
     */
    public function test_a_valid_quote_carries_its_locked_moment_onto_the_recorded_movement(): void
    {
        Carbon::setTestNow('2026-10-19 10:02:00');
        $this->rates->recordManualRate($this->gold->id, $this->gold22k->id, 'sell', 22000.00);
        $quote = $this->rates->quote($this->gold->id, $this->gold22k->id, 'sell');

        Carbon::setTestNow('2026-10-19 10:10:00');
        $this->rates->assertQuoteStillValid($quote);

        $customer = $this->makeCustomer();
        $movement = $this->ledger->recordMovement(
            partyId: $customer->id,
            direction: 'out',
            metalTypeId: $this->gold->id,
            purityId: $this->gold22k->id,
            weightGrams: 10.0,
            ratePerGram: $quote['rate_per_gram'],
            amount: 220000.0,
            movementDate: '2026-10-19',
            quotedAt: $quote['quoted_at'],
        );

        $this->assertDatabaseHas('metal_ledger_movements', [
            'id' => $movement->id,
            'quoted_at' => '2026-10-19 10:02:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_recording_a_movement_without_an_explicit_quote_defaults_quoted_at_to_now(): void
    {
        Carbon::setTestNow('2026-10-19 10:30:00');
        $customer = $this->makeCustomer();

        $movement = $this->ledger->recordMovement(
            partyId: $customer->id,
            direction: 'in',
            metalTypeId: $this->gold->id,
            purityId: $this->gold22k->id,
            weightGrams: 10.0,
            ratePerGram: 22000.0,
            amount: 220000.0,
            movementDate: '2026-10-19',
        );

        $this->assertNotNull($movement->quoted_at);
        $this->assertTrue($movement->quoted_at->equalTo(Carbon::parse('2026-10-19 10:30:00')));

        Carbon::setTestNow();
    }
}
