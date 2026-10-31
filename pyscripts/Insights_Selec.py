#!/usr/bin/env python3

#Importng req Libraries
import minimalmodbus
import struct
import datetime
import time
import smtplib
import ssl
import schedule
import paho.mqtt.client as mqtt
import json
import csv
import os
from datetime import date
from email.mime.multipart import MIMEMultipart
from email.mime.base import MIMEBase
from email.mime.text import MIMEText
from email import encoders

#Assigning variables
offdatasent = 0
ondatasent = 0
efficient_notification=0
overload_notification=0
ideal_notification=0
efficient_count=0
overload_count=0
normal_count=0
exception_count=0

#Threshold Limit of data
VRY_UT = 450
VRY_LT = 300
VYB_UT = 450
VYB_LT = 300
VBR_UT = 450
VBR_LT = 300
IT_UT = 170
IT_LT = 60
IR_UT = 170
IR_LT = 60
IY_UT = 170
IY_LT = 60
IB_UT = 170
IB_LT = 60


# Modbus Communication setup
mb_address = 1
instrument = minimalmodbus.Instrument('/dev/ttyUSB0', mb_address)
instrument.serial.baudrate = 4800
instrument.serial.bytesize = 8
instrument.serial.parity = minimalmodbus.serial.PARITY_NONE
instrument.serial.stopbits = 1
instrument.serial.timeout = 1
instrument.mode = minimalmodbus.MODE_RTU

instrument.clear_buffers_before_each_transaction = True
instrument.close_port_after_each_call = True

#Email Configurations for Email notification
sender = "alamcocosmodbustest@gmail.com"
password = ""
receiver = "sanjeevisomu@gmail.com"
cc_list = ["sanjeevis.imik@gmail.com"]
receivers = [receiver] + cc_list
context = ssl.create_default_context()

#UDF to check connectivity
def icon():
    if os.system("ping -c 1 8.8.8.8 > /dev/null 2>&1") == 0:
        return True

#User defined function for sending mail
def send_email(sender, password, receivers, subject, message):
    # Create a secure SSL context
    global context
    server=None
    try:
        # Establish a connection to the SMTP server
        server = smtplib.SMTP("smtp.gmail.com", 587)
        server.starttls(context=context)
        server.login(sender, password)

        # Create the email content
        msg = f"Subject: {subject}\n\n{message}"

        # Send the email
        server.sendmail(sender, receivers, msg)
        print(f"{subject}  Email sent to {receivers} \n")
    except (smtplib.SMTPConnectError, smtplib.SMTPRecipientsRefused, smtplib.SMTPDataError) as e:
        print(f"Error: {e}")
    except Exception as e:
        print("Error sending daily log file:", e,'\n')
    finally:
        if server:
            server.quit()

# Reboot Notification
try:
    with open ('/proc/uptime', 'r') as f:
        uptime_seconds = float(f.readline().split()[0])
    if uptime_seconds < 600:
        date1 = datetime.datetime.now().strftime("%d-%m-%Y")
        current_time = datetime.datetime.now().strftime("%H:%M:%S")
        subject7="PKS PI REBOOT ALERT"
        message7=f"Subject:{subject7}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\n THE RPI IN PKS IS REBOOTED"
        send_email(sender,password,receivers,subject7,message7)
except Exception as e:
    print(f"Error: {e}")

#Mqtt configuration for mqtt connection
broker_address = "xx.xxx.xxx.xx"
broker_port = 1883
topic = "IMIKtest"
client_id = "00002"
mqtt_qos = 0
mqtt_retain = False

# Callback function for MQTT connection
def on_connect(client, userdata, flags, rc):
    print("Connected with result code "+str(rc))

# Callback function for MQTT publishing
def on_publish(client, userdata, mid):
    print("Message published \n")

# Function to check and reconnect mqtt if necessary
def ensure_connection():
    if not mqtt_client.is_connected():
        try:
            mqtt_client.reconnect()
        except Exception as e:
            print(f"Reconnect failed: {e}")

