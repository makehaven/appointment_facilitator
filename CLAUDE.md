# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Module Overview

The **Appointment Facilitator** module provides a flexible appointment booking system for Drupal. Core features:

- **Dynamic Capacity Calculation**: Appointment capacity is the minimum of badge capacities and facilitator profile limits
- **Join Workflow**: Authenticated users can join appointments via CSRF-protected forms
- **Arrival Tracking**: Integrates with access control logs to track facilitator attendance
- **Statistics & Reporting**: Admin reports at `/admin/reports/appointment-facilitator` and self-serve stats at `/facilitator/stats`

## Commands

```bash
# Backfill arrival status for appointments from access logs
drush appointment-facilitator:backfill-arrivals --start=2025-01-01 --end=2025-03-31
drush appointment-facilitator:backfill-arrivals --force  # Overwrite existing values

# Clear cache after config changes
drush cr
```

## Architecture

### Content Type: `appointment`

Key fields on the appointment node:
- `field_appointment_timerange` (Smart Date) - When the appointment occurs
- `field_appointment_date` (Date) - Legacy fallback date field
- `field_appointment_host` (Entity reference) - The facilitator user
- `field_appointment_badges` (Entity reference) - Required badges/topics (taxonomy)
- `field_appointment_attendees` (Entity reference) - Users who joined
- `field_appointment_status` (List) - Status including `canceled`
- `field_appointment_purpose` (List) - Appointment purpose
- `field_appointment_result` (List) - Outcome tracking
- `field_facilitator_arrival_status` (List) - `on_time`, `late_grace`, `late`, `missed`
- `field_facilitator_arrival_time` (Datetime) - First access scan time

### Capacity System

Capacity is computed in `appointment_facilitator_effective_capacity()`:
1. Collects `field_badge_capacity` from each referenced badge term
2. Collects `field_coordinator_capacity` from the facilitator's profile
3. Returns the minimum of all values (defaults to 1 if none set)

### Service Layer

| Service | Purpose |
|---------|---------|
| `AppointmentStats` | Aggregates statistics per facilitator with optional date range and purpose filters |

### Controllers

| Controller | Route | Purpose |
|------------|-------|---------|
| `StatsController` | `/admin/reports/appointment-facilitator` | Admin dashboard with filterable stats |
| `FacilitatorStatsController` | `/facilitator/stats` | Self-serve stats for current user |

### Forms

| Form | Route | Purpose |
|------|-------|---------|
| `SettingsForm` | `/admin/config/people/appointment-facilitator` | Module configuration |
| `JoinAppointmentForm` | `/appointment/{node}/join` | Join button on appointment nodes |
| `CancelUpcomingAppointmentForm` | `/appointment/my-next/cancel` | Quick cancel for facilitators |

### Hooks

- `hook_entity_view()` - Adds capacity summary and Join CTA to appointment full view
- `hook_node_presave()` - Syncs timerange from legacy slots, populates date from timerange
- `hook_cron()` - Backfills arrival status from access control logs
- `hook_entity_form_display_alter()` - Ensures Smart Date widget has duration defaults
- `hook_navigation_menu_link_tree_alter()` - Normalizes menu link icon options

### Integration Points

**Access Control Logs**: Arrival tracking requires:
1. `access_control_api_logger` module enabled
2. `access_control_log` entity type with `access_control_request` bundle
3. `field_access_request_user` field on the log entity

**Profile Module**: Facilitator capacity requires:
- Profile bundle (default: `coordinator`) with `field_coordinator_capacity`
- `field_coordinator_hours` (Smart Date) for term-based rate calculations

**Badges Vocabulary**: Badge capacity requires:
- Taxonomy vocabulary (default: `badges`) with `field_badge_capacity`

## Configuration

Settings at `/admin/config/people/appointment-facilitator`:
- `show_always_join_cta`: Show Join button even when capacity is 1
- `badges_vocab_machine_name`: Vocabulary for badges (default: `badges`)
- `facilitator_profile_bundle`: Profile bundle for facilitators (default: `coordinator`)
- `arrival_grace_minutes`: Minutes after start that count as grace period (default: 5)
- `arrival_pre_window_minutes`: Minutes before start to check for scans (default: 30)
- `arrival_backfill_days`: Days to scan on cron for arrival status (default: 7)

## Permissions

- `view appointment facilitator reports` - Access admin statistics dashboard
- `view own facilitator stats` - Access personal facilitator statistics
- `cancel own facilitator appointments` - Use quick-cancel tool

## Key Implementation Details

### Smart Date vs Date Range

The module uses Smart Date when available, falling back to core Date Range. The install/update hooks manage this automatically via `_appointment_facilitator_ensure_timerange_and_displays()`.

### Arrival Status Classification

```php
// In hook_cron and drush command
$status = _appointment_facilitator_classify_arrival($start, $end, $scan, $grace_minutes);
// Returns: 'on_time', 'late_grace', 'late', or 'missed'
```

