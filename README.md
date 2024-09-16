# infinipoll10k
PV Logger for Infinisolar 10k hybrid and compatible inverters

This php script (put it in /etc/infinipoll10/) logs values from Voltronics Infinisolar 10k and compatible hybrid pv inverters via a ETH-RS232 converter connected to the inverters serial port. It is mainly developed for displaying values in 123solar and meterN.

Anyhow, all logged values are stored in textfiles named value.txt in directory set in variabe "$tmp_dir", eg. DCINV1.txt for PV string 1 dc input voltage.

IP and TCP port of the ip-serial converter have to be set in parameter "$moxa_ip" and "$moxa_port". It has to be configures in "TCP-Server" mode. Tested with USR-TCP-232 and Moxa NPort 5210.
The script queries inverters type, serial number and firmware versions and stores it in variable $CMD_INFO.
Main problem for this (and many other invertes) is, that the total KWH counter is in KW only. 123solar needs it more accurate in WH. I solved this with query total counter (in KWH) and daycounter (in WH) and then just add WH without KWH from daycounter to total counter. This is the base for all calculations and this counter will be increased the next day with the value stored from the day before. Off course this includes a slight difference if server gets started new and there are no values in temp. memory "$tmp_dir".
I tried to fix this in function hourspowertoday() as good as I could.

$debug when set to true, will create a logfile stored in file named in variable "$logfilename".
$debug2 will output additional debug infos on the cli when script get started here
$storage_stat when set will create a file logging battery charge/discharge power for efficiency calculations. See script batteff.php for more.

The main loop continously querying values from pv, grid and battery will be repeated after variable "$warte_bis_naechster_durchlauf" expires.

Alarms get called every 100 loops and stored in file ALARM.txt, even at nighttime. Battery infos are updated during night time too and can be added as sensors in meterN to see live and historical values.

Added some helping scripts:
  batteff.php - Calculate battery efficency from logged values
  battery_calibration_10k.php - Calibrate inverter battery port voltage measurement
  infinipoll10k_bms.php - add BMS fed from script readmqttbms.php
  notladen_ein.php/notladen_aus.php - Enable emergency charging of batteries from grid
  
Any improvements are very welcome.
