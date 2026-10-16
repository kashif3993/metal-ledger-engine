# Metal Ledger — Complete Testing Guide

One file: for each of the 4 tasks built, what the brief asked for, what
was built for it, and **two** verified test cases you can run yourself.
Every number below was actually run through the real code (not
hand-calculated) — see the bottom for how to run them.

Source: `Metal Ledger & Valuation Engine — Task Brief (1).docx`, dated
Oct 13, 2026.

---

## Task 1 — One language for weight and purity

> **From the brief:** *"Any weight the shop speaks must mean exactly
> one thing... A weight entered in any unit and read back in another
> returns the same quantity, both directions, every time... Purity
> turns a worn weight into pure-metal weight: a 10 g 22K chain is
> 9.167 g of pure gold, a 10 g 925 bar is 9.25 g of pure silver."*
> (Requirements FR-1, G-4)

**What was built:** `WeightConversionService` converts between gram,
tola, masha, ratti and point losslessly in both directions — the
grams-per-tola figure is configured once (`tola_grams` in Settings),
never hardcoded twice. `PurityConversionService` turns a worn weight
into pure-metal weight using the purity's fineness percentage.

**Code:** [`WeightConversionService.php`](../app/Services/MetalLedger/WeightConversionService.php),
[`PurityConversionService.php`](../app/Services/MetalLedger/PurityConversionService.php)

### Test case 1 — a mixed-unit gold item
| Input | Value |
|---|---|
| Weight | 50.5 grams |
| Purity | 22K gold (91.66%, owner-confirmed) |

| Result | Value |
|---|---|
| In traditional units | 4 tola, 3 masha, 7 ratti, 64.49 point |
| Converted back to grams | 50.500005875736 (tiny float noise — this is exactly why storage rounds to 3dp) |
| Pure gold in a 10g 22K item | 9.166 g |

### Test case 2 — the brief's own example, plus silver
| Input | Value |
|---|---|
| Weight | 1 tola gold |
| Purity | 22K (91.66%) |

| Result | Value |
|---|---|
| 1 tola in grams | 11.6638038 |
| Pure gold content | 10.691 g |
| *(brief's own silver example, for comparison)* 10g at 925 (92.50%) | **9.25 g pure** — matches the brief exactly |

---

## Task 2 — The rate book

> **From the brief:** *"Every price in the system must trace back to
> one rate... Nothing is ever overwritten or deleted... An Admin rate
> outranks a fetched rate for the same metal, purity and day... a 9
> a.m. fetch, an 11 a.m. correction and a 1 p.m. retry [must not]
> silently erase the owner's decision."* (FR-2, FR-3, FR-4, G-2)

**What was built:** `RateBookService` — every rate entry is a new row
(never updated/deleted); a manual entry always wins; a same-day
automatic fetch is rejected once the owner has corrected that day; a
purity with no rate of its own can have one derived from a basis purity,
always labeled `is_derived` so it's never silently used.

**Code:** [`RateBookService.php`](../app/Services/MetalLedger/RateBookService.php)

### Test case 1 — the brief's exact 9am/11am/1pm scenario
| Time | Action | Rate | Becomes current? |
|---|---|---|---|
| 9:00 AM | Auto-fetch | Rs 22,000/g | Yes — until... |
| 11:00 AM | Admin correction | Rs 22,400/g | Yes |
| 1:00 PM | Auto-fetch again | Rs 22,150/g | **No — rejected** |

**Result:** 3 entries in history, Rs 22,400/g stays in force — matching
the brief's "Done when" line word for word.

### Test case 2 — a purity with no rate of its own (derived)
| Input | Value |
|---|---|
| 24K gold's current rate | Rs 34,000/g |
| 21K gold | has no rate entered today |

| Result | Value |
|---|---|
| Derived 21K rate | Rs 29,779.78/g (34,000 × 87.50 ÷ 99.90) |
| Flag on the result | `is_derived: true` — always labeled, never silent |

---

## Task 3 — The movement ledger

> **From the brief:** *"One record shape for every gram that moves,
> whoever it moves to or from... A mistake from last week must be
> correctable... nothing already recorded can quietly change
> afterwards... A party balance has two sides that are never netted
> into one number: metal owed... and money owed."* (FR-14, FR-15,
> FR-16, FR-21)

