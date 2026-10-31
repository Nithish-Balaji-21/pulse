# How the daily summary of Water Consumption is calculated?

## Step 1: Device Data Collection

- Each water meter device records its consumption using a counter.
- Data is sent every 20 seconds, and each time water is consumed, the counter increases.
- There are two main field IDs:
    - `d27` → **Total Water**
    - `d16` → **Husk Water**

## Step 2: Storage in MySQL database
- All device readings are stored in the `device_data_water_meters` table.
- Each record includes:
    - `device_id` → Which device (meter) is reporting
    - `field_id` → Which water line (d27 = total, d16 = husk)
    - `meter_date` → Date of reading
    - `meter_datetime` → Exact timestamp of reading
    - `litres_counter` → Current reading of the meter counter
    - `is_duplicate` → Marks if this reading is repeated
    - `created_at / updated_at` → Record timestamps
- The table may have a unique constraint on (`device_id`, `field_id`, `meter_date`, `litres_counter`) to prevent duplicate counts for the same reading on the same day.

## Step 3: Running the Scheduler
- The water summary job is handled by `ProcessDeviceDataForModWaterJob.php`.
- Command: `php artisan devicedata:others --modules=water`
- Runs daily at 05:15 AM
- Populates the `summary_water_meter` table

## Step 4: Data Processing

### 4.1: Remove Old Duplicates
Any records previously marked as duplicates are deleted to start fresh.

### 4.2: Mark New Duplicates
If two consecutive readings from the same device and field have the same counter value, the later one is marked as duplicate. This prevents double counting.

### 4.3: Identify Dates to Process
The job checks the `meter_date` of records and determines which dates have consumption data. If no date is given, yesterday is used by default.

### 4.4: Calculate Total Water for Each Date and Field

- For each `device_id` and `field_id` (d27 = total, d16 = husk):
    - Find the first counter of the day (earliest record)
    - Find the last counter of the day (latest record)
    - Calculate total litres:
        `total_litres = last_counter - first_counter`
    - If counters reset (last < first), total litres are calculated by adding differences between consecutive readings, handling resets.

### 4.5: Counter Calculation
- For each pair of readings:
    - If first reading, assume a minimum consumption (e.g., 10L) or 0 if logic requires.
    - If current >= previous, add the difference.
    - If current < previous, a reset is assumed; add 0 or a minimum value as per logic.

## Step 5: Store Daily Summary
- After calculating totals, the job updates or inserts records in the `summary_water_meter` table for each `device_id` and `field_id` (d27, d16).
- If a record already exists for the day, it is updated. Otherwise, a new record is created.

## Step 6: Result
At the end of the process, the `summary_water_meter` table has one record per day per device/field, showing the total litres consumed for both total water (d27) and husk water (d16). This summary is later used for reports, notifications, and dashboard display.

---

## Table Schemas

### `device_data_water_meters`

| Field            | Type         | Description                                  |
|------------------|--------------|----------------------------------------------|
| id               | int          | Primary key                                  |
| device_id        | int          | Reference to `devices` table                 |
| field_id         | varchar(50)  | Identifier for the water meter field         |
| meter_date       | date         | Date of the meter reading                    |
| meter_datetime   | datetime     | Exact timestamp of the meter reading         |
| litres_counter   | int          | Running counter of litres                    |
| is_duplicate     | tinyint(1)   | Flag for duplicate entries                   |
| created_at       | timestamp    | Timestamp of record creation                 |
| updated_at       | timestamp    | Timestamp of last record update              |

**Collection Interval:** Every 20 seconds.

### `summary_water_meter`

| Field            | Type         | Description                                  |
|------------------|--------------|----------------------------------------------|
| id               | int          | Primary key                                  |
| team_id          | int          | Foreign key to the teams table               |
| device_id        | int          | Foreign key to the devices table             |
| field_id         | varchar(50)  | Identifier for the water meter field         |
| date             | date         | Date of the consumption summary              |
| litres_consumed  | double       | Total litres consumed for the day            |
| created_at       | datetime     | Timestamp of record creation                 |
| updated_at       | datetime     | Timestamp of last record update              |