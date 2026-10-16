# Metal Ledger — Plain-Language Walkthrough

No code here — just what each piece does, example numbers, and what they
prove. Read this before the walkthrough; it's meant so you can explain
it in your own words without needing to show any code.

---

## 1. Weight & Purity Conversion

**What it does:** The shop uses two weight systems — grams, and the old
tola/masha/ratti/point system. This piece converts between them without
losing accuracy. It also works out how much *pure* gold or silver is
actually inside an item, based on its purity (22K, 24K, etc.).

**Example:**
- A 50.5 gram item, shown the traditional way → **4 tola, 3 masha, 7
  ratti, 64.49 point**.
- A 10 gram 22K gold item → only **9.166 grams** of that is actually
  pure gold (22K = 91.66% pure, the rest is other metals mixed in).

**What it proves:** converting back and forth between the two systems
never drifts or loses precision, even if you do it many times.

---

## 2. The Rate Book (today's price)

**What it does:** Every price the shop uses — for gold, for silver —
comes from one single place: the rate book. Prices can arrive two ways:
automatically fetched from the internet, or manually typed in by the
owner. **The owner's price always wins.**

**Example — the exact scenario a shop runs into in real life:**
| Time | What happens | Price | Is it the "live" price? |
|---|---|---|---|
| 9:00 AM | System auto-fetches | Rs 22,000/g | Yes — until... |
| 11:00 AM | Owner manually corrects it | Rs 22,400/g | **Yes — now this one** |
| 1:00 PM | System auto-fetches again | Rs 22,150/g | **No — rejected** |

**What it proves:** once the owner has corrected a price for the day, an
automatic update later that same day can never quietly overwrite it. The
shop's price stays exactly what the owner set it to, until the owner
changes it again.

---

## 3. The Movement Ledger (everything coming in / going out)

**What it does:** Every time metal moves — sold to a customer, bought
back from a customer, handed to a goldsmith (karigar) to make jewelry,
bought from a wholesaler — it's written down as one "movement." This is
how the shop knows how much metal or money each person owes, or is owed.

**Example:**
1. Sell a customer a 10g 22K chain for Rs 2,24,000.
   → She now "owes" the shop (bookkeeping-wise) **9.166g of pure gold**
   (10g × 91.66%).
2. Realize it was actually weighed wrong — should've been 10.5g, not
   10g. **Nothing gets erased or edited.** A brand-new corrected entry is
   added on top, pointing back at the mistake it's fixing.
   → New balance: **9.624g of pure gold**. The wrong 10g entry still
   exists in the records, but it's no longer counted — only the
   correction is.
3. At any time, you can ask "what did this entry originally say, and who
   fixed it, and when" — the full history is always there.

**What it proves:** mistakes are corrected by adding a new row, never by
deleting or silently changing the old one — so there's always a
complete paper trail an auditor (or the owner) could follow.

---

## 4. Buy-Back Valuation (when a customer sells gold back to the shop)

**What it does:** When a customer brings jewelry back to sell to the
shop, the shop re-weighs it today, on the spot, and prices it using
**today's buying rate** — never what the customer originally paid for
it. A small percentage gets deducted for wear and impurities (the
"deduction").

**Example:**
- Customer brings back a 22K item, re-weighed today: **20 grams**.
- Today's buying rate: **Rs 22,000/gram**.
- Shop's standard deduction: **2.5%**.
- Deduction: 20g × 2.5% = **0.5g**
- What actually gets paid for: 20g − 0.5g = **19.5g**
- Amount paid to the customer: 19.5g × Rs 22,000 = **Rs 4,29,000**

**What it proves — the important one:** there is no way, anywhere in
this system, to feed in what the customer originally paid when they
bought the item. It's not just "turned off" — the option to use that
figure doesn't exist in the code at all. This directly answers the
brief's own warning: *buying back jewelry is a brand-new purchase, never
a sale being undone.*

---

## What's NOT built yet (and that's by design)

The brief listed two "stretch goals" — zakat/historical reporting, and
karigar (goldsmith) reconciliation. These weren't built. The design note
explains *how* they'd be added later without needing to redesign
anything already here — they're left out on purpose, not forgotten.

---

## One sentence per task, if you only have 30 seconds with the PM

1. **Weights:** converts gram ↔ tola/masha/ratti/point without ever
   losing precision.
2. **Rates:** one official price per metal; the owner's manual
   correction always beats an automatic update.
3. **Ledger:** every movement of metal is recorded permanently;
   mistakes are corrected by adding a new entry, never by erasing the
   old one.
4. **Buy-back:** always priced fresh, today, off the re-weighed grams
   and today's rate — the original sale price can't reach it, by design.