#Mqtt Connection establishment
mqtt_client = mqtt.Client(client_id=client_id,clean_session=False)
mqtt_client.on_connect = on_connect
mqtt_client.on_publish = on_publish
mqtt_client.connect(broker_address,broker_port,120)

def generate_log_filename():
    current_date = datetime.datetime.now().strftime("%Y-%m-%d")
    csv_filename = f"PKS_COIIOT_log_{current_date}.csv"
    return csv_filename

csv_filename = generate_log_filename()
csv_header = ["Date", "Time", "RY Phase Voltage (V)", "YB Phase Voltage (V)", "BR Phase Voltage (V)","Total Current (A)", "R Phase Current (A)", "Y Phase Current (A)", "B Phase Current (A)","Total Watts (kW)", "R Phase Watts (kW)", "Y Phase Watts (kW)", "B Phase Watts (kW)", "Total EB (kWh)"]
try:
    with open(csv_filename, 'x', newline='') as csv_file:
        writer = csv.writer(csv_file)
        writer.writerow(csv_header)
except FileExistsError:
    # CSV file already exists
    pass

def send_daily_log():
    server1=None
    try:
        # Generate the log filename for today
        current_date = datetime.datetime.now().strftime("%Y-%m-%d")
        csv_filename =f"PKS_COIIOT_log_{current_date}.csv"

        # Check if the log file exists
        if os.path.exists(csv_filename):
            message = MIMEMultipart()
            message['From']=sender
            message['To']=','.join(receivers)
            Subject=f"Daily Log File - {current_date}"
            message['Subject']=Subject


            with open(csv_filename,'rb') as attachment:
                part= MIMEBase('application','octet-stream')
                part.set_payload(attachment.read())
            part.add_header("Content-Disposition", f"attachment; filename= {csv_filename}")
            message.attach(part)
            body = MIMEText("Please find the attached CSV file for today's log.")
            message.attach(body)
            text=message.as_string()

            if icon():
                server1 = smtplib.SMTP("smtp.gmail.com", 587)
                server1.starttls(context=context)
                server1.login(sender, password)
                server1.sendmail(sender, receivers, text)
                print(f"Daily log file sent successfully for {current_date}")
                
            else:
                attachment_data.append({"subject":Subject,"message":text})
                save_unsent_attachment(attachment_data)
                print('Email Attachment data added to Queue')
    except Exception as e:
        print("Error sending daily log file:", e)
    finally:
        if server1:
            server1.quit()

def generate_hrly_log_filename():
    current_date = datetime.datetime.now().strftime("%Y-%m-%d")
    csv_filename = f"PKS_COIIOT_hrly_log_{current_date}.csv"
    return csv_filename

csv_filename1 = generate_hrly_log_filename()
csv_header1 = ["Date", "Time", "Pwr Consumption (wh)"]
try:
    with open(csv_filename1, 'x', newline='') as csv_file1:
        writer = csv.writer(csv_file1)
        writer.writerow(csv_header1)
except FileExistsError:
    # CSV file already exists
    pass

def send_hrly_daily_log():
    server1=None
    try:
        # Generate the log filename for today
        current_date = datetime.datetime.now().strftime("%Y-%m-%d")
        csv_filename = f"PKS_COIIOT_hrly_log_{current_date}.csv"

        # Check if the log file exists
        if os.path.exists(csv_filename):
            message = MIMEMultipart()
            message['From']=sender
            message['To']=','.join(receivers)
            Subject=f"Hourly power consumption Log File - {current_date}"
            message['Subject']=Subject

            with open(csv_filename,'rb') as attachment:
                part= MIMEBase('application','octet-stream')
                part.set_payload(attachment.read())
            part.add_header("Content-Disposition", f"attachment; filename= {csv_filename}")
            message.attach(part)
            body = MIMEText("Please find the attached CSV file for Hourly power consumption.")
            message.attach(body)
            text=message.as_string()

            if icon():
                server1 = smtplib.SMTP("smtp.gmail.com", 587)
                server1.starttls(context=context)
                server1.login(sender, password)
                server1.sendmail(sender, receivers, text)
                print(f"Daily log file sent successfully for {current_date}")
                
            else:
                attachment_data.append({"subject":Subject,"message":text})
                save_unsent_attachment(attachment_data)
                print('Attachment  data added to Queue')
    except Exception as e:
        print("Error sending daily log file:", e)
    finally:
        if server1:
            server1.quit()

