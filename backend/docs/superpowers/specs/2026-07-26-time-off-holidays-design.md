# Time-off & Holidays — Design

**Date:** 2026-07-26
**Status:** Approved

## Problem

The slot engine (`SlotGenerator`) only models a **recurring weekly** schedule:
staff working days/hours (`staff_profiles.working_days_json` /
`working_hours_json`) intersected with branch opening hours, minus existing
appointment conflicts and past times. There is no way to model **one-off**
unavailability:

- A stylist on vacation, out sick, taking a half-day, or on a lunch break.
- The salon (or a single branch) closed for a public holiday or renovation.

Such dates still show bookable slots and accept public bookings — a real
correctness hole in the core booking path.

## Scope

In scope:
- Per-staff time-off as datetime ranges (covers full-day, multi-day, half-day, break).
- Per-branch (or org-wide) closures as whole-day date ranges.
- Slot engine subtracts both; booking and reschedule refuse blocked slots.
- Owner/manager CRUD API + management UI.

Out of scope (later modules): reviews, reports.

## Data Model

Two purpose-built tables, both `BelongsToOrganization`-scoped.

### `staff_time_off`
| column | type | notes |
| --- | --- | --- |
| id | bigint pk | |
| organization_id | fk organizations | auto-filled + globally scoped |
| user_id | fk users | the staff member |
| start_at | datetime | inclusive |
| end_at | datetime | inclusive/exclusive per overlap rule below |
| reason | string nullable | free text (vacation, sick, break) |
| timestamps | | |

Datetime range covers every case: a full day is `00:00..23:59`, a break is
`12:00..13:00`. Validation: `end_at` after `start_at`; staff must belong to the
current organization.

### `branch_closures`
| column | type | notes |
| --- | --- | --- |
| id | bigint pk | |
| organization_id | fk organizations | auto-filled + globally scoped |
| branch_id | fk branches **nullable** | NULL = whole salon (all branches); set = one branch |
| start_date | date | inclusive |
| end_date | date | inclusive |
| reason | string nullable | free text (holiday, renovation) |
| timestamps | | |

Whole-day granularity matches how holidays work. `branch_id` nullable yields
both org-wide ("closed Christmas") and single-branch ("Uptown renovation") from
one table. Validation: `end_date >= start_date`; branch (when given) belongs to
the org.

## Slot Engine Integration

`SlotGenerator::generate(Service, User $staff, string $date, ?Branch, ?int $excludeAppointmentId)`:

1. **Branch closed?** If any `branch_closures` row for the org with
   (`branch_id` NULL **or** `branch_id` = the branch) has `$date` within
   `[start_date, end_date]` → return `[]`. A closure with a NULL branch closes
   every branch; a null `$branch` argument matches only NULL-branch closures.
2. **Staff time-off?** Load the staff member's `staff_time_off` rows that
   intersect `$date`. For each candidate start, if the service window
   `[candidate, candidateEnd]` overlaps any `[start_at, end_at]`
   (`candidate < end_at && candidateEnd > start_at`) → skip that candidate. A
   full-day row blocks every candidate ⇒ `[]`.

Both checks sit alongside the existing working-hours / conflict / past-time
logic. Two extra queries per `generate()` call (per staff+date) — acceptable.

Booking (`BookingController::book`) and reschedule already validate the chosen
time against `generate()` membership, so they refuse a blocked slot with **no
controller change** — covered by tests.

## API

Tenant group (`auth:sanctum` + `tenant`), gated to owner/manager via policy.

```
GET    staff/{staff}/time-off          list a staff member's upcoming time-off
POST   staff/{staff}/time-off          create { start_at, end_at, reason? }
DELETE staff/{staff}/time-off/{timeOff} delete (nested-ownership 404 guard)

GET    branch-closures                 list closures for the org
POST   branch-closures                 create { branch_id?, start_date, end_date, reason? }
DELETE branch-closures/{closure}       delete
```

Policies: `StaffTimeOffPolicy` and `BranchClosurePolicy` — all abilities
`isManagerOrOwner()`. Staff/branch route-model binding is org-scoped, so a
foreign id 404s (tenant isolation preserved).

## UI

- **Staff view:** per-stylist time-off list with add (datetime start/end +
  reason) and remove. Matches existing CRUD panels.
- **Branches view:** closures list scoped to a branch or "All branches"
  (org-wide), add (date range + reason) and remove.

## Testing (TDD)

- `SlotGeneratorTest`: blocks during time-off, blocks on branch closure (both
  branch-specific and org-wide NULL), allows outside the window, respects
  partial-day overlap boundaries.
- `StaffTimeOffControllerTest` / `BranchClosureControllerTest`: CRUD, owner/manager
  gate (staff 403), tenant isolation (foreign id 404), validation.
- Booking/reschedule feature tests: refuse a slot inside time-off / closure.

## Rollout

Additive migrations, nullable/new tables only — no change to existing rows.
Empty tables ⇒ engine behaves exactly as today.
