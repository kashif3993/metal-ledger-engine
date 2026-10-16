<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MetalLedger\MetalLedgerRate;
use App\Models\MetalType;
use App\Models\Purity;
use App\Services\MetalLedger\RateBookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A manual-testing walkthrough for the rate book (Task 2): shows a fetched
 * rate, an admin correction, and a later same-day fetch, then prints what
 * "current" vs "previous" ends up looking like. Runs inside a transaction
 * that's rolled back at the end, so it never leaves demo rows behind and
 * can be re-run as many times as you like.
 */
class DemoRateHistory extends Command
{
    protected $signature = 'demo:rate-history';

    protected $description = 'Walk through a 3-step rate history (fetch, correction, fetch) to show current vs previous';

    public function handle(): int
    {
        $gold = MetalType::where('name', 'Gold')->first();
        $g22 = $gold ? Purity::where('metal_type_id', $gold->id)->where('name', '22K')->first() : null;

        if (! $gold || ! $g22) {
            $this->error('Reference data is missing. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        DB::beginTransaction();

        $rateBook = app(RateBookService::class);

        $this->info('9:00am — an automatic fetch comes in.');
        $rateBook->recordFetchedRate($gold->id, $g22->id, 'sell', 22000.00, now()->setTime(9, 0));

        $this->info('11:00am — the owner manually corrects it.');
        $rateBook->recordManualRate($gold->id, $g22->id, 'sell', 22400.00, enteredByUserId: null, effectiveAt: now()->setTime(11, 0));

        $this->info('1:00pm — another automatic fetch comes in, same day.');
        $rateBook->recordFetchedRate($gold->id, $g22->id, 'sell', 22150.00, now()->setTime(13, 0));

        $this->newLine();
        $this->line('Current rate right now: '.$rateBook->currentRate($gold->id, $g22->id, 'sell')->rate_per_gram);
        $this->newLine();

        $rows = MetalLedgerRate::where('metal_type_id', $gold->id)
            ->where('purity_id', $g22->id)
            ->where('rate_type', 'sell')
            ->orderBy('effective_at')
            ->get(['rate_per_gram', 'source', 'is_current', 'superseded_by_manual', 'effective_at']);

        $this->table(
            ['Time', 'Rate', 'Source', 'Current?', 'Blocked by correction?'],
            $rows->map(fn ($r) => [
                $r->effective_at->format('g:ia'),
                number_format((float) $r->rate_per_gram, 2),
                $r->source,
                $r->is_current ? 'YES' : 'no — previous',
                $r->superseded_by_manual ? 'YES — ignored' : 'no',
            ]),
        );

        $this->newLine();
        $this->comment('Nothing above was saved — this demo rolls back its own data on exit.');
        DB::rollBack();

        return self::SUCCESS;
    }
}
