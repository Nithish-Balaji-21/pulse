import paho.mqtt.client as mqtt
import json
import time
import sqlite3

# Configuration
MQTT_BROKER = "xx.xxx.xxx.xx"
MQTT_PORT = 1883
MQTT_TOPIC = "IMIKtest"
Sqlite_DB = "/var/www/coirpower/database/database.sqlite"
conn = sqlite3.connect(Sqlite_DB)
cursor = conn.cursor()


# MQTT callback function when a message is received
def on_message(client, userdata, message):
    global conn, cursor
    try:
        payload = message.payload.decode('utf-8')
        message_json = json.loads(payload)

        # Defining SQL structure for pushing data
        sql = '''INSERT INTO device_data
        (date, time, device_id, d4, d5, d6, d7, d8, d9, d10, d11, d12, d13, d14, d15)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'''

        # Prepare the item for SQLite
        values = (
            message_json.get('D1'),
            message_json.get('D2'),
            message_json.get('D3'),
            message_json.get('D4'),
            message_json.get('D5'),
            message_json.get('D6'),
            message_json.get('D7'),
            message_json.get('D8'),
            message_json.get('D9'),
            message_json.get('D10'),
            message_json.get('D11'),
            message_json.get('D12'),
            message_json.get('D13'),
            message_json.get('D14'),
            message_json.get('D15')
        )

        # Save the message to SQLite
        cursor.execute(sql, values)
        conn.commit()
        print("Data added to SQLite table")

    except Exception as e:
        print(f"Error saving message: {e}")

# MQTT callback function when connected to the broker
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("Connected successfully")
        client.subscribe(MQTT_TOPIC)
    else:
        print(f"Connection failed with code {rc}")

# MQTT callback function when the client disconnects
def on_disconnect(client, userdata, rc):
    print("Disconnected, trying to reconnect...")
    while True:
        try:
            client.reconnect()
            break
        except Exception as e:
            print(f"Reconnect failed: {e}")
            time.sleep(5)

# Setup MQTT client
client = mqtt.Client()
client.on_connect = on_connect
client.on_message = on_message
client.on_disconnect = on_disconnect

# Connect to the MQTT broker
client.connect(MQTT_BROKER, MQTT_PORT, 60)

# Start the MQTT client
client.loop_forever()
