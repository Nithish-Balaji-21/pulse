# How the daily summary of Pith Blocks is calculated?

### Step 1: Device Data Collection

- Each pith block-producing machine (device) records its production using a counter.
- It sends the data every 20 seconds, and every time a block is made, the counter increases.

### Step 2: Storage in MySQL database
- All device readings are stored in the `device_data_pith_blocks` table.
- Each record from the device includes:
    - `device_id` → Which machine produced the block
    - `block_date` → Date of production
    - `block_datetime` → Exact timestamp of production
    - `blocks_counter` → Current reading of the machine counter
    - `eb_units_counter` → Energy units used (optional)
    - `shift_id` → Shift during which block was produced
    - `is_duplicate` → Marks if this reading is repeated
    - `created_at / updated_at` → Record timestamps
- The table has a unique constraint on (`device_id`, `block_date`, `blocks_counter`) to prevent duplicate counts for the same block on the same day.
- This ensures that repeated readings (from glitches or retries) are not counted twice.

### Step 3: Running the Scheduler
- Job for fibre
1. **Fibre Mini Bales:**
        - Command: `php artisan devicedata:others --modules=bales`
        - Runs daily at 05:15 AM
        - Populates the `summary_fibre_mini_bales` table
  
- Every day, a cron job runs early morning (5:15 AM) to process previous day’s data.
- This is handled by the command:
    `php artisan devicedata:others --team_ids=* --modules=* --start= --end= --dates=`
- The command identifies which teams and devices have pith block modules enabled and dispatches a job for each device.

### Step 4: Data Processing
Each device has a job called `ProcessDeviceDataForModBlocksJob` which does the following:

#### 4.1: Remove Old Duplicates
Any records previously marked as duplicates are deleted to start fresh.

#### 4.2: Mark New Duplicates
If two consecutive readings from the same device have the same counter value, the later one is marked as duplicate. This prevents double counting.

**Why Duplicates Exist Despite the Unique Key?** The database only enforces a unique key on (`device_id`, `block_date`, `blocks_counter`). A reading with the same `blocks_counter` can still be inserted if it occurs on a different day (e.g., an overnight reading that duplicates yesterday's last counter). The duplicate marking logic handles these crucial edge cases using time-based comparison (`LAG` function) to ensure the count is only registered once across the entire timeline.

#### 4.3: Identify Dates to Process
The job checks the `block_date` of records from `created_at` column and determines which dates have production data. If no date is given, yesterday is used by default.

#### 4.4: Calculate Total Blocks for Each Date

- Find the first counter of the day (earliest record)
- Find the last counter of the day (latest record)
- Calculate total blocks:
    `total_blocks = last_counter - first_counter + 1`
    — except where the counter can be carried across the day boundary, in which case the day
    opens on the last reading *before* it (`total_blocks = last_counter - carried_counter`,
    with no `+1`, the carried reading having already been counted on its own day). See
    `App\Support\Pulse\CounterSeed` and `docs/iot/fibre.md` §4.4-4.5 for the shared rules.
    (`+1` because first block counts as 1)
- If counters reset (last < first), total blocks are calculated by adding differences between consecutive readings.

### Step 5: Store Daily Summary
- After calculating total blocks for a date, the job updates or inserts a record in `summary_pith_blocks` table:
    - `team_id` → Team that owns the device
    - `device_id` → Device producing the blocks
    - `date` → Date of production
    - `blocks_expected` → Total blocks produced that day
    - `blocks_in_stock` → Blocks carried over from previous day (if any)
### 5.1 Fibre mini bales
- After calculating totals, the jobs update or insert records in the summary tables
    -`summary_fibre_mini_bales` (daily, for mini bales)
- If a record already exists for the day, it is updated. Otherwise, a new record is created.

### Step 6: Result
At the end of the process, the `summary_pith_blocks` table has one record per day per team/device, showing the total number of blocks produced. and the summary tables have one record per shift for mini bales per team/device, showing the total number of bales produced, weighed, and related metrics.This summary is later used for reports, notifications, and dashboard display.

---

## Table Schemas

### `summary_fibre_mini_bales`

| Field          | Type      | Description                                  |
|----------------|-----------|----------------------------------------------|
| id             | int       | Primary Key, Auto-Increment                  |
| team_id        | int       | Foreign key to the teams table               |
| staff_id       | int       | Foreign key to the staff table               |
| device_id      | int       | Foreign key to the devices table             |
| date           | date      | Date of the summary                          |
| bales_expected | int       | Expected number of bales                     |
| bales_produced | mediumint | Actual number of bales produced              |
| bales_in_stock | mediumint | Number of bales currently in stock           |
| is_locked      | tinyint   | Flag indicating if the record is locked      |
| comments       | text      | Additional comments                          |
| created_by     | int       | User who created the record                  |
| created_at     | datetime  | Timestamp of record creation                 |
| updated_by     | int       | User who last updated the record             |
| updated_at     | datetime  | Timestamp of last record update              |

### Q&A Section

**1. What happens if the machine counter resets during the day?**

If the counter resets (e.g., 7540 suddenly becomes 10), the system doesn’t panic.
It calculates blocks by taking differences between each pair of consecutive readings and adding them together, which gives the correct total even when the device resets.

**2. What happens if there are gaps between counters?**

Consider the following example:
On Nov 10th the following counters are observed,

    08:00 → 5100
    08:20 → 5105
    09:00 → 5120

- Although the device sends every 20 seconds, network delays or signal drops may cause missing timestamps.
- The system only cares about counter movement, not missing timestamps.
- Gaps do NOT affect summary accuracy because: `total_blocks = last counter – first counter`.
- So even with gaps:
    - The counter jumped from 5105 → 5120
    - Meaning 15 blocks were produced
    - They are counted correctly

**3. Why do we run the summary job early morning (5:15 AM)?**

Because the previous day’s data is 100% complete by then. Running earlier might miss records sent late by the device. Running later would delay dashboards and reports.

**4. Can this summary be recalculated later if something goes wrong?**

Yes, because raw device data is fully stored, the summary can always be regenerated for any date. This helps when devices send late data, logic is updated, or mistaken entries are fixed.
