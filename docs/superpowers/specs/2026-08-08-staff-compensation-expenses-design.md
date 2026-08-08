# Staff Compensation & Expenses — Design

**Date:** 2026-08-08
**Status:** Approved, not yet implemented

## Problem

The dashboard knows what a salon earns. It knows nothing about what a salon
spends, so it cannot tell an owner the only number that decides whether the
business survives: profit.

The largest cost is staff, and salons pay staff in three different shapes:

- **Commission** — the staff member keeps a percentage of what they bill. "You
  brought in 1000, you take 25%."
- **Fixed salary** — a flat monthly amount regardless of bookings.
- **Hybrid** — a smaller fixed salary plus a percentage on top.

Today an owner computes this by hand, per staff member, at month end, from a
report that shows earned revenue but applies no rule to it. The arithmetic is
easy; remembering each person's deal, applying it to the right window, and
keeping a record of what was actually paid is not.

Rent, utilities, and supplies are simply not recorded anywhere.

## Decisions

**Payroll is a monthly run, not a live calculation.** The owner opens a run for
a month, the system computes each staff member's pay from their rule, the owner
adjusts and finalizes. A finalized run is a record of what was paid — that
record is the point. A live "what would I owe" report cannot answer "what did I
pay Rima in June", which is the question that matters in a dispute.

**Commission is calculated on completed appointments at their snapshot price** —
`SUM(appointments.price) WHERE status = COMPLETED`, the same predicate
`ReportService::earnedWindow` already uses. Payroll and the revenue report
therefore agree by construction. The alternative, paying on cash actually
collected, is more accurate but requires the salon to record every payment
faithfully, and a booking paid across a month boundary splits in a way owners
find surprising.

**One flat commission rate per staff member.** Not per-service, not tiered. One
number the owner can explain to the staff member. Per-service rates are a real
practice in larger salons and can be layered on later without changing this
model — the payroll line already snapshots the rate it used.

**Pay rules live on `staff_profiles`, not in a versioned rules table.** A
payroll line snapshots the pay type, rate, and salary it was computed from, so a
raise never rewrites history. That is the only property a rule-history table
would have bought, and it costs three columns instead of a table plus
effective-dating plus a "which rule applied on date X" lookup.

**Finance is owner-only, reads included.** Managers and staff get 403 on every
finance route, and the pay-rule fields are stripped from the staff API for
non-owners in both directions. A manager who can see every colleague's salary is
a real-world problem, not a hypothetical one.

## Scope

In:

- Pay rule (type, salary, rate) per staff member
- Monthly payroll runs with per-staff lines, editable while draft, locked on
  finalize
- Expense log with a fixed category list
- Profit block on the existing reports endpoint and screen

Out (later, in rough order):

- Advances ledger — an advance given mid-month is logged as an expense and the
  payroll line trimmed before finalizing. A proper ledger that tracks an
  outstanding balance and auto-deducts belongs in a later pass.
- Per-service and tiered commission rates
- Recurring expense templates (rent auto-created monthly)
- Owner-defined expense categories
- Weekly or fortnightly pay periods, and salary proration
- Staff-facing payslip view
- Payslip PDF / export

## Data model

### `staff_profiles` — three new columns

```
pay_type         string, default 'none'    -- none | commission | salary | hybrid
monthly_salary   decimal(10,2), default 0  -- used by salary, hybrid
commission_rate  decimal(5,2),  default 0  -- percent, used by commission, hybrid
```

`pay_type = none` is the default for every existing staff member, and staff on
`none` are excluded from payroll runs entirely — owner-operators, contractors,
and anyone the salon settles outside the app.

`PayType` enum in `app/Enums/PayType.php`.

### `payroll_runs`

```
id
organization_id     foreignId, cascadeOnDelete
period_month        date        -- always the 1st of the month
status              string      -- draft | finalized
total_salary        decimal(10,2), default 0
total_commission    decimal(10,2), default 0
total_amount        decimal(10,2), default 0
finalized_at        timestamp, nullable
finalized_by        foreignId users, nullable, nullOnDelete
timestamps
unique (organization_id, period_month)
```

