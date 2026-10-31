# IoT Data Handling Documentation

## Overview

This document serves as a central hub for understanding how IoT data is collected, processed, and managed within the CoiioT system. The system is divided into several modules, each with its own specific data handling processes. For detailed information, please refer to the module-specific documentation linked below.

## Shift day and the anchor

Every factory has a 24-hour "shift day" starting at a per-team **shift-day anchor** — the `SHIFT_DAY_ANCHOR` row in `pulse_settings` (default `08:00:00`; commonly 06:00–08:00 per factory). Shifts in `pulse_shifts` store only physical clock times (`start_time`, `end_time`); whether a time falls on the "next calendar day" is derived at runtime from the anchor, never stored (the old `starts_next_day` / `ends_next_day` columns were dropped in July 2026).

All shift math uses in-memory offsets — minutes since the anchor: `offset(t) = (t − anchor + 1440) % 1440`, giving 0..1439 for starts and 1..1440 for ends (an end equal to the anchor means 1440, the end of the day). The helpers live on `App\Models\Pulse\Shift`: `offsetsFor()`, `containsMoment()` (start-inclusive, end-exclusive), `windowOn($date)`, `startsNextDay()` / `endsNextDay()`, and `Shift::anchorForTeam($teamId)`. Schedule validation (max 3 shifts, no overlaps, no anchor crossing) is in `App\Services\Pulse\ShiftScheduleService`.

The nightly processing jobs resolve each shift's physical window with `windowOn()` against the team's **current** anchor and freeze the attribution by writing `shift_id` onto raw device rows (start-inclusive, end-exclusive — a boundary reading belongs to the later shift; readings in coverage gaps stay `NULL`). Changing the anchor is rejected if any active shift would cross the new boundary, so historical windows never silently become invalid.

## Husks stock reconciliation (database triggers)

Pith production and husk stock are kept in sync by **database triggers and a stored procedure**, not application code — none of this is visible in `app/`, testable by Pest, or tied to Eloquent model events. It fires directly at the MySQL layer on any INSERT/UPDATE/DELETE against the tables involved, including raw `DB::table()` writes and manual SQL/backfills.

- `pulse_summary_pith_productions` has four `AFTER` triggers (`trg_reconcile_after_insert_expected`, `trg_reconcile_after_insert_produced`, `trg_reconcile_after_update_production`, `trg_reconcile_after_delete_production`) that call `CALL reconcile_husks_stock(team_id, shift_date)` whenever `cft_produced` / `cft_expected` changes on a non-locked (`is_locked = 0`) row.
- The `reconcile_husks_stock(p_team_id, p_start_date)` stored procedure walks every `pulse_husks_daily_stock` row for that team **from `p_start_date` onward, in date order**, and for each date:
  1. Sums `cft_produced` (falling back to `cft_expected` if produced is null) across that day's `pulse_summary_pith_productions` rows.
  2. Sets `opening_stock` to the previous day's freshly-recomputed `closing_stock` (or leaves the existing `opening_stock` if this is the first row in the walk).
  3. Recomputes `closing_stock = opening_stock + husks_received + husks_correction - (cft * HUSKS_PER_CFT)`, using the team's `HUSKS_PER_CFT` setting (`pulse_settings`, module `pith`) as the husks-per-CFT conversion factor.
  4. Carries the new `closing_stock` forward as `opening_stock` for the next date — **this cascades forward through every subsequent day's stock row, not just the changed date.** Editing a pith production record from a month ago rewrites `closing_stock` for every day between then and today.
- `pulse_husks_daily_stock` separately has its own `BEFORE UPDATE` trigger (`trg_reconcile_before_update_stock`) that independently recomputes `closing_stock` with the identical formula on every update to that table. When the procedure above updates a row, this trigger just re-derives the same value from the pending `NEW.*` fields (redundant but harmless). It's the **only** enforcement path for manual/direct edits to `pulse_husks_daily_stock` (e.g. hand-correcting `husks_correction`) that don't go through the procedure at all.

**Practical implications:**
- A single pith production edit can cascade an `UPDATE` across many `pulse_husks_daily_stock` rows — don't assume a production edit only touches "today's" stock row.
- Bulk/backfill writes to `pulse_summary_pith_productions` still fire these triggers per row; there's no application-level way to skip them during a backfill.
- `HUSKS_PER_CFT` (renamed 2026-07-22 from the legacy `HUSKS_PER_LOAD`, which pre-dated the CFT unit migration) holds the husks-per-CFT conversion factor.

## Module-Specific Documentation

-   [**Pith Module IoT Data Handling](./iot/pith.md)**
    -   **Focus**: Detailed Knowledge Base article on "How the daily summary of Pith Blocks is calculated?", covering device data collection, storage, scheduler, data processing (including duplicate handling and counter resets), and daily summary storage.

-   [**Fibre Module IoT Data Handling](./iot/fibre.md)**
    -   **Focus**: Handling 120kg bale production data and aggregating production metrics per shift.
    -   **Key Processes**: Processing raw `device_data_fibre_bales` entries, robust counter reset detection and handling for bales produced and weight.
    -   **Key Jobs**: `ProcessDeviceDataForFibreJob` (120kg bale production), `ProcessDeviceDataForModBalesJob` (fibre bale production, manages counter resets for `bales_expected`).
    -   **Main Tables**: `device_data_fibre_bales`, `device_data_fibre_mega_bales_weight`, `summary_fibre_mini_bales`, `summary_fibre_mega_bales`.

-   [**Water IoT Data Handling](./iot/water.md)**
    -   **Focus**: Processing device data from water meters, calculating water consumption, and accurately handling counter resets.
    -   **Key Processes**: Cleaning duplicate raw device data, aggregating counter readings, detecting and correcting counter resets.
    -   **Key Job**: `ProcessDeviceDataForModWaterJob` (processes `device_data_water_meters` to populate `summary_water_meter`).
    -   **Main Tables**: `device_data_water_meters`, `summary_water_meter`.

---

Last updated: 2026-07-21