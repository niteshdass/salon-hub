# Appointment Reminders + WhatsApp/SMS Integration — Design

**Date:** 2026-07-23
**Status:** Approved (pending spec review)

## Goal

Send each customer one pre-appointment reminder ahead of their booking, over a
per-organization-selected channel (WhatsApp or SMS), at a per-organization
configurable lead time. Ship a channel-driver abstraction with a zero-cost
`log` driver now; real WhatsApp (Meta Cloud API) and SMS drivers plug in later
behind the same interface without touching callers. Provide a Settings UI where
a salon enables reminders, picks the channel, sets lead time, and enters the
channel's connection credentials.

## Non-goals

- No real WhatsApp/SMS delivery in this iteration (log driver only). Meta Cloud
  API / SMS provider HTTP integration is a follow-up behind the same interface.
- No multi-reminder cadence (e.g. 24h + 1h). Exactly one reminder per
  appointment.
- No per-org custom message text. Fixed template with placeholders (YAGNI).
- No customer channel preference. The org picks one channel for all its
  reminders.

## Decisions (locked)

1. **Provider strategy** — agnostic channel-driver abstraction; `log` driver
   shipped now. Cheapest real path is Meta WhatsApp Cloud API (utility-template
   pricing + free conversation tier) over SMS; both are future drivers.
2. **Lead time** — configurable per org, default 24 hours.
3. **One reminder per appointment**, timezone-aware, deduped via a
   `reminder_sent_at` flag.
4. **Config storage** — dedicated `reminder_settings` table (not columns on
   `organizations`); credentials stored via encrypted cast.
5. **Message** — fixed template with placeholders.

## Data model

### New table `reminder_settings` (one row per org)

| Column            | Type                          | Notes                                        |
| ----------------- | ----------------------------- | -------------------------------------------- |
| `id`              | bigint PK                     |                                              |
| `organization_id` | bigint FK, **unique**         | one settings row per org                     |
| `enabled`         | boolean, default `false`      | master switch                                |
| `channel`         | string enum `whatsapp\|sms`   | default `whatsapp`                           |
| `lead_hours`      | unsignedSmallInteger          | default `24`                                 |
| `credentials`     | text (encrypted JSON), nullable | channel-specific keys, see below           |
| `timestamps`      |                               |                                              |

`credentials` is an `encrypted:array` cast. Shape by channel:
- WhatsApp: `{ phone_number_id, access_token, template_name }`
- SMS: `{ provider, from, api_key }`

Rationale for a separate table: credentials are secrets (encrypted at rest),
and this keeps a growing config blob off the frequently-read `organizations`
row.

### `appointments` migration

Add `reminder_sent_at` nullable timestamp. Null = no reminder sent yet; set =
claimed/sent. Also acts as the atomic dedupe guard.

## Channel driver abstraction

```
interface ReminderChannel {
    public function send(string $to, string $message): void;
}
```

- `LogReminderChannel implements ReminderChannel` — writes recipient + message
  to `laravel.log`. **The only driver registered this iteration.** Zero cost,
  fully assertable in tests.
- `ReminderChannelManager` — resolves a driver from the org's `channel` setting.
  This iteration: always returns the log driver regardless of channel value
  (the setting is still persisted and surfaced in the UI). Future
  `WhatsAppChannel` / `SmsChannel` register here with no change to callers.

## Reminder engine

`App\Services\AppointmentReminderService::dispatchDue(): void`

1. Iterate organizations whose `reminder_settings.enabled = true`.
2. For each, select **due** appointments:
   - status ∈ {`pending`, `confirmed`}
   - customer has a non-empty `phone`
   - `reminder_sent_at` IS NULL
   - appointment start (`booking_date` + `start_time`), interpreted in the org's
     `timezone`, falls within `(now, now + lead_hours]`
3. **Atomic claim** per appointment:
   `Appointment::whereKey($id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now()])`.
   Dispatch only when the update affected 1 row — this prevents a double-send if
   two hourly runs overlap.
4. Dispatch a queued `SendAppointmentReminder` job per claimed appointment. The
   job builds the message, resolves the channel via `ReminderChannelManager`,
   and calls `send()`. Failures are logged and swallowed (reminder is
   best-effort; the claim already marked it sent).

### Message template (fixed)

```
Reminder: {service} at {salon} on {date}, {time}. See you soon!
```

Placeholders filled from the appointment/service/org. `{date}`/`{time}`
formatted in the org timezone.

## Scheduler + command

- `php artisan reminders:send` → `AppointmentReminderService::dispatchDue()`.
- Register the scheduler in `bootstrap/app.php` (no `->withSchedule(...)` exists
  yet) and schedule `reminders:send` `->hourly()`.

Hourly granularity + configurable lead hours means a reminder fires within the
hour the appointment enters the lead window. Acceptable for a day-before
reminder.

## API (tenant-scoped settings)

`App\Http\Controllers\ReminderSettingController` under authenticated tenant
routes.

- `GET /settings/reminders`
  Returns `{ enabled, channel, lead_hours, has_credentials }` where
  `has_credentials` is a bool derived from whether the stored `credentials` for
  the selected channel are present. **Never returns secret values.**
- `PUT /settings/reminders`
  `FormRequest` validates: `enabled` bool, `channel` in `whatsapp,sms`,
  `lead_hours` int 1..168, `credentials.*` strings (nullable). Upsert the row.
  A blank credential field leaves the stored secret untouched (no accidental
  wipe on save when the form re-renders masked).

## Frontend

- New route `/settings` → `SettingsView.vue`, plus a nav item.
- Reminders card:
  - Enable toggle
  - Channel radio: WhatsApp / SMS
  - Lead-time number input (hours)
  - Channel-specific **Connection** subform:
    - WhatsApp: Phone Number ID, Access Token, Template name
    - SMS: From number, API key (+ provider)
  - Save button (PUT), success/error toast.
  - Honest status note: *"Reminders run in log/test mode until a live provider
    is connected."*
- Credential inputs render empty (masked); submitting empty keeps existing
  stored secret.

## Testing

Feature tests (Pest/PHPUnit, existing style):

- **Settings CRUD** — GET defaults, PUT updates, tenant-scoped isolation,
  response never contains secret credential values, blank cred field preserves
  stored secret.
- **Command / engine**:
  - dispatches for a due appointment (inside lead window, right status, phone
    present) using a fake/log channel spy
  - respects org timezone in the window calc
  - dedupe: second run does not re-send (claim guard); `reminder_sent_at` set
  - filters: disabled org → none; no phone → none; already sent → none;
    cancelled/completed/no_show → none; outside window (too far / past) → none
- **Channel manager** — resolves the log driver for both channel values this
  iteration.

## Rollout

1. Migrations (`reminder_settings`, `appointments.reminder_sent_at`).
2. Channel interface + log driver + manager.
3. Reminder service + job + command + scheduler registration.
4. Settings API + FormRequest.
5. Settings UI + nav + route.
6. Tests, then end-to-end verification (queue:work flush + log assertion +
   browser settings save).
