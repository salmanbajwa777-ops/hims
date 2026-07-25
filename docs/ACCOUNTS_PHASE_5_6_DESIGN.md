# Accounts Phases 5 & 6 — Design

Drafted 2026-07-26. **Not built.** Decisions are open until you say otherwise.

Phases 1–4 are live: period-matched expenses, the expense report, the unified
revenue layer (`clinic_revenue()` / `clinic_doctor_shares()`), the income
report, and the doctor share statement.

---

## The problem both phases share

**Everything today recomputes from live rows.** `doctor_share_statement.php`
runs its queries fresh on every load, and the income report does the same. That
is correct for a *view* and wrong for a *record*, because:

- A bill voided in August silently rewrites July's statement — including a July
  you have already paid out against.
- A refund issued later reduces a month that was already settled.
- A doctor's `consult_share_pct` is read live, so editing it re-prices every
  past month at the new rate.

That last one is the sharpest. Change a doctor from 70% to 75% today and every
historical statement retroactively claims you underpaid them.

**So both phases are fundamentally about freezing numbers**, not about
computing new ones. The maths already exists and is shared.

---

## Phase 5 — P&L + month-end close

### The page

`pnl_report.php`, gated on `FINANCIAL_VIEW_DAILY_PL` (third of the four dead
permission keys to get a page).

It joins what already exists — no new arithmetic:

```
Gross received (clinic_revenue)          1,000,000
− Refunds                                  −20,000
− Tax withheld (clinic_doctor_shares)     −110,000
− Doctor shares earned                    −430,000
──────────────────────────────────────────────────
Clinic income                              440,000
− Salaries          (period-matched)      −250,000
− Rent                                     −60,000
− Utilities, supplies, …                   −40,000
──────────────────────────────────────────────────
NET PROFIT                                  90,000
```

Expenses come from the expense report's existing grouping —
`COALESCE(period_month, expense_date)`, disbursements excluded via
`is_disbursement`. **Doctor Shares postings never appear as an operating cost**
— the earned share is already deducted above the line. That double-count trap
is the single most important invariant on this page.

Same three outputs as the other reports: screen, A4 print, CSV. Month mode and
range mode, matching the income report.

### The close

`monthly_closings` stores the **computed figures**, not a pointer to
recompute them:

```sql
CREATE TABLE monthly_closings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_month    DATE NOT NULL UNIQUE,   -- 1st of the month
    gross_revenue   DECIMAL(12,2) NOT NULL,
    refunds         DECIMAL(12,2) NOT NULL,
    tax_withheld    DECIMAL(12,2) NOT NULL,
    doctor_shares   DECIMAL(12,2) NOT NULL,
    clinic_income   DECIMAL(12,2) NOT NULL,
    operating_costs DECIMAL(12,2) NOT NULL,
    net_profit      DECIMAL(12,2) NOT NULL,
    stream_json     TEXT NULL,   -- per-stream + per-category breakdown snapshot
    closed_by_id    INT NOT NULL,
    closed_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reopened_at     TIMESTAMP NULL,
    reopened_by_id  INT NULL,
    reopen_reason   VARCHAR(255) NULL
);
```

`stream_json` holds the detail so a closed month can render its full breakdown
without touching live tables at all.

**Closing a month blocks back-dated postings into it.** `expenses.php` already
consults `require_day_open()`; this adds a month-level equivalent. Without it a
closed month is trivially rewritable, since `expense_date` is admin-editable.

**Reopening is admin-only, reason-required, and audit-logged.** Not offered
casually — the whole point is that a closed month stays closed.

**A closed month renders from the snapshot; an open one computes live**, with a
visible badge saying which you are looking at. Ambiguity here defeats the
purpose.

### Open questions

1. **Late arrivals into a closed month** — a July utility invoice surfacing in
   August. Land it in August (standard, simple) or reopen July? *Recommend:
   August, with a note. Reopening should be reserved for genuine errors.*
2. **Does closing a month block payout runs for it?** Probably not — you may
   close the books before paying doctors. But then a payout must not alter the
   closed month's figures, which the snapshot already guarantees.
3. **Month close vs. day close** — `shift_closings` is per-user, per-day, cash
   only. These are different objects; the month close should not require every
   day to be closed first, or one forgotten shift blocks the books.

---

## Phase 6 — Payout engine + tax register

### What exists vs. what is missing

`doctor_share_statement.php` already shows the right numbers. What it cannot do
is **record that a payout happened**. Today the only trace is a Doctor Shares
expense row: it has an amount, a doctor and a month, but no line detail — so
nothing can answer "which bills was I paid for?" six months later.

### Two tables