#Userdefined function for saving data to file 
def save_data_to_file(filename, data):
    with open(filename, 'w') as file:
        file.write(str(data))

#Userdefined function for reading data from file
def load_data_from_file(filename):
    try:
        with open(filename, 'r') as file:
            data = file.read().strip()
            if data:  # Check if data is not empty
                return float(data)
            else:
                return None
    except Exception as e:
        print(f"Error reading file {filename}: {e}")
        return None

#User defined function to calculate energy consumption
def read_energy_consumption():
    raw_data = instrument.read_registers(90, 2, 4)
    energy = struct.unpack('<f', struct.pack('<HH', *raw_data))[0]
    return round(energy,2)

# Function to check if a file exists and create it if it does not
def ensure_file_exists(filename):
    if not os.path.exists(filename):
        with open(filename, 'w') as file:
            file.write('')

# Ensure necessary files exist
files_to_check = [
    'shift_pwr.txt',
    'last_hour_energy.txt',
    'last_day_energy.txt',
    'last_week_energy.txt',
    'last_month_energy.txt'
]

for filename in files_to_check:
    ensure_file_exists(filename)

#To calculate power consumption data when script started running
if load_data_from_file('shift_pwr.txt') is None:
    initial_value = read_energy_consumption()
    save_data_to_file('shift_pwr.txt', initial_value)
if load_data_from_file('last_hour_energy.txt') is None:
    save_data_to_file('last_hour_energy.txt', read_energy_consumption())
if load_data_from_file('last_day_energy.txt') is None:
    save_data_to_file('last_day_energy.txt', read_energy_consumption())
if load_data_from_file('last_week_energy.txt') is None:
    save_data_to_file('last_week_energy.txt', read_energy_consumption())
if load_data_from_file('last_month_energy.txt') is None:
    save_data_to_file('last_month_energy.txt', read_energy_consumption())

#Shift insights
machine_offtime='machine_offtime.txt'
machine_ontime='machine_ontime.txt'

def read_datafrom_file(filename):
    try:
        with open(filename,'r') as file:
            data=file.read().strip()
            if data:
                return data
            else:
                return None
    except Exception as e:
        return None
    
def save_ondata_to_file(filename, data):
    with open(filename, 'a') as file:
        file.write(str(data)+'\n')
    
def read_ontime(filename):
    try:
        with open(filename,'r') as file:
            firstline=file.readline()
            if firstline:
                return firstline
            else:
                return None
    except Exception as e:
        return None

#Userdefined function for sending Firstshift pwr consumption
def Firstshift():
    PC=load_data_from_file('shift_pwr.txt')
    kwh_data = read_energy_consumption()

    if PC != 0:
        Unit_diffrence = kwh_data - PC
    else:
        Unit_diffrence = kwh_data
    save_data_to_file('shift_pwr.txt',round(kwh_data,2))

    Last_offtime=read_datafrom_file(machine_offtime)
    First_ontime=read_ontime(machine_ontime)
    with open (machine_ontime,'w') as file:
        pass

    #Timestamp
    date1 = datetime.datetime.now().strftime("%d-%m-%Y")
    current_time = datetime.datetime.now().strftime("%H:%M:%S")
    subject1="First Shift Insights"
    message1=f"Subject:{subject1}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\nPower consumed in first shift is : {round(Unit_diffrence,2)} wh\n\nThe first machine ON time is : {First_ontime} \n\nThe last machine OFF time is : {Last_offtime}"
    if icon():
        send_email(sender,password,receivers,subject1,message1)
    else:
        email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":subject1,"message":message1})
        save_unsent_emails(email_data)
        print('Email Notification data added to Queue \n')