The unique index is what prevents paying the same month twice. Runs are
organization-wide, not per-branch: the free plan allows one branch, and a
staff member belongs to one branch anyway.

`PayrollRunStatus` enum in `app/Enums/PayrollRunStatus.php`.

### `payroll_lines`

```
id
payroll_run_id     foreignId, cascadeOnDelete
staff_id           foreignId users, nullable, nullOnDelete
staff_name         string            -- snapshot; survives staff deletion
pay_type           string            -- snapshot of the rule used
commission_rate    decimal(5,2)      -- snapshot
monthly_salary     decimal(10,2)     -- snapshot
earned_revenue     decimal(10,2)     -- computed, never edited
bookings           integer           -- completed count, never edited
salary_amount      decimal(10,2)     -- editable while draft
commission_amount  decimal(10,2)     -- editable while draft
total_amount       decimal(10,2)     -- salary_amount + commission_amount
timestamps
index (payroll_run_id)
```

`staff_id` is nullable with `nullOnDelete`, and `staff_name` is snapshotted, so
deleting a staff account never erases the record of what they were paid — the
same rule `payments.recorded_by` already follows.

`earned_revenue` and `bookings` stay untouched when the owner overrides an
amount, so an override is visible against the reality it departs from.

### `expenses`

```
id
organization_id   foreignId, cascadeOnDelete
branch_id         foreignId, nullable, nullOnDelete
payroll_run_id    foreignId, nullable, cascadeOnDelete
category          string
expense_date      date
amount            decimal(10,2)
note              string, nullable
recorded_by       foreignId users, nullable, nullOnDelete
timestamps
index (organization_id, expense_date)
```

`ExpenseCategory` enum: `rent, utilities, supplies, salary, marketing,
equipment, maintenance, other`.

`payroll_run_id` is set on the one salary expense a run creates when it
finalizes. It does two jobs: the P&L counts staff pay exactly once, and deleting
a run takes its salary expense with it via the cascade.

## Computation

`app/Services/PayrollCalculator.php` — pure, writes nothing. Given a month, it
returns one line per staff member whose `pay_type` is not `none`:

```
earned_revenue    = SUM(appointments.price)
                    WHERE status = COMPLETED
                      AND staff_id = X
                      AND booking_date BETWEEN month_start .. month_end
bookings          = COUNT of those appointments
commission_amount = pay_type in (commission, hybrid)
                      ? round(earned_revenue * commission_rate / 100, 2)
                      : 0
salary_amount     = pay_type in (salary, hybrid) ? monthly_salary : 0
total_amount      = salary_amount + commission_amount
```

Staff with no completed bookings still get a line when they are on `salary` or
`hybrid` — they are owed their salary. A `commission` staff member with zero
revenue gets a line totalling zero, so the run shows the whole team.

**No proration.** A staff member hired on the 20th gets the full monthly salary
on the line; the owner edits it down before finalizing. Proration rules vary by
salon and guessing wrong is worse than showing an obvious number to correct.

All money rounds to 2 decimal places at the point of computation.

## Run lifecycle

| Route | Behaviour |
|---|---|
| `POST /api/payroll/runs` | Body `{period_month}`, normalised to the 1st of that month, and rejected if it is in the future — the current month is allowed and produces a partial run. Creates a **draft** and its lines from the calculator. A second run for the same month is a 422 (the unique index is the backstop). |
| `GET /api/payroll/runs` | Runs for the org, newest month first. |
| `GET /api/payroll/runs/{run}` | Run with its lines. |
| `PATCH /api/payroll/runs/{run}/lines/{line}` | Edit `salary_amount` / `commission_amount`. **Draft only** — 422 on a finalized run. Recomputes the line total. |
| `POST /api/payroll/runs/{run}/finalize` | Recomputes run totals from lines, sets `finalized`, stamps `finalized_at` / `finalized_by`, creates the salary expense. Finalizing an already-finalized run is a 422. |
| `DELETE /api/payroll/runs/{run}` | Deletes the run, its lines, and (for a finalized run) its salary expense, by cascade. |

Finalize writes in a transaction: totals, status, and the expense row land
together or not at all.

There is no edit-after-finalize. Correcting a finalized month means deleting the
run and creating it again — a rare operation that should feel deliberate.