```sql
CREATE TABLE doctor_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_number  VARCHAR(30) UNIQUE NOT NULL,   -- DP-2026-0001
    doctor_id      INT NOT NULL,
    -- "Paid up to this date" is recorded, not inferred. The next payout for a
    -- doctor defaults to period_start = the day after their last settled
    -- period_end, so gaps and overlaps cannot be created by accident.
    period_start   DATE NOT NULL,
    period_end     DATE NOT NULL,
    gross_amount   DECIMAL(12,2) NOT NULL,   -- fees the doctor's work generated
    tax_withheld   DECIMAL(12,2) NOT NULL,
    doctor_amount  DECIMAL(12,2) NOT NULL,   -- earned after tax and split
    already_paid   DECIMAL(12,2) NOT NULL,   -- prior payouts in the same period
    net_paid       DECIMAL(12,2) NOT NULL,   -- what actually went out
    -- Rate SNAPSHOT. Without these, editing a doctor's % silently re-prices
    -- every historical payout.
    share_pct      DECIMAL(5,2) NOT NULL,
    has_tax        TINYINT(1) NOT NULL,
    tax_pct        DECIMAL(5,2) NOT NULL,
    expense_id     INT NULL,     -- the Doctor Shares row that moved the cash
    status         ENUM('draft','paid','voided') NOT NULL DEFAULT 'draft',
    paid_at        TIMESTAMP NULL,
    created_by_id  INT NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    voided_at      TIMESTAMP NULL,
    voided_by_id   INT NULL,
    void_reason    VARCHAR(255) NULL
);

CREATE TABLE doctor_payout_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_id     INT NOT NULL,
    source_type   ENUM('OPD','IPD') NOT NULL,
    source_id     INT NOT NULL,           -- bills.id or ipd_doctor_visits.id
    occurred_on   DATE NOT NULL,
    patient_name  VARCHAR(255) NULL,      -- snapshot; survives a patient merge
    -- NEGATIVE on a clawback line. Signed, so SUM(doctor_share) over a payout
    -- is always its true value with no CASE on line_kind anywhere.
    gross         DECIMAL(10,2) NOT NULL,
    tax           DECIMAL(10,2) NOT NULL,
    doctor_share  DECIMAL(10,2) NOT NULL,
    -- EARNING = work done in this payout's own period.
    -- CLAWBACK = reversal of something paid in an EARLIER period.
    line_kind     ENUM('EARNING','CLAWBACK') NOT NULL DEFAULT 'EARNING',
    -- Clawback provenance: which settled payout this reverses, and why. Without
    -- these a doctor cannot audit a deduction, and "why is my payout short?"
    -- becomes an argument instead of a lookup.
    clawback_of_payout_id INT NULL,
    clawback_reason VARCHAR(255) NULL,
    FOREIGN KEY (payout_id) REFERENCES doctor_payouts(id) ON DELETE CASCADE,
    FOREIGN KEY (clawback_of_payout_id) REFERENCES doctor_payouts(id) ON DELETE SET NULL,
    -- One clawback per source line, ever. This is the guard that stops the same
    -- voided bill being deducted on two consecutive payouts.
    UNIQUE KEY uniq_clawback (source_type, source_id, line_kind)
);
```

**`UNIQUE (source_type, source_id, line_kind)` is the load-bearing constraint.**
Clawbacks are found by re-checking every previously-paid source row for a void
or refund, and without the guard a bill voided in August would be deducted
again in September, and again in October. The unique key makes double-clawback
impossible at the database level rather than relying on query correctness.

It also permits exactly one EARNING and one CLAWBACK per source row, which is
the intended lifecycle: paid once, reversed at most once.

**Every figure is snapshotted at payout time.** A later void or refund cannot
rewrite a payout that has already been made, and rate changes apply forward
only. This is the whole point of the phase.

### The flow

1. Open the statement for a doctor and period (the page that already exists)
2. **Create payout** → writes a `draft` with one line per bill / ward round
3. Review the frozen lines
4. **Mark paid** → posts the Doctor Shares expense and links `expense_id`

Step 4 replaces today's manual expense posting, so the disbursement and its
line detail can never disagree.

### Clawbacks — DECIDED 2026-07-26

A bill voided or refunded *after* its payout is settled appears as a **negative
line on the next payout**, never as a silent edit to the settled one. Ignoring
it would mean the clinic absorbs the loss on money the doctor was already paid.

**How they are found.** When a payout is created, alongside the period's
earnings the engine scans every source row already paid on a *previous* payout
for this doctor and checks whether it has since been voided or refunded. Each
hit becomes a CLAWBACK line at the **originally paid** figures — negated. Not
recomputed: the doctor is repaid exactly what they were given, at the rate in
force then, even if their percentage has changed since.

Reversing at today's rate would be wrong in both directions — a raised
percentage would claw back more than was ever paid.

**Partial refunds** claw back proportionally: refund 40% of a bill and 40% of
that line's doctor share is reversed.