# User defined function to send power consumption data of second shift
def Secondshift():
    PC=load_data_from_file('shift_pwr.txt')
    kwh_data1 = read_energy_consumption()

    if PC != 0:
        Unit_diffrence1 = kwh_data1 - PC
    else:
        Unit_diffrence1 = kwh_data1
    save_data_to_file('shift_pwr.txt',round(kwh_data1,2))

    Last_offtime=read_datafrom_file(machine_offtime)
    First_ontime=read_ontime(machine_ontime)
    with open (machine_ontime,'w') as file:
        pass

    #Timestamp
    date1 = datetime.datetime.now().strftime("%d-%m-%Y")
    current_time = datetime.datetime.now().strftime("%H:%M:%S")
    subject2="Second Shift Insights"
    message2=f"Subject:{subject2}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\nPower consumed in second shift is : {round(Unit_diffrence1,2)} wh\n\nThe first machine ON time is : {First_ontime} \n\nThe last machine OFF time is : {Last_offtime}"
    if icon():
        send_email(sender,password,receivers,subject2,message2)
    else:
        email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":subject2,"message":message2})
        save_unsent_emails(email_data)
        print('Email Notification data added to Queue \n')

def hourly_consumption():
    previous_energy = load_data_from_file('last_hour_energy.txt')
    current_energy = read_energy_consumption()
    consumption = current_energy - previous_energy
    save_data_to_file('last_hour_energy.txt', round(current_energy,2))
    date1 = datetime.datetime.now().strftime("%d-%m-%Y")
    current_time = datetime.datetime.now().strftime("%H:%M:%S")
    #subject="Hourly Power Consumption of ALAM"
    #message=f"Subject:{subject}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\nPower consumed in last hour is : {round(consumption,2)} wh"
    #if icon():
    #    send_email(sender,password,receivers,subject,message)
    #else:
    #    email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":subject,"message":message})
    #    save_unsent_emails(email_data)
    #    print('Email Notification data added to Queue')
    with open(csv_filename1, 'a', newline='') as csv_file:
        writer = csv.writer(csv_file)
        writer.writerow([date1,current_time,consumption])

def daily_consumption():
    previous_energy = load_data_from_file('last_day_energy.txt')
    current_energy = read_energy_consumption()
    consumption = current_energy - previous_energy
    save_data_to_file('last_day_energy.txt', round(current_energy,2))
    date1 = datetime.datetime.now().strftime("%d-%m-%Y")
    current_time = datetime.datetime.now().strftime("%H:%M:%S")
    subject="Daily Power Consumption of PKS"
    message=f"Subject:{subject}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\nPower consumed in last day is : {round(consumption,2)} wh"
    if icon():
        send_email(sender,password,receivers,subject,message)
    else:
        email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":subject,"message":message})
        save_unsent_emails(email_data)
        print('Email Notification data added to Queue \n')
  

def weekly_consumption():
    previous_energy = load_data_from_file('last_week_energy.txt')
    current_energy = read_energy_consumption()
    consumption = current_energy - previous_energy
    save_data_to_file('last_week_energy.txt', round(current_energy,2))
    date1 = datetime.datetime.now().strftime("%d-%m-%Y")
    current_time = datetime.datetime.now().strftime("%H:%M:%S")
    subject="Weekly Power Consumption of PKS"
    message=f"Subject:{subject}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\nPower consumed in last week is : {round(consumption,2)} wh"
    if icon():
        send_email(sender,password,receivers,subject,message)
    else:
        email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":subject,"message":message})
        save_unsent_emails(email_data)
        print('Email Notification data added to Queue \n')


def monthly_consumption():
    today = date.today()
    if today.day == 1:
        previous_energy = load_data_from_file('last_month_energy.txt')
        current_energy = read_energy_consumption()
        consumption = current_energy - previous_energy
        save_data_to_file('last_month_energy.txt', round(current_energy,2))
        date1 = datetime.datetime.now().strftime("%d-%m-%Y")
        current_time = datetime.datetime.now().strftime("%H:%M:%S")
        subject="Monthly Power Consumption of PKS"
        message=f"Subject:{subject}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\nPower consumed in last month is : {round(consumption,2)} wh"
        if icon():
            send_email(sender,password,receivers,subject,message)
        else:
            email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":subject,"message":message})
            save_unsent_emails(email_data)
            print('Email Notification data added to Queue \n')


