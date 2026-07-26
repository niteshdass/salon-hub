# Reports & Analytics — Design

Date: 2026-07-26
Status: Approved (design)
Roadmap: module #3 (after Time-off/holidays, Reviews & ratings)

## Goal

Give salon owners and managers a reports page that answers four questions
over a chosen date range:

1. How much did we earn, and how is it trending?
2. Which services sell?
3. How is each staff member performing?
4. What do our bookings look like (status mix, busiest times)?

## Decisions (locked)

- **Reports in v1:** Revenue over time, Top services, Staff performance,
  Bookings breakdown — all four.
- **Revenue basis:** *Earned* — the snapshot `price` on COMPLETED
  appointments. Same basis as the existing dashboard revenue figure. A
  booking earns exactly what it quoted, once completed; unpaid/deposit
  gaps are out of scope for v1 (a future "collected" basis could layer on
  the payments table).
- **Date range:** presets **and** custom. Presets: 7 days, 30 days, this
  month, last month, this year. Plus a custom from/to picker.
- **Access:** owner + manager. Staff are excluded (money-sensitive),
  mirroring how the dashboard hides revenue from stylists.

## Architecture

One endpoint, one payload (mirrors `DashboardController`): fewer
round-trips, one range to validate, one place to compute. Split per-report
only if it later grows.

### Route + access

- `GET /api/reports` inside the tenant group (`['auth:sanctum', 'tenant']`).
- Reports are not a model, so there is no policy to auto-discover. The gate
  lives in `ReportRequest::authorize()`:
  `return $this->user()->isManagerOrOwner();`
  A forbidden role gets 403 before the controller runs — the same shape as
  other role-gated FormRequests in the app.

### `ReportRequest` (validation)

- `from`, `to`: optional dates; when present, `to >= from`
  (`after_or_equal:from`).
- Absent → default to the last 30 days (`to` = today, `from` = today − 29).
- Span cap: reject ranges longer than **366 days** (422). Bounds the
  PHP-side aggregation cost.
- Range is resolved (defaults applied) into concrete `from`/`to` dates the
  controller/service consume.

### `ReportController@__invoke(ReportRequest, CurrentTenant)`

Resolves the range, calls `ReportService`, returns
`response()->json(['data' => [...]])` with keys:
`summary`, `revenue`, `top_services`, `staff`, `bookings`.

### `ReportService`

All money = earned = SUM of snapshot `price` on COMPLETED appointments in
range (`whereDate('booking_date', ...)` between from/to, status =
completed). The tenant global scope on `Appointment` isolates the org.

Methods:

- **`summary(from, to)`** → `{ earned, bookings, avg_ticket, previous: {
  earned, bookings, avg_ticket }, delta: { earned_pct, bookings_pct } }`.
  - `bookings` here = count of completed appointments (the earning ones).
  - `avg_ticket` = earned / bookings (0 when no bookings).
  - Previous window = the equal-length span immediately before `from`
    (e.g. a 30-day range compares to the prior 30 days).
  - Delta percentages: `null` when the previous value is 0 (no baseline to
    compare against) — the UI shows "—" rather than a fake ∞%.

- **`revenueSeries(from, to)`** → `{ granularity, points: [{ period,
  label, earned, bookings }] }`, zero-filled across the whole range.
  - Granularity auto: span ≤ 31 days → `day`; ≤ 182 days → `week`; else
    `month`.
  - **Computed PHP-side**: fetch the range's completed appointments
    (`booking_date`, `price`), bucket + sum in PHP. This avoids MySQL
    (`DATE_FORMAT`) vs SQLite (`strftime`) divergence and keeps tests
    accurate against the real query. Range is capped so row count is
    bounded.
  - `period` = machine key (e.g. `2026-07-24`, `2026-W30`, `2026-07`);
    `label` = human label for the axis.

- **`topServices(from, to)`** → `[{ service_id, name, bookings, earned,
  share_pct }]`, ranked by `earned` desc, top 10.
  - Grouped by `service_id` in SQL (`selectRaw('service_id, COUNT(*)
    ... SUM(price) ...')`) — group-by-id + SUM is portable across MySQL
    and SQLite. Names resolved via a `Service` lookup (tenant-scoped).
  - `share_pct` = this service's earned / total earned in range.