**The statement gains an "Adjustments from prior periods" section**, each line
naming the original payout number, the patient, the date and the reason. A
deduction a doctor cannot trace is a dispute waiting to happen.

#### The edge case this creates

**A clawback can exceed the period's earnings**, leaving a negative payout.
Realistic: a doctor on leave all of August, whose large July procedure is
voided in August.

The system must NOT pay a negative amount. Proposed handling:

- The payout is still created, showing negative `net_paid`
- **No expense is posted** — no cash moves
- The shortfall carries as an opening balance against the doctor's *next*
  payout, via `carried_balance` on `doctor_payouts`
- The statement says plainly: *"Rs X carried forward — deducted from your next
  payout"*

This keeps the debt visible and recoverable instead of silently written off,
and never requires anyone to hand money back.

```sql
-- on doctor_payouts
carried_in   DECIMAL(12,2) NOT NULL DEFAULT 0,  -- brought forward from last payout
carried_out  DECIMAL(12,2) NOT NULL DEFAULT 0,  -- unrecovered, goes to the next
```

*Open: is carry-forward right, or should a negative balance simply be written
off? Carry-forward is recommended — it is the only option that keeps the number
honest without demanding a refund from the doctor.*

### Tax deposit register

`tax_register.php`, gated on `FINANCIAL_VIEW_ALL_COMMISSIONS`. Per doctor, per
month: gross, tax withheld, net paid. Monthly and annual totals for filing.

**Doctors with `consult_has_tax = 0` are listed as "self-deposits", never as a
zero row.** DR SALMAN A BAJWA deposits his own tax; a zero would read as
missing data and invite someone to start withholding tax the clinic does not
owe.

Reads `doctor_payouts` (snapshots) for settled periods, not live tables — so
the register cannot drift from what was actually paid. That is exactly what a
tax filing needs.

Needs per doctor for a real filing: **CNIC / NTN**. Not on `users` today —
would be two nullable columns.

---

## Build order

1. `pnl_report.php` reading live (no migration) — proves the numbers first
2. `monthly_closings` + close/reopen once the figures look right
3. `doctor_payouts` + lines, wired to the existing statement page
4. `tax_register.php` on top of the snapshots

Deliberately: **no snapshot table gets built before its live report has been
checked against reality.** Freezing a wrong number is worse than not freezing.

---

## Questions

1. ~~**Clawbacks**~~ — **DECIDED 2026-07-26: negative line on the next
   payout.** Reversed at the ORIGINALLY PAID figures, not recomputed at today's
   rate. Guarded by `UNIQUE (source_type, source_id, line_kind)` so the same
   voided bill can never be deducted twice. See the Clawbacks section.
1a. **Carry-forward vs write-off** — still open, recommendation below.
   A clawback can exceed a period's earnings, leaving a negative payout.
   Carry-forward puts the shortfall on the NEXT payout as an opening balance
   (`carried_in` / `carried_out`) and settles it out of future earnings;
   write-off has the clinic absorb it. *Recommend carry-forward: it is the only
   choice consistent with having agreed to clawbacks at all — writing off at
   the boundary would mean clawbacks work except when they are large, which is
   exactly when they matter. Nobody is ever asked to hand money back either
   way; carry-forward just keeps the debt visible until future work covers it.*

   **Caveat that needs a rule regardless:** a doctor who LEAVES carrying a
   balance has no future payout to deduct from, so it becomes a write-off in
   practice. Design should let an admin explicitly write off a stranded balance
   with a reason — a recorded decision, never a silent default.

2. ~~**Payout period**~~ — **DECIDED 2026-07-26: monthly by default, but any
   from/to range is allowed.** Each payout records its own `period_start` /
   `period_end`, so "paid up to this date" is a fact on the record rather than
   an assumption. The next payout for a doctor defaults to starting the day
   after their last settled `period_end`, which makes gaps and overlaps
   impossible to create by accident.

3. ~~**Part-payments**~~ — **DECIDED: allowed.** A doctor may be paid more than
   once for the same period (advance, then balance). `already_paid` on the
   payout and the existing "already disbursed" warning cover it.

4. ~~**CNIC/NTN**~~ — **DECIDED: skip.** Handled manually outside the system.
   The tax register stays internal-only: per doctor, per month, gross / tax
   withheld / net paid, for whoever does the filing to work from. No identity
   columns on `users`.

5. ~~**Who may run a payout**~~ — **DECIDED: admin only.** New permission
   `FINANCIAL_RUN_PAYOUT`, granted to ADMIN. Not part of the MANAGER bundle.
   Note the consequence: admin can both void a bill and run the payout that
   claws it back, so there is no segregation of duties here — the audit log is
   the only control, which is acceptable at this clinic's size but should be a
   conscious choice rather than an oversight.