#UDF Log the error with timestamp
def log_error(message):
    error_time = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    with open("error_log.txt", "a") as error_file:
        error_file.write(f"{error_time}: {message}\n")

# Userdefined func to read data from MFM
def read_data(register):
    raw_data = instrument.read_registers(register, 2, 4)
    data = struct.unpack('<f', struct.pack('<HH', *raw_data))[0]
    return round(data)

#For resending unsent mails

email_file = 'unsent_emails.json'
email_data=[]
def load_unsent_emails():
    try:
        if os.path.exists(email_file):
            with open(email_file, 'r') as file:
                return json.load(file)
    except json.JSONDecodeError:
        pass
    return []

def save_unsent_emails(email_data):
    with open(email_file, 'w') as file:
        json.dump(email_data, file)

def retry_unsent_emails():
    if icon():
        try:
            unsent_emails = load_unsent_emails()
            index = 0
            while index < len(unsent_emails):
                email = unsent_emails[index]
                try:
                    send_email(email["sender"], email["password"], email["receivers"], email["subject"], email["message"])
                    unsent_emails.pop(index)
                    print(f"Email sent to: {email['receivers']}")
                except Exception as e:
                    print(f"Error sending email to {email['receivers']}: {e}")
                    index += 1  # Move to the next email even if there's an error
            print("Mails resent \n")
        except Exception as e:
            print(f"Error while loading unsent emails: {e}")
        finally:
            save_unsent_emails(unsent_emails)  # Save the remaining unsent emails

            


#For resending mqtt data

mqtt_file = 'unsent_mqttdata.json'
mqttmsg_data=[]
def load_unsent_mqttdata():
    try:
        if os.path.exists(mqtt_file):
            with open(mqtt_file, 'r') as file:
                return json.load(file)
    except json.JSONDecodeError:
        pass
    return []

def save_unsent_mqttdata(mqttmsg_data):
    with open(mqtt_file, 'w') as file:
        json.dump(mqttmsg_data, file)

def retry_unsent_mqttdata():
    if icon():
        try:
            unsent_mqttdata = load_unsent_mqttdata()
            index = 0
            while index < len(unsent_mqttdata):
                data = unsent_mqttdata[index]
                mqtt_client.publish(topic, data, mqtt_qos, mqtt_retain)
                unsent_mqttdata.pop(index)
            print("MQTT msg resent \n")
        except Exception as e:
            print(f"Error sending Unsent mqtt data: {e}")
        finally:
            save_unsent_mqttdata(unsent_mqttdata)

#For resending attachment files

attachment_file = 'attachment.json'
attachment_data=[]
def load_unsent_attachment():
    try:
        if os.path.exists(attachment_file):
            with open(attachment_file, 'r') as file:
                return json.load(file)
    except json.JSONDecodeError:
        pass
    return []

def save_unsent_attachment(attachment_data):
    with open(attachment_file, 'w') as file:
        json.dump(attachment_data, file)

def retry_unsent_attachment():
    server1 = None
    if icon():
        unsent_attachment = load_unsent_attachment()
        index = 0
        
        while index < len(unsent_attachment):
            attachment = unsent_attachment[index]
            message = MIMEMultipart()
            message['From'] = sender
            message['To'] = ','.join(receivers)
            message['Subject'] = attachment['subject']
            text = attachment['message']
            try:
                server1 = smtplib.SMTP("smtp.gmail.com", 587)
                server1.starttls(context=context)
                server1.login(sender, password)
                server1.sendmail(sender, receivers, text)
                unsent_attachment.pop(index)
                print(f"Attachment file resent \n")
            except Exception as e:
                print(f"Error sending attachment: {e}")
                index += 1# Move to the next attachment even if there's an error
            finally:
                if server1 is not None:
                    server1.quit()
                save_unsent_attachment(unsent_attachment)
        
def unsent_schedule():
    retry_unsent_emails()
    retry_unsent_mqttdata()
    retry_unsent_attachment()

efficient_count=0
ideal_count=0
overload_count=0