- **`staffPerformance(from, to)`** → `[{ staff_id, name, bookings,
  earned, rating: { average, count } }]`, ranked by `earned` desc.
  - Completed appointments grouped by `staff_id` in SQL for bookings +
    earned. Staff names resolved via a tenant-scoped `User` query
    (`organization_id` + `role = staff`; the User model carries no global
    tenant scope, so it is filtered manually).
  - `rating` = average + count of reviews for that staff whose
    `created_at` falls in the range (`null` average when none).

- **`bookingsBreakdown(from, to)`** → `{ by_status: { pending, confirmed,
  completed, cancelled, no_show }, busiest_day: { weekday, count } | null,
  busiest_hour: { hour, count } | null }`.
  - `by_status`: counts over **all** appointments in range (every status,
    zero-filled — the UI renders a fixed row of chips).
  - Busiest day/hour: computed PHP-side over non-cancelled appointments in
    range (weekday from `booking_date`, hour from `start_time`). `null`
    when there are no qualifying appointments.

### Multi-tenancy

`Appointment`, `Review` carry the `BelongsToOrganization` global scope, so
every query above is auto-isolated. `User` (staff) is not auto-scoped (it
is the auth model) → staff-name lookups filter explicitly by
`organization_id` + `role = staff`. A foreign org's data never appears.

### Portability (MySQL prod / SQLite test)

- Time bucketing (day/week/month) and weekday/hour extraction: **PHP-side**
  (fetch rows, group in PHP). DB-agnostic and testable.
- Aggregations that are pure group-by-id + COUNT/SUM (top services, staff
  performance, status counts): **SQL**, portable as written.
- Dates in SQLite are stored `Y-m-d 00:00:00`; range filters use
  `whereDate('booking_date', ...)`.

## Frontend

### Route + nav

- Route `/reports`, name `reports`,
  `meta: { requiresAuth: true, roles: ['owner', 'manager'] }`.
- Nav item "Reports" (chart icon), `roles: ['owner', 'manager']`, placed
  after Customers and before Reviews.

### `ReportsView.vue`

- **Range picker**: preset buttons (7d / 30d / This month / Last month /
  This year) + custom from/to date inputs. Any change refetches
  `GET /reports?from&to`. Default 30d on mount. Presets are computed on the
  client into concrete from/to dates.
- **Summary cards**: Total earned, Bookings, Avg ticket — each with a delta
  chip vs the prior period (green ↑ / red ↓, e.g. "+12% vs prev period";
  "—" when no baseline).
- **Revenue chart**: lightweight inline **SVG bar chart** (no dependency —
  the repo has no chart lib). One bar per `revenue.points` bucket, hover
  tooltip with period label + earned amount. X labels adapt to granularity.
- **Top services** table: service, bookings, earned, % of total.
- **Staff performance** table: staff, bookings, earned, avg rating (stars).
- **Bookings breakdown**: status chips with counts (reusing the status
  colors from `ManageBookingView`), plus "Busiest day" and "Busiest hour"
  callouts.
- Currency via `authStore.organization.currency` + `Intl.NumberFormat`
  (same helper pattern as `DashboardView`).
- Loading skeleton; empty state when the range has no completed bookings
  ("No data for this range").

Frontend is display-only. The repo has no JS test harness, so the view is
verified via `npm run build` + a browser pass, consistent with prior
modules.

## Testing (TDD, backend)

Feature tests against `GET /api/reports` with real Sanctum tokens +
`RefreshDatabase`:

- Earned revenue sums **only** completed appointments (pending/cancelled/
  no-show excluded).
- Range filtering: appointments outside from/to are excluded.
- Summary delta: previous-window comparison math; `null` delta when the
  previous window is empty.
- `revenueSeries` granularity switches (daily for a short range, monthly
  for a long one) and is zero-filled across gaps.
- `topServices` ranked by earned, correct bookings/earned, `share_pct`
  sums sensibly; tenant isolation (a foreign org's services never appear).
- `staffPerformance` bookings/earned per staff + avg rating scoped to the
  range.
- `bookingsBreakdown` status counts (all statuses, zero-filled) + busiest
  day/hour; `null` busiest values when no appointments.
- Access: staff role → 403; owner and manager → 200.
- Validation: `to` < `from` → 422; span > 366 days → 422; absent range →
  defaults to last 30 days.

## Out of scope (v1)

- "Collected" revenue basis (payments net refunds) — earned only for now.
- CSV / PDF export.
- Per-branch filtering (aggregate is org-wide across branches).
- Scheduled / emailed reports.
