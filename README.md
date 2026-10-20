# Metal Ledger & Valuation Engine

A backend module for a gold & silver shop ERP: unit/purity conversion, the rate book,
the movement ledger, and buy-back valuation. Built against the task brief in
[`docs/Metal Ledger & Valuation Engine — Task Brief (1).docx`](<docs/Metal Ledger & Valuation Engine — Task Brief (1).docx>).

**Day 1 design note** (answers the rate-locking question, the data model, the rounding
policy, and how the stretch goals land later — written before any code, as the brief
requires): [`docs/Metal Ledger and Valuation Engine - Day 1 Design Note.pdf`](<docs/Metal Ledger and Valuation Engine - Day 1 Design Note.pdf>)

## Running it

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```

No external services, no MySQL — SQLite only, both for the app (`database/database.sqlite`)
and the test suite (in-memory). `php artisan db:seed` adds minimal reference data (metals,
purities, party types) for exploring the module interactively via `php artisan tinker`;
the test suite doesn't need it — every test creates its own rows.

## What's here, mapped to the brief's tasks

| Task | Code | Tests |
| --- | --- | --- |
| **1 — Weight & purity** | [`WeightConversionService`](app/Services/MetalLedger/WeightConversionService.php), [`PurityConversionService`](app/Services/MetalLedger/PurityConversionService.php) | [`WeightConversionServiceTest`](tests/Feature/MetalLedger/WeightConversionServiceTest.php), [`PurityConversionServiceTest`](tests/Unit/MetalLedger/PurityConversionServiceTest.php) |
| **2 — The rate book** | [`RateBookService`](app/Services/MetalLedger/RateBookService.php), [`MetalLedgerRate`](app/Models/MetalLedger/MetalLedgerRate.php) | [`RateBookServiceTest`](tests/Feature/MetalLedger/RateBookServiceTest.php) |
| **3 — The movement ledger** | [`LedgerService`](app/Services/MetalLedger/LedgerService.php), [`MetalLedgerMovement`](app/Models/MetalLedger/MetalLedgerMovement.php) | [`LedgerServiceTest`](tests/Feature/MetalLedger/LedgerServiceTest.php) |
| **5 — Buy-back valuation** | [`BuyBackValuationService`](app/Services/MetalLedger/BuyBackValuationService.php), [`MetalLedgerBuyBackDeduction`](app/Models/MetalLedger/MetalLedgerBuyBackDeduction.php) | [`BuyBackValuationServiceTest`](tests/Feature/MetalLedger/BuyBackValuationServiceTest.php) |
| **6 — Karigar reconciliation** (stretch, built anyway) | [`KarigarWorkOrderService`](app/Services/MetalLedger/KarigarWorkOrderService.php) | [`KarigarWorkOrderServiceTest`](tests/Feature/MetalLedger/KarigarWorkOrderServiceTest.php) |
| **Day 1 question — rate-lock** | [`RateBookService::quote()`/`assertQuoteStillValid()`](app/Services/MetalLedger/RateBookService.php) | [`RateQuoteLockTest`](tests/Feature/MetalLedger/RateQuoteLockTest.php) |

Task 4 (zakat/historical reporting) is the brief's own stretch goal — not built, by
design; the design note explains how it lands on what's here without reshaping it.
Task 6 was a stretch goal too (the brief said not to build it this week). Building it
anyway, once the four required tasks were solid, was a confirmed decision — not an
oversight of the instruction — see
[`docs/explain-to-the-office.md`](docs/explain-to-the-office.md) for why that's flagged
explicitly rather than left for someone else to notice.

The rate-lock mechanism answers the brief's own mandatory Day 1 question (the design
note's "quote validity window" + "rate-change tolerance") — previously answered only in
the design note's prose, now actually enforced: `RateBookService::quote()` locks a rate
for one bill line, `assertQuoteStillValid()` checks it at finalize time.

## Design notes worth reading in the code itself

- **Every "Done when" line in the brief has a test named after it.** The tests aren't just
  checking the code runs — they're the brief's own acceptance bar, made executable (e.g.
  `test_an_admin_correction_outranks_a_same_day_fetch_and_a_later_fetch_cannot_undo_it`
  reproduces the brief's exact 9am/11am/1pm scenario).
- **G-3 is enforced structurally, not by convention.** `BuyBackValuationServiceTest::test_no_method_on_this_service_accepts_an_original_sale_price_or_weight`
  uses reflection to prove the buy-back valuation methods have no parameter an original
  sale figure could even be passed through — there's nothing to disable or override
  because there's nothing there.
- **A real bug caught by the brief's own "nothing drifts from being converted twice" rule:**
  an early version of `traditionalToGrams()` rounded to 3 decimal grams internally — finer
  than a single "point" (~0.0012g) can represent, so round-tripping a weight through units
  silently lost precision. Fixed by moving rounding out of the conversion method entirely —
  round only at the point of storage, never inside a general-purpose utility.
- **One open question from the brief — 22K's exact purity — was asked and answered**,
  not guessed: confirmed at 91.66% (not the textbook 91.666...% rounds to 91.67%). See the
  design note's "Open question resolved" section for the full reasoning.

## Why four separate `metal_ledger_*` tables, not shared schema

Each piece of state this module owns gets its own table (`metal_ledger_rates`,
`metal_ledger_movements`, `metal_ledger_buyback_deductions`) rather than reusing a
same-purpose table from elsewhere, so the module has no write dependency on anything
outside it. Read-only reference data it doesn't own (`metal_types`, `purities`,
`party_types`, `parties`, `settings`) is shared, minimal, and never written to by this
module's own logic beyond what a normal shop admin screen would do.