# Scheduling Tasks 
schedule.every().day.at("17:59").do(Firstshift)
schedule.every().day.at("05:59").do(Secondshift)
schedule.every().hour.at(":59").do(hourly_consumption)
schedule.every().day.at("23:59").do(daily_consumption)
schedule.every().monday.at("23:59").do(weekly_consumption)
schedule.every().day.at("23:59").do(monthly_consumption)
schedule.every().day.at("23:59").do(send_hrly_daily_log)
schedule.every().day.at("23:59").do(send_daily_log)
schedule.every().day.at("07:00").do(unsent_schedule)

while True:
    try:
        schedule.run_pending()  # used to run pending schedule tasks
        ensure_connection()

        # Generate new log filename if date changes
        current_date = datetime.datetime.now().strftime("%Y-%m-%d")
        if not csv_filename.endswith(current_date + ".csv"):
            csv_filename = generate_log_filename()
            with open(csv_filename, 'x', newline='') as csv_file:
                writer = csv.writer(csv_file)
                writer.writerow(csv_header)
        
        if not csv_filename1.endswith(current_date + ".csv"):
            csv_filename1 = generate_hrly_log_filename()
            with open(csv_filename1, 'x', newline='') as csv_file1:
                writer = csv.writer(csv_file1)
                writer.writerow(csv_header1)
        
        # To read RY line voltage
        data = read_data(8)
        print("The RY Phase Voltage is : ", data, " v")

        # To read YB line voltage
        data1 = read_data(10)
        print("The YB phase voltage is : ", data1, " v")

        # To read BR line voltage
        data2 = read_data(12)
        print("The BR phase voltage is : ", data2, " v")

        # To read Avg Current
        data3 = read_data(22)
        print("The Total Current   is : ", data3, " A")

        # To read R Phase current
        data4 = read_data(16)
        print("The R phase Current is : ", data4, " A")

        # To read Y Phase current
        data5 = read_data(18)
        print("The Y phase Current is : ", data5, " A")

        # To read B Phase current
        data6 = read_data(20)
        print("The B phase Current is : ", data6, " A")

        # To read Total watts
        data7 = read_data(52)
        print("The Total Watts is : ", data7, " kw")

        # To read R phase watts
        data8 = read_data(24)
        print("The R phase Watts is : ", data8, " kw")

        # To read Y phase watts
        data9 = read_data(26)
        print("The Y phase Watts is : ", data9, " kw")

        # To read B phase watts
        data10 = read_data(28)
        print("The B phase Watts is : ", data10, " kw")

        # To read total EB consumption
        data11 = read_data(90)
        print("The Total EB consumption is : ", data11, " kWh\n")

        date1 = datetime.datetime.now().strftime("%Y-%m-%d")
        time1 = datetime.datetime.now().strftime("%H:%M:%S")
        mqtt_data = {"D1": date1,"D2": time1, "D3": client_id, "D4": data, "D5": data1, "D6": data2, "D7": data3, "D8": data4, "D9": data5, "D10": data6, "D11": data7, "D12": data8, "D13": data9, "D14": data10, "D15": data11}
        payload = json.dumps(mqtt_data)
        if icon():
            mqtt_client.publish(topic, payload,mqtt_qos,mqtt_retain)
        else:
            mqttmsg_data.append(payload)
            save_unsent_mqttdata(mqttmsg_data)
            print('MQTT data added to Queue \n')

        # Append data to CSV file
        with open(csv_filename, 'a', newline='') as csv_file:
            writer = csv.writer(csv_file)
            writer.writerow([date1, time1, data, data1, data2, data3, data4, data5, data6, data7, data8, data9, data10, data11])

        # Condition and code to send OFF state notification
        if data3 == 0 and data > 300 and offdatasent == 0:

            offdatasent = 1
            ondatasent = 0
            date1 = datetime.datetime.now().strftime("%d-%m-%Y")
            current_time = datetime.datetime.now().strftime("%H:%M:%S")
            save_data_to_file('machine_offtime.txt',(datetime.datetime.now()).strftime("%Y-%m-%d %H:%M:%S"))
            subject3="Power On Machine OFF notification"
            message3=f"Subject:{subject3}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\n THE MACHINES IN PKS ARE TURNED OFF"
            if icon():
                send_email(sender,password,receivers,subject3,message3)
            else:
                email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":subject3,"message":message3})
                save_unsent_emails(email_data)
                print('Email Notification data added to Queue \n')

        # Condition and code to send ON state notification
        elif data3 > 5 and ondatasent == 0:

            offdatasent = 0
            ondatasent = 1
            date1 = datetime.datetime.now().strftime("%d-%m-%Y")
            current_time = datetime.datetime.now().strftime("%H:%M:%S")
            save_ondata_to_file('machine_ontime.txt',(datetime.datetime.now()).strftime("%Y-%m-%d %H:%M:%S"))
            subject4="Machine ON notification"
            message4=f"Subject:{subject4}\n\nThe Date is : {date1}\nThe Time is : {current_time} \n\n THE MACHINES IN PKS ARE TURNED ON"
            if icon():
                send_email(sender,password,receivers,subject4,message4)
            else:
                email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":subject4,"message":message4})
                save_unsent_emails(email_data)
                print('Email Notification data added to Queue \n')

        # Conditions to check whether the readings are within threshold limit and send notification
        if (data <= VRY_UT and data >= VRY_LT) and (data1 <= VYB_UT and data1 >= VYB_LT) and (data2 <= VBR_UT and data2 >= VBR_LT) and (data3 <= IT_UT and data3 >= IT_LT) and (data4 <= IR_UT and data4 >= IR_LT) and (data5 <= IY_UT and data5 >= IY_LT) and (data6 <= IB_UT and data6 >= IB_LT):
            try:
                efficient_count+=1
                ideal_count=0
                overload_count=0

                if efficient_notification == 0 and efficient_count==3:
                    ideal_notification=0
                    overload_notification=0
                    efficient_notification = 1
                    date1 = datetime.datetime.now().strftime("%d-%m-%Y")
                    current_time = datetime.datetime.now().strftime("%H:%M:%S")
                    sub5 = "Efficient Functionality Notification"
                    msg5 = f"Subject :{sub5}\n\nThe Date is : {date1}\nThe Time is : {current_time}\n\nThe Machines in PKS are  functioning Efficiently\n\nTHE READINGS ARE : \n\nThe RY phase voltage ={data} V\nThe YB phase voltage = {data1} V\nThe BR phase voltage = {data2} V\nThe Total current = {data3} A\nThe R phase current = {data4} A\nThe Y phase current = {data5} A\nThe B phase current = {data6} A\nThe Total Watts = {data7} w\nThe R phase Watts = {data8} w\nThe Y phase Watts = {data9} w\nThe B phase Watts = {data10} w\nThe Total EB Consumption is = {data11} kWh"
                    if icon():
                        send_email(sender,password,receivers,sub5,msg5)
                    else:
                        email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":sub5,"message":msg5})
                        save_unsent_emails(email_data)
                        print('Email Notification data added to Queue \n')
            except Exception as e:
                print(e)

        # Conditions to check whether the Machiles are Running Ideal and send notification
        if (data3 < IT_LT) and (data4 < IR_LT) and (data5 < IY_LT) and (data6 < IB_LT):
            try:
                ideal_count+=1
                efficient_count=0
                overload_count=0
                
                if ideal_notification==0 and ideal_count==3:
                    efficient_notification=0
                    overload_notification=0
                    ideal_notification=1
                    date1 = datetime.datetime.now().strftime("%d-%m-%Y")
                    current_time = datetime.datetime.now().strftime("%H:%M:%S")
                    sub5 = "IDEAL load Notification"
                    msg5 = f"Subject :{sub5}\n\nThe Date is : {date1}\nThe Time is : {current_time}\n\nThe Machines in PKS are running IDEAL\n\nTHE READINGS ARE : \n\nThe RY phase voltage ={data} V\nThe YB phase voltage = {data1} V\nThe BR phase voltage = {data2} V\nThe Total current = {data3} A\nThe R phase current = {data4} A\nThe Y phase current = {data5} A\nThe B phase current = {data6} A\nThe Total Watts = {data7} w\nThe R phase Watts = {data8} w\nThe Y phase Watts = {data9} w\nThe B phase Watts = {data10} w\nThe Total EB Consumption is = {data11} kWh"
                    if icon():
                        send_email(sender,password,receivers,sub5,msg5)
                    else:
                        email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":sub5,"message":msg5})
                        save_unsent_emails(email_data)
                        print('Email Notification data added to Queue \n')
            except Exception as e:
                print(e)


        # Conditions to check whether the Machiles are Running Overload and send notification
        if (data3 > IT_UT) and (data4 > IR_UT) and (data5 > IY_UT) and (data6 > IB_UT):
            try:
                overload_count+=1
                efficient_count=0
                ideal_count=0
                
                if overload_notification==0 and overload_count==2:
                    ideal_notification=0
                    efficient_notification=0
                    overload_notification=1
                    date1 = datetime.datetime.now().strftime("%d-%m-%Y")
                    current_time = datetime.datetime.now().strftime("%H:%M:%S")
                    sub5 = "Overload Notification"
                    msg5 = f"Subject :{sub5}\n\nThe Date is : {date1}\nThe Time is : {current_time}\n\nThe Machines in PKS are Running in Overload condition\n\nTHE READINGS ARE : \n\nThe RY phase voltage ={data} V\nThe YB phase voltage = {data1} V\nThe BR phase voltage = {data2} V\nThe Total current = {data3} A\nThe R phase current = {data4} A\nThe Y phase current = {data5} A\nThe B phase current = {data6} A\nThe Total Watts = {data7} w\nThe R phase Watts = {data8} w\nThe Y phase Watts = {data9} w\nThe B phase Watts = {data10} w\nThe Total EB Consumption is = {data11} kWh"
                    if icon():
                        send_email(sender,password,receivers,sub5,msg5)
                    else:
                        email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":sub5,"message":msg5})
                        save_unsent_emails(email_data)
                        print('Email Notification data added to Queue \n')
            except Exception as e:
                print(e)

        exception_count=0
    
    except minimalmodbus.NoResponseError as e:
        # Handle Modbus no response error
        if exception_count == 1:
            outrage_time=datetime.datetime.now().strftime("%H:%M:%S")
        if exception_count < 6:
            error_message = "Modbus device did not respond: " + str(e)
            print(error_message)
            # Log the error
            log_error(error_message)
            exception_count+=1
        else:
            date2 = datetime.datetime.now().strftime("%d-%m-%Y")
            time2 = datetime.datetime.now().strftime("%H:%M:%S")
            mqtt_data = {"D1": date2,"D2": time2, "D3": client_id, "D4":0, "D5":0, "D6":0, "D7":0, "D8":0, "D9":0, "D10":0, "D11":0, "D12":0, "D13":0, "D14":0, "D15":0}
            payload = json.dumps(mqtt_data)
            if icon():
                mqtt_client.publish(topic, payload,mqtt_qos,mqtt_retain)
            else:
                mqttmsg_data.append(payload)
                save_unsent_mqttdata(mqttmsg_data)
                print('MQTT data added to Queue \n')

            # Append data to CSV file
            with open(csv_filename, 'a', newline='') as csv_file:
                writer = csv.writer(csv_file)
                writer.writerow([date2, time2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0])
            sub6 = "Power Outrage Notification"
            msg6 = f"Subject :{sub6}\n\nThe Date is : {date2}\nThe Time is : {time2}\n\nThe power supply is currently down in PKS From : {outrage_time}."
            if icon():
                send_email(sender,password,receivers,sub5,msg5)
            else:
                email_data.append({"sender":sender,"password":password,"receivers":receivers,"subject":sub5,"message":msg5})
                save_unsent_emails(email_data)
                print('Email Notification data added to Queue \n')

    except minimalmodbus.InvalidResponseError as e:
        # Handle Modbus invalid response error
        error_message = "Invalid response received from Modbus device: " + str(e)
        print(error_message)
        # Log the error
        log_error(error_message)

    except Exception as e:
        # Catch all other exceptions
        error_message = "Error: " + str(e)
        print(error_message)
        # Log the error
        log_error(error_message)
    finally:
        time.sleep(20)