**What was built:** `LedgerService.recordMovement()` — the same method
records a sale, a buy-back, a karigar issue, or a purchase from another
shop. Corrections (`correctMovement()`) always add a new row pointing
back at the one they replace; the original is never touched. Balances
are computed by excluding superseded rows, never by editing history.

**Code:** [`LedgerService.php`](../app/Services/MetalLedger/LedgerService.php)

### Test case 1 — a customer sale, then a correction
| Step | Detail |
|---|---|
| Sell | 10g 22K chain to Ayesha for Rs 224,000 |
| Her balance after | 9.166g pure gold owed to the shop |
| Correct | weight was actually 10.5g, not 10g |
| Her balance after correction | 9.624g (the old 10g entry is excluded, not added on top) |
| Full history | 10.000g → 10.500g, both still in the table |

### Test case 2 — the same shape, two different party types
*(proves the brief's point: one record shape works for everyone)*

**Raw metal issued to a karigar** (direction: out — metal leaves the shop):
| Field | Value |
|---|---|
| Party | Ustad Rafiq (Karigar) |
| Weight | 1 tola (11.6638038g) of 24K gold |
| Rate | Rs 33,800/g |
| Amount | Rs 394,236.57 |

**Result:** his metal balance = **11.652g pure gold** — he's holding
that much of the shop's gold until finished pieces come back. This is
the exact same `recordMovement()` call as a customer sale — just a
different party type and direction — with no special-case code for
"karigar" anywhere in the service.

---

## Task 5 — Buy-back valuation

> **From the brief:** *"Gold coming back over the counter is gold
> being bought fresh today. It is never a sale being undone... Today's
> weight is the only weight used... The original sale price and weight
> cannot reach the valuation at all."* (FR-11 to FR-13, FR-24a, G-3)
>
> **Done when:** *"the identical one-tola item brought back the same
> day values below what the customer paid."*

**What was built:** `BuyBackValuationService.valuate()` — prices
purely from today's re-weighed grams and today's **buying** rate (never
the selling rate, never anything client-supplied). No parameter on this
method accepts an original sale price or weight — proven structurally
by a reflection test, not just by convention.

**Code:** [`BuyBackValuationService.php`](../app/Services/MetalLedger/BuyBackValuationService.php)

### Test case 1 — a straightforward buy-back
| Input | Value |
|---|---|
| Re-weighed today | 20g, 22K |
| Today's buying rate | Rs 22,000/g |
| Deduction | 2.5% (shop default) |

**Result:** `20.000g − 2.50% (0.500g) = 19.500g × Rs 22,000.00/g = Rs 429,000.00`

### Test case 2 — the brief's exact "Done when" test
| Step | Detail |
|---|---|
| Sold today | 1 tola (11.6638038g) 22K to Bilal at the **selling** rate, Rs 22,400/g |
| Sale amount | **Rs 261,269.21** |
| Same item, brought back same afternoon | re-weighed at 1 tola, priced at today's **buying** rate, Rs 22,000/g, 2.5% deduction |
| Buy-back amount | **Rs 250,184.00** |
| Is the buy-back less than what was paid? | **YES** — Rs 250,184.00 < Rs 261,269.21 |

This is the brief's own acceptance bar, met exactly: the identical item
values below what the customer paid, same day, with no way for the
original Rs 261,269.21 figure to reach the buy-back calculation at all.

---

## How to run any of these yourself

```
cd C:\laragon\www\metal-ledger-engine
php artisan tinker
```

Paste the relevant service calls from [`demo-data-manual-testing.md`](demo-data-manual-testing.md),
or just run the one-command version of all four:

```
php artisan demo:walkthrough
```

Nothing above touches your real gold-shop database — this project has
its own, separate SQLite file, and everything here rolls back after
running.
