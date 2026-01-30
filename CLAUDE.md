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
