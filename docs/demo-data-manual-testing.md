# Demo Data — Manual Testing

This project has no web form — it's a backend module, so "entering test
data" means running PHP in `php artisan tinker` and reading back what it
computed. Everything below is ready to paste in, with the expected
result next to it so you can confirm it matches.

```
cd C:\laragon\www\metal-ledger-engine
php artisan tinker
```

---

## Task 1 — Weight & purity conversion

```php
use App\Services\MetalLedger\WeightConversionService;
use App\Services\MetalLedger\PurityConversionService;

$weight = app(WeightConversionService::class);
$purity = app(PurityConversionService::class);

// A 50.5 gram item, broken into tola/masha/ratti/point:
$weight->gramsToTraditional(50.5);
// → ['tola' => 4, 'masha' => 3, 'ratti' => 7, 'point' => 64.49]

// And back the other way — should return to 50.5 (round-trips cleanly):
$weight->traditionalToGrams(['tola' => 4, 'masha' => 3, 'ratti' => 7, 'point' => 64.49]);
// → 50.500005875736 — tiny floating-point noise, not a bug; this is
//   exactly why storage rounds to 3dp (50.500) rather than trusting
//   a raw float to come back out exactly even

// A 10g 22K item's pure gold content (owner-confirmed 91.66%, not 91.67%):
$purity->pureWeight(10, 91.66);
// → 9.166

// A 20g 999 silver bar's pure content:
$purity->pureWeight(20, 99.90);
// → 19.98
```

---

## Task 2 — The rate book

Already has its own one-command demo — see [`README.md`](../README.md):

```
php artisan demo:rate-history
```

---

## Task 3 — The movement ledger

```php
use App\Models\Party;
use App\Models\MetalType;
use App\Models\Purity;
use App\Services\MetalLedger\LedgerService;

$gold = MetalType::where('name', 'Gold')->first();
$g22  = Purity::where('metal_type_id', $gold->id)->where('name', '22K')->first();

// A test customer (the Parties table has no rows yet — ReferenceDataSeeder
// only seeds the party *types*, not actual people):
$customer = Party::create(['party_type_id' => 1, 'name' => 'Ayesha (demo)']);

$ledger = app(LedgerService::class);

// Sell her a 10g 22K chain today:
$sale = $ledger->recordMovement(
    partyId: $customer->id, direction: 'out',
    metalTypeId: $gold->id, purityId: $g22->id,
    weightGrams: 10, ratePerGram: 22400, amount: 224000,
    movementDate: now(),
);

$ledger->metalBalance($customer->id, $gold->id);
// → 9.166  (positive = she owes the shop this much pure gold —
//            10g worn × 91.66% fineness)

// Now correct a typo — the weight should have been 10.5g, not 10:
$corrected = $ledger->correctMovement($sale->id, ['weight_grams' => 10.5, 'amount' => 235200]);

$ledger->metalBalance($customer->id, $gold->id);
// → 9.624  (9.166 is gone from the balance entirely — the ORIGINAL
//            10g entry is excluded now that a correction supersedes it,
//            not added on top of it)

// The full history of that one logical entry, original first.
// Note: walk the chain from the CORRECTED row's id, not the original's —
// correctionChain() follows corrects_movement_id backwards, and the
// original has nothing to follow (it's where the chain ends).
$ledger->correctionChain($corrected->id)->pluck('weight_grams');
// → [10.000, 10.500]
```

---

## Task 5 — Buy-back valuation

```php
use App\Services\MetalLedger\RateBookService;
use App\Services\MetalLedger\BuyBackValuationService;

// Needs a current BUYING rate first — valuate() refuses to guess if
// there isn't one:
app(RateBookService::class)->recordManualRate($gold->id, $g22->id, 'buy', 22000.00);

$buyback = app(BuyBackValuationService::class);

// A customer brings back a 20g 22K item, re-weighed on your scale today
// (shop's default deduction is 2.5% — set via MetalLedgerBuyBackDeduction
// if you want to test a different rate):
$buyback->valuate($gold->id, $g22->id, reweighedGrams: 20);
// → [
//      weight_grams: 20,
//      rate_per_gram: 22000,
//      deduction_percent: 2.5,
//      deduction_grams: 0.5,
//      effective_weight_grams: 19.5,
//      amount: 429000,
//      explanation: "20.000g re-weighed − 2.50% (0.500g) deduction = 19.500g × Rs 22000.00/g = Rs 429000.00",
//   ]

// The brief's G-3 rule in practice: there is no parameter anywhere on
// this method for what the customer originally paid — only today's
// re-weighed grams and today's rate feed the price, by design.
```

---

Nothing above needs cleanup — none of it touches your real gold-shop
database (this project has its own, separate SQLite file). Re-run any
block as many times as you like.
