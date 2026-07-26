# Reviews & Ratings — Design

## Goal

Let a customer rate the salon after a completed appointment, show that social
proof on the public salon page, and give the owner control to hide abusive
reviews. Reviews are **verified**: each is tied to one real, completed
appointment via the booking's existing public token — no login, no fake
reviews.

## Decisions

- **Submission path:** through the existing token-based manage page
  (`manage/{token}`). Allowed only once the appointment is `completed`, and only
  once per appointment (unique).
- **Scope:** a single overall rating 1–5 plus an optional comment. Staff /
  service / branch are the appointment's, so per-salon and per-staff aggregates
  come for free.
- **Moderation:** auto-publish. A review is `published` on submission and shows
  immediately; the owner can `hide` it afterward. The public page shows only
  non-hidden reviews.

## Data

### `reviews` table (additive migration)

| column            | type                          | notes |
|-------------------|-------------------------------|-------|
| `id`              | id                            | |
| `organization_id` | foreignId, constrained, cascade | tenant, via `BelongsToOrganization` |
| `appointment_id`  | foreignId, constrained, cascade, **unique** | one review per appointment |
| `staff_id`        | foreignId nullable, users, nullOnDelete | snapshot for per-staff aggregate; survives staff deletion |
| `rating`          | unsignedTinyInteger           | 1–5 |
| `comment`         | text nullable                 | |
| `reviewer_name`   | string                        | snapshot of `customer.name` at submission |
| `status`          | string, default `published`   | `published` \| `hidden` |
| timestamps        |                               | |

Index `['organization_id', 'status']` for the public list; the unique
`appointment_id` guards double submission.

### `Review` model

- `use BelongsToOrganization`.
- fillable: organization_id, appointment_id, staff_id, rating, comment,
  reviewer_name, status.
- casts: `rating` => integer.
- relations: `appointment()`, `staff()` (User).

## API

### Public (unauthenticated, `public.tenant`)

```
GET  public/{org}/manage/{token}          existing — payload gains can_review + review
POST public/{org}/manage/{token}/review   submit { rating, comment? }
```

- `manage` payload additions:
  - `can_review`: `true` when `status == completed` and no review exists yet.
  - `review`: the existing review (id, rating, comment, created_at) or `null`.
- `POST review` (`StoreReviewRequest`):
  - rules: `rating` required int 1–5; `comment` nullable string max 1000.
  - 422 when the appointment is not `completed`.
  - 409 when a review already exists for it.
  - snapshots `staff_id` and `reviewer_name` (= `appointment.customer.name`)
    from the appointment; `status` defaults to `published`.
  - → 201 with the review resource.

### Dashboard (tenant group `auth:sanctum` + `tenant`)

```
GET    api/reviews            list, latest first; meta { count, average }
PATCH  api/reviews/{review}   toggle status (hide / unhide)
DELETE api/reviews/{review}   remove
```

- `index`: any authed user (`viewAny`). Each row carries rating, comment,
  reviewer_name, status, created_at, and light appointment context
  (service name, staff name, booking_date). Meta `average` is rounded to 1 dp
  over all of the org's reviews; `count` is the total.
- `PATCH`: owner/manager only. Body `{ status: 'hidden' | 'published' }`.
- `DELETE`: owner/manager only.
- `ReviewPolicy`: `viewAny` => true; `update` / `delete` => `isManagerOrOwner`.
- Route-model binding rides the tenant global scope, so a foreign review id
  404s.

## Public display (`SiteController`)

Payload gains:

- `rating`: `{ average, count }` over the org's non-hidden reviews
  (`average` rounded 1 dp, `count` int; `average` is `null` when count 0).
- `reviews`: latest ~20 non-hidden, each `{ id, rating, comment, name,
  created_at }` where `name` is formatted "First L." from `reviewer_name` for
  privacy.
- each `team` member gains `rating`: `{ average, count }` over that staff
  member's non-hidden reviews.

## Frontend

- **ManageBookingView:** when `can_review`, render a 1–5 star picker + optional
  comment + submit. After submission (or when `review` already present) show the
  submitted rating + comment and a thank-you, no form.
- **SalonSiteView:** aggregate stars + count near the header; a reviews section
  listing recent reviews; per-staff average stars on team cards.
- **Reviews dashboard view (new) + nav item:** table of reviews with rating,
  reviewer, service/staff, date, status; hide/unhide toggle and delete for
  owner/manager; header shows the average and count. Read-only for staff.

## Testing (TDD)

- `ReviewModelTest` / migration: unique appointment, tenant scope, casts.
- `PublicReviewSubmissionTest`: submit on a completed appointment → 201 +
  row; blocked (422) when not completed; blocked (409) on second submission;
  `manage` payload exposes `can_review` + `review`.
- `ReviewModerationTest`: owner lists with meta average/count; owner hides /
  unhides; staff cannot (403); delete; foreign review 404; tenant isolation.
- `SiteReviewsTest`: public payload aggregate + list excludes hidden; per-staff
  rating; name formatted "First L.".

## Out of scope

- Editing a submitted review, replies from the owner, per-service rating split,
  email prompts to review. Additive later.
