# Multi-Service Booking & Tips — Design

**Date:** 2026-08-08
**Status:** Approved, not yet implemented

## Problem

A booking holds exactly one service. `appointments.service_id` is a single
foreign key, `appointments.price` is that one service's price, and `end_time` is
derived from that one service's duration.

Real salon visits are not shaped like that. A customer books a cut *and* a
colour *and* a blow-dry in one sitting. Today they must either create three
separate bookings — three calendar blocks, three invoices, three confirmation
emails, and no guarantee the three land back-to-back with the same stylist — or
book one service and tell the salon the rest over WhatsApp, which is exactly the
workflow SalonHub exists to replace.

Separately, the salon has no way to record a tip. Tips are a large share of what
a stylist actually takes home, and the platform that computes their payroll is
blind to them.

## Decisions

**One visit stays one appointment.** Services become line items on the
appointment (`appointment_services`), not separate appointment rows. A visit is
one calendar block, one invoice, one confirmation, one review, one payment
ledger. The alternative — one appointment row per service linked by a
`booking_group_id` — would give per-service staff for free, but it silently
triples every count in the product (bookings today, payroll `bookings`, dashboard
totals) and forces cancel, reschedule, invoice, and review to all become
group-aware.

**One staff member performs the whole visit, back-to-back.** Total duration is
the sum of the line durations, occupying one contiguous block. `SlotGenerator`,
`AppointmentScheduler::hasConflict`, the calendar, and the policies are unchanged
in shape — they only ever needed a duration, and now the duration is a sum.
Per-service stylists are a real practice and can be layered on later; they
require chained slot search across staff and per-line time windows, which is a
different project.

**Line items snapshot name, price, and duration.** Same reasoning the existing
`appointments.price` column already documents: the invoice must show what was
quoted, not a menu price that has since changed. The snapshot also means
a line's `service_id` can be `nullOnDelete` rather than the `cascadeOnDelete`
that `appointments.service_id` carries today. `ServiceController::destroy` keeps
its existing refusal — a service with bookings against it still cannot be
deleted, and the owner is still told to set it inactive — so `nullOnDelete` is
defense in depth, not a new workflow: it guarantees that no future path (a
direct database delete, a relaxed guard) can erase visit history. The guard's
"has appointments" check moves from `appointments.service_id` to the line table.

**`appointments.price` stays the total.** It is denormalized as the sum of the
lines rather than replaced by a join. Every existing consumer — `balanceDue()`,
`PayrollCalculator`, `ReportService::earnedWindow`, the invoice subtotal — keeps
working untouched, and revenue reporting continues to reconcile against a single
column.

**All line writes go through one action.** `AppointmentServiceWriter` syncs the
lines, recomputes `price`, and recomputes `end_time`. Dashboard create, dashboard
edit, and public book all call it, so the three paths cannot drift on how a total
is computed.

**Tips are counter-only and separate from the balance.** `payments.tip_amount`
sits alongside `amount`; `amount` remains strictly the money applied to the
booking balance, so `balanceDue()` is untouched. Only `PaymentController::store`
(a team member recording money at checkout) ever sets it. The public deposit
flow does not offer a tip: a tip is decided after the service, not before it.

**Tips go 100% to the staff member, outside the commission base.** A payroll line
gains `tips_amount`, added into `total_amount` but excluded from `earned_revenue`
and from the commission calculation. Commissioning a tip would mean the salon
keeps a cut of money the customer handed to the stylist.

**Tips are attributed on the same window and status as revenue.** The payroll
tips query filters verified payments joined to *completed* appointments by
`booking_date` within the month — deliberately identical to the revenue base, so
payroll and the revenue report still cannot disagree.

## Scope

In:

- `appointment_services` line-item table, with backfill from `appointments.service_id`
- Multi-service selection on the public booking site, the customer's manage-booking
  view, and the dashboard appointment create/edit forms
- Staff filtering by intersection (staff who can perform *all* selected services)
- Duration, price, deposit, and conflict detection driven by line sums
- `payments.tip_amount`, entered at checkout by staff
- Tips on the invoice and in payroll; `payroll_lines.tips_amount`
- `ReportService::topServices` grouped over line items

