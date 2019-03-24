# infinipoll10k
PV Logger for Infinisolar 10k hybrid and compatible inverters

This php script (put it in /etc/infinipoll10/) logs values from Voltronics Infinisolar 10k and compatible hybrid pv inverters via a ETH-RS232 converter connected to the inverters serial port. It is mainly developed for displaying values in 123solar and meterN.
Anyhow, all logged values are stored in directory set in variabe "$tmp_dir".
IP and TCP port of the ip-serial converter have to be set in parameter "$moxa_ip" and "$moxa_port". It has to be configures in "TCP-Server" mode.
The script queries inverters type, serial number and firmware versions and stores it in variable $CMD_INFO.
Main problem for this (and many other invertes) is, that the total KWH counter is in KW only. 123solar needs it more accurate in WH. I solved this with query total counter (in KWH) and daycounter (in WH) and then just add WH without KWH from daycounter to total counter. This is the base for all calculations and this counter will be increased the next day with the value stored from the day before. Off course this includes a slight difference if server gets started new and there are no values in temp. memory "$tmp_dir".
$debug when set to true, will create a logfile stored in file named in variable "$logfilename".
The main loop querying values from pv, grid and battery will be redone after vaiable "$warte_bis_naechster_durchlauf" in seconds is awaited.
Alarms get called every 100 loops and stored in file ALARM.txt, even at nighttime. Battery infos are updated during night time too and can be added as sensors in meterN to see live and historical values.

Any improvement is very welcome.
