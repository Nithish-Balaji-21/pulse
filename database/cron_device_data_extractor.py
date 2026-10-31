import sqlite3

connection = sqlite3.connect("database.sqlite")
cursor = connection.cursor()

cursor.execute("SELECT DISTINCT date FROM device_data ORDER BY date ASC")
dates = cursor.fetchall()

for row_data in dates:
    device_data_date = row_data[0]

    curr_off_secs = 0
    curr_idle_secs = 0
    curr_optimal_secs = 0
    curr_overload_secs = 0
    curr_missing_secs = 0

    previous = None

    cursor.execute("SELECT time, d7 FROM device_data WHERE date=? ORDER BY time ASC", (device_data_date,))

    for record in cursor:
        time_str, current = record
        h, m, s = map(int, time_str.split(':'))
        present = h * 3600 + m * 60 + s

        if previous is None:
            previous = present
            continue

        diff = present - previous

        if diff <= 64:
            if current < 5:
                curr_off_secs += diff
            elif 6 <= current <= 95:
                curr_idle_secs += diff
            elif 96 <= current <= 175:
                curr_optimal_secs += diff
            else:
                curr_overload_secs += diff
        else:
            if current < 5:
                curr_off_secs += 64
            elif 6 <= current <= 95:
                curr_idle_secs += 64
            elif 96 <= current <= 175:
                curr_optimal_secs += 64
            else:
                curr_overload_secs += 64

            curr_missing_secs += diff - 64

        previous = present

    cursor.execute('''
        INSERT INTO device_data_daily (date, device_id, machine_off_hrs, machine_idle_hrs, machine_optimal_hrs, machine_overload_hrs, machine_missing_hrs)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (date, device_id, shift) DO UPDATE SET
        machine_off_hrs = excluded.machine_off_hrs, 
        machine_idle_hrs = excluded.machine_idle_hrs,
        machine_optimal_hrs = excluded.machine_optimal_hrs,
        machine_overload_hrs = excluded.machine_overload_hrs,
        machine_missing_hrs = excluded.machine_missing_hrs
    ''', (
        device_data_date,
        '',
        round(curr_off_secs / 3600,2),
        round(curr_idle_secs / 3600,2),
        round(curr_optimal_secs / 3600,2),
        round(curr_overload_secs / 3600,2),
        round(curr_missing_secs / 3600,2)
    ))

connection.commit()
connection.close()