Out:

- Per-service staff assignment within one visit
- Per-line quantity (booking the same service twice in one visit)
- Customer-facing tips in the public/gateway payment flow
- Reordering line items in the UI (order is the order they were picked)
- Per-service commission rates

## Data Model

### New: `appointment_services`

```
id
appointment_id   FK -> appointments, cascadeOnDelete
service_id       FK -> services, nullable, nullOnDelete
name             string        -- snapshot
price            decimal(10,2) -- snapshot
duration         int (minutes) -- snapshot
sort_order       unsigned int
timestamps
index(appointment_id)
```

No `organization_id` and no tenant scope of its own: a line is only ever reached
through its appointment, which is scoped by `BelongsToOrganization` — the same
arrangement `payroll_lines` already uses.

### Changed: `appointments`

- `price` — unchanged column, now defined as `SUM(appointment_services.price)`.
- `end_time` — now `start_time + SUM(appointment_services.duration)`.
- `service_id` — backfilled into one line per existing appointment, then dropped.

Two migrations, not one: create-table-plus-backfill, then drop-column. The
backfill is then reviewable and re-runnable on its own, and the column survives
long enough to verify against.

### Changed: `payments`

- `tip_amount` decimal(10,2) default 0.

`amount` keeps its meaning (money against the balance). Total cash taken at the
counter is `amount + tip_amount`.

### Changed: `payroll_lines`

- `tips_amount` decimal(10,2) default 0.

### Models

- `Appointment::lines()` — `HasMany(AppointmentService::class)`, ordered by `sort_order`.
- `Appointment::services()` — `BelongsToMany(Service::class, 'appointment_services')` for reporting reads.
- `Appointment::service()` — removed, along with the `service_id` fillable entry.
- `AppointmentService` — no tenant trait, `$fillable` covering the snapshot columns.

## Components

**`AppointmentServiceWriter`** (new, `app/Actions/`)

```
sync(Appointment $appointment, array $serviceIds): void
```

Loads the tenant-scoped services in the given order, replaces the appointment's
lines with fresh snapshots, then writes back `price` (sum of line prices) and
`end_time` (start plus sum of line durations). The single place a total is
defined.

**`SlotGenerator::generate()`** — first parameter changes from `Service $service`
to `int $durationMinutes`. It only ever read `$service->duration`. Callers pass
the line sum.

**`AppointmentScheduler`** — unchanged. It already takes explicit start/end times.

**`PayrollCalculator`** — gains a tips aggregate keyed by `staff_id`:

```
verified payments
  join appointments on payments.appointment_id
  where appointments.status = completed
    and appointments.booking_date between month start and end
  group by appointments.staff_id
  sum payments.tip_amount
```

`PayrollLine::totalFor($salary, $commission, $tips)` gains a third argument;
`recomputeTotal()` and the manual-override path follow it.

**`ReportService::topServices`** — groups over `appointment_services` joined to
completed appointments in the window: `SUM(aps.price)` as earned, `COUNT(*)` as
bookings. `bookings` now counts *lines*, not visits — a three-service visit
contributes one to each of three services, which is the intent of the report.
Because the meaning changes, the report's column header becomes "Services
booked" rather than "Bookings".
Names come from the line snapshot, so deleted services still report correctly.
`earnedWindow` and every other revenue figure stay on `appointments.price`, so
the report totals continue to reconcile.

**`BookingNotifier`** — templates list every service and the total instead of a
single service name.

## API

**Public**

- `GET /services/{service}/staff` → `GET /staff?service_ids[]=` — returns staff
  who can perform **all** the listed services (intersection of `staff_services`).
  The existing fallback survives: when no service in the salon has any staff
  assignment, return every active staff member, so an unconfigured salon is
  still bookable.
- `GET /slots` — `service_id` → `service_ids[]`; duration is the sum.
- `POST /book` — `service_id` → `service_ids[]`. The in-transaction availability
  re-check is unchanged; it receives the summed duration. The deposit is computed
  from the line total via the existing `depositFor()`.

