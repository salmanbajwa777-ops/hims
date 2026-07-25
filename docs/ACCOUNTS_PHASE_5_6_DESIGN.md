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
    gross         DECIMAL(10,2) NOT NULL,
    tax           DECIMAL(10,2) NOT NULL,
    doctor_share  DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (payout_id) REFERENCES doctor_payouts(id) ON DELETE CASCADE
);
```

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

### Clawbacks

A bill voided or refunded *after* its payout is settled appears as a **negative
line on the next payout**, never as a silent edit to the settled one. The
statement gains an "adjustments from prior periods" section.

*This is the question I most want your answer on — see below.*

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

1. **Clawbacks** — a July bill voided in August, after July was paid out.
   Negative line on August's payout, or ignore it? *(Recommend: negative line.
   Ignoring means the clinic silently absorbs it.)*
2. **Payout period** — always a calendar month, or arbitrary ranges? The
   statement page already allows any range. *(Recommend: allow any, default to
   last month.)*
3. **Part-payments** — can a doctor be paid twice for one month (an advance,
   then a balance)? The current expense flow allows it and warns. *(Recommend:
   keep allowing; `already_paid` handles it.)*
4. **CNIC/NTN on `users`** — needed for a filing-ready register. Add now or
   keep the register internal-only?
5. **Who may run a payout?** Admin only, or does MANAGER get it? Note the
   segregation-of-duties argument: whoever computes the payout arguably should
   not also be able to void the bills behind it.