The salary expense a run creates: category `salary`, `expense_date` = last day
of the period month, `amount` = run `total_amount`, `payroll_run_id` = the run,
`branch_id` = null, note = "Payroll — August 2026".

## Expenses

Standard REST at `/api/expenses` (`index`, `store`, `update`, `destroy`), the
list filterable by `from`/`to`, `category`, and `branch_id`, newest first.

`amount` must be greater than zero, `category` must be a valid enum case, and
`branch_id` must belong to the tenant. Expenses may be dated in the past or
today, not the future.

Payroll-generated rows (`payroll_run_id` not null) are returned by `index` but
rejected by `update` and `destroy` with a 422 pointing at the run. They change
only when the run does.

## Profit reporting

`ReportService::build` gains a `profit` block over the range it already takes:

```
profit: {
  earned:              float,   -- already computed by summary()
  expenses_total:      float,
  expenses_by_category: [{ category, amount, share_pct }],
  net_profit:          float    -- earned - expenses_total
}
```

Expenses are summed by `expense_date` within the range. A monthly salary expense
lands on the last day of its month, so a report range that cuts a month in half
excludes it — the profit block states the range it covers and the owner reads it
as such.

The whole block is owner-only. `ReportController` omits `profit` for managers
rather than 403-ing the report, which managers legitimately use.

## Authorization

`PayrollRunPolicy` and `ExpensePolicy`, every ability owner-only including
reads, checked at class level against the bound tenant — the shape
`PaymentSettingPolicy` already uses.

Pay-rule fields need protection on the staff endpoints too:

- `StoreStaffRequest` / `UpdateStaffRequest` accept `pay_type`,
  `monthly_salary`, `commission_rate` only from an owner; a non-owner sending
  them gets them dropped, not a 422, so a manager saving an unrelated edit does
  not fail.
- `StaffResource` includes the three fields only when the requesting user is an
  owner.

`StaffResource` is used solely by the authenticated `StaffController`; the
public booking site renders staff through its own resources, so no pay data can
reach an unauthenticated response through this path.

Every finance query relies on the tenant global scope. `payroll_runs` and
`expenses` carry `organization_id` and use `BelongsToOrganization`, so a
cross-tenant id resolves to a 404 rather than another salon's payroll.

## Frontend

**Staff form** — a "Compensation" section, rendered only for owners. A pay type
radio (None / Commission / Fixed salary / Salary + commission) reveals the
fields that type needs: rate for commission, salary for fixed, both for hybrid.
Help text states the rule in words: "Rima keeps 25% of what she bills."

**`FinanceView.vue`** at `/finance`, three tabs:

- *Payroll* — month picker and a run list. Opening a run shows the line table:
  staff, bookings, earned, salary, commission, total. Amounts are inline-editable
  while draft, read-only after finalize, with a finalized badge and date. The
  Finalize button confirms before locking, naming the total.
- *Expenses* — filterable list, add/edit modal (date, category, amount, branch,
  note). Payroll rows show a lock icon and link to their run instead of an edit
  button.
- *Profit* — reuses the reports range picker: earned, expenses by category, net
  profit, with the net styled by sign.

A "Finance" sidebar entry, hidden for non-owners.

## Testing

Feature tests:

- Commission-only, salary-only, and hybrid lines compute the documented amounts
- `pay_type = none` staff are excluded from a run
- A salary staff member with zero completed bookings still gets their salary
- Cancelled and pending appointments contribute nothing to `earned_revenue`
- A second run for the same month is rejected
- Line edits are accepted on a draft and rejected on a finalized run
- Finalize creates exactly one salary expense, for the run total, dated the last
  day of the month
- Finalizing twice is rejected
- Deleting a finalized run deletes its salary expense
- Payroll-generated expenses reject update and destroy
- Managers and staff get 403 on every payroll and expense route
- A manager reading a staff record sees no pay fields; a manager writing them is
  ignored
- A payroll run and an expense from another organization 404
- The report `profit` block subtracts expenses in range and is absent for
  managers

Unit tests on `PayrollCalculator`: 2dp rounding on an awkward rate (33.33% of
1000.01), and a staff member with no appointments.