**Dashboard**

- `POST /appointments`, `PATCH /appointments/{id}` — `service_id` → `service_ids[]`.
- `POST /appointments/{id}/payments` — accepts `tip_amount`.
- `GET /appointments/{id}/invoice` — `line_items` is one entry per service line;
  adds `tips` and `total_collected`.

**Validation**

- `service_ids` — `required, array, min:1, distinct`, each entry a tenant-scoped
  `exists` on `services` (the same `Rule::exists(...)->where('organization_id', ...)`
  shape already used).
- `tip_amount` — `nullable, numeric, gte:0, max:99999999.99`.
- `amount` on a payment relaxes from `gt:0` to `gte:0`, with a rule that at least
  one of `amount` / `tip_amount` must exceed 0. Without this, a customer whose
  balance is already settled cannot leave a tip.

**`AppointmentResource`** — the `service` object is replaced by `services[]`
(`id, name, price, duration`) plus a `duration` total. No dual field and no
deprecation window: every frontend reader is updated in the same change.

## UI

**Public booking (`PublicBookingView`)** — step one becomes multi-select service
cards with a sticky summary bar: *n services · total minutes · total price*.
Changing the selection resets the downstream steps (staff, date, slots), reusing
the reset rule already in place for a single service change. The staff step
queries the intersection endpoint; its empty state becomes "No one can do all of
these — try removing a service." Confirmation lists every line plus the total.

**Manage booking (`ManageBookingView`)** — lists every line and the total. The
service set is fixed on reschedule; the customer picks a new slot at the stored
total duration.

**Dashboard appointment form** — the service select becomes a multi-select with
the same running total. Editing an existing booking re-runs conflict detection
against the new total duration and rejects on overlap, exactly as it does today
when the single service changes.

**Checkout (record payment)** — a Tip field beside Amount. The invoice shows
service lines, subtotal, payments, tips, and balance due.

**Payroll table** — a Tips column between Commission and Total.

## Error Handling

- Empty or all-invalid `service_ids` → 422 from validation, as any missing
  required field.
- A cross-tenant service id → 422 from the tenant-scoped `exists` rule, never a
  leak of whether the id exists elsewhere.
- No staff can perform the whole selection → the staff step returns an empty
  list and the UI tells the customer to drop a service. Not an error state.
- The summed duration no longer fits the working day → the slots endpoint
  returns no slots, the existing empty-slot UI applies.
- A slot taken between viewing and submitting → the existing in-transaction
  re-check returns the existing 422; the longer duration makes this more likely,
  which is exactly why the re-check already exists.
- `amount: 0` with `tip_amount: 0` → 422.
- Deleting a service that past bookings used → still refused with the existing
  422 and message; the guard now checks the line table. A service deleted by
  some other path leaves its lines intact with `service_id` null.

## Testing

Feature tests, written first:

- A multi-service booking derives `end_time` from the summed duration and `price`
  from the summed prices.
- Conflict detection blocks a booking that overlaps the *full* summed block, not
  just the first service's window.
- The staff endpoint returns only staff who can perform every selected service,
  and still falls back to all active staff when the salon has no assignments.
- Deleting a service that has lines against it is still refused with 422; a
  service force-deleted at the database level leaves its lines with a null
  `service_id` and an intact name/price snapshot.
- The backfill migration converts each existing `service_id` into exactly one
  line with matching name, price, and duration, and leaves `price` unchanged.
- A tip-only payment (`amount: 0`, `tip_amount: 5`) is accepted; `amount: 0` with
  no tip is rejected.
- `balanceDue()` and `amountPaid()` ignore `tip_amount`.
- The invoice returns one line item per service, and `total_collected` equals
  `amount_paid + tips`.
- Payroll adds tips to `total_amount` while `earned_revenue` and
  `commission_amount` exclude them.
- `topServices` attributes a three-service visit to three services.

Frontend component tests cover the multi-select summary total and the downstream
reset on selection change.