### Who may join an appointment (tag-alongs)

`appointment_facilitator_is_open_to_join($node)` is the single rule; every Join
surface (JoinAppointmentForm build + submit, /appointments row CTA, dashboard
cards, badge next-steps "join a session" list) calls it. An explicit
`field_appointment_open_to_join` value wins; empty follows the purpose: badge
checkouts are open (joiner still needs the badge pending), every other purpose
is closed (ledger #42473). The booking form shows "Keep this session
one-on-one" for checkouts and "Let other members join this session" for other
purposes (`#states`); `_appointment_facilitator_build_open_to_join_field()`
is an `#entity_builders` callback (form-level `#submit` never runs for the node
Save button) and stores only choices that depart from the purpose default.
The field itself is site config (`config/field.*.field_appointment_open_to_join.yml`).

### Statistics Aggregation

`AppointmentStats::summarize()` accepts options:
- `host_id` - Filter to specific facilitator
- `purpose` - Filter by appointment purpose
- `include_cancelled` - Include canceled appointments
- `use_facilitator_terms` - Use profile term dates for rate calculations

## Views Shipped

- `appointments` - General appointment listing
- `scheduled_appointments` - Public schedule view
- `scheduled_appointment_slots` - Slot-based availability
- `appointment_count_report` - Admin reporting
- `appointment_user_review` - User-facing appointment history

## Update Hooks

Updates 9007-9016 handle field migrations, Smart Date configuration, and menu link icon normalization. All are idempotent and safe to re-run.

### Pending badges after an appointment

`PendingBadgeReminder` (service `appointment_facilitator.pending_badge_reminder`)
emails each facilitator once a day about appointments they hosted, ended at
least `pending_badge_reminder_after_hours` (24) ago and within
`pending_badge_reminder_window_days` (7), where the booker or a joiner still has
a pending `badge_request` for a badge the appointment lists. One reminder per
appointment, ever (key-value `appointment_facilitator.pending_badge_reminded`).
Cron only sends while `pending_badge_reminder_enabled` is true (ships off).
Preview or send by hand: `drush appointment-facilitator:pending-badge-reminders [--send]`.
Wording is `PendingBadgeReminder::compose()` (pure, unit-tested). The email
links to the dashboard.

`/facilitator/dashboard` opens with a sign-off card (`buildSignoffCard()`, data
from `PendingBadgeReminder::forHost()`): the same match, emailed or not, one
row per member with links to their profile, each appointment (with its door
record) and `/badges/pending/user/{uid}`. All appointments from the last
`pending_badge_dashboard_days` (90) are listed. Older ones, back to
`pending_badge_older_days` (365), only when the member scanned in during the
appointment; the rest are counted, not listed. A row leaves the card when the
badge stops being pending, ages out, or gets an outcome (below).

### Outcomes when a badge is not approved

Each card row has "Not approving?" buttons: No-show, Needs another session,
Didn't happen (`AppointmentOutcomes`, table
`appointment_facilitator_badge_outcome`, update 9027; one row per appointment
and member, with who recorded it). Route
`/facilitator/badge-outcome/{member}/{outcome}?nids=` (CSRF token; host of
each appointment or staff with `view appointment facilitator reports`; `undo`
deletes). The badge request stays pending so the member can rebook; the row
leaves the card and the email (`pendingRows()` filters recorded pairs). Undo
lives in "You marked recently" (14 days) on the card: a token generated in
the controller's status message did not validate, so do not put the undo link
there. Cache tag `appointment_facilitator_badge_outcome` is invalidated on
record/clear. The approval page box shows a recorded outcome ("Marked No-show
by X on Nov 24") and the booker's own `field_appointment_result` report
("Member reported: met, session went well"), which also shows on the card and
in the email. Only the booker can file that report, never joiners.

### Door log as evidence for a checkout

`AppointmentDoorEvidence` (service `appointment_facilitator.door_evidence`)
reads the member's `access_control_log` scans on the appointment's day:
`in_window` (from an hour before the start to the end), `same_day`, `none`, or
`unknown` (no log table / no time). Evidence, not proof: a scan is not a pass,
and a member can walk in behind someone. It is shown in the reminder email,
on the dashboard, and above each pending request on the badge approval page
(`_appointment_facilitator_badge_request_door_check()` finds the member's latest
past appointment for that badge, any host). When the log disagrees
(`same_day`/`none`), setting Active there needs the "I checked this member out
in person" box, and the quick Approve link needs `?door_confirmed=1` (the
button is relabelled "Approve anyway"); both log a notice. No appointment on
record = nothing to compare, no extra step.

The "Approve pending badges" button on appointment cards shows only when a
participant has a pending request (`membersWithPendingRequests()`, one query
per page). `/appointments/all` pages past appointments, 100 a page.
