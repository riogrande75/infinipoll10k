#!/usr/bin/php
<?php
//Allg. Einstellungen
$moxa_ip = "192.168.x.y";	//ETH-Serial converters IP (TCP_Server mode)
$moxa_port = 20110;			//TCP-Port des ETH-Serial converters 
$moxa_timeout = 10;
$warte_bis_naechster_durchlauf = 2;	//Zeit zw. zwei Abfragen in Sekunden
$tmp_dir = "/tmp/inv1/";			//Speicherort/-ordner fuer akt. Werte -> am Ende ein / !!!
if (!file_exists($tmp_dir)) {
        mkdir("/tmp/inv1/", 0777);
        }
$tmp_dir_backup = "/home/user/inv1/"; // Ordner für tgl. Backups der Files, bitte anpassen
if (!file_exists($tmp_dir_backup)) {
        mkdir("/home/user/inv1/", 0777);
        }
$error = [];
$schleifenzaehler = 0;

//Logging/Debugging Einstellungen:
$debug = 1;         //Debugausgaben und Debuglogfile
$debug2 = 1;        //advanced debugging CLI only
$storage_stat=1;	//Batterielade/Entladestatistiken ein
$log2console = 0;
$fp_log = 0;
$script_name = "infinipoll10k.php";
$logfilename = "/etc/infinipoll10k/log/infinipoll_10k_";     //Debugging Logfile

//Initialisieren der Variablen:
$is_error_write = false;
$totalcounter = 0;
$daybase = 0;
$daybase_yday = 0;
$daypower_old = 0;

//Syslog oeffnen
openlog($script_name, LOG_PID | LOG_PERROR, LOG_LOCAL0);
syslog(LOG_ALERT,"**INFINIPOLL 10K Neustart**");

if($debug) $fp_log = @fopen($logfilename.date("Y-M-d").".log", "a");
if($debug) logging("**INFINIPOLL 10K Neustart**");

// Get model,version and protocolID for infini_startup.php
// Modell  abfragen
$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout); // Open connection to the inverter
// ProtokollID abfragen
fwrite($fp, "^P003PI".chr(0x0d)); //QPI Protocol ID abfragen 6byte
$byte=fgets($fp,10);
$protid = substr($byte,5,2);
if($debug2) echo "ProtocolID: ".$protid."\n";
// Serial abfragen
fwrite($fp, "^P003ID".chr(0x0d)); //QPI Protocol ID abfragen 6byte
$byte=fgets($fp,32);
$serial = substr($byte,8,14);
if($debug2) echo "Serial: ".$serial."\n";
// CPU Version abfragen
fwrite($fp, "^P004VFW".chr(0x0d));
$byte=fgets($fp,22);
$version = substr($byte,5,14);
if($debug2) echo "CPU Version: ".$version."\n";
// CPU secondary Version abfragen
fwrite($fp, "^P005VFW2".chr(0x0d));
$byte=fgets($fp,24);
$version2 = substr($byte,6,15);
if($debug2) echo "CPU secondary Version: ".$version2."\n";
// Modell  abfragen
fwrite($fp, "^P003MD".chr(0x0d));
$byte=fgets($fp,42);
$modelcode = substr($byte,6,3);
if($modelcode="000") $model="MPI Hybrid 10KW/3P";
$modelva = substr($byte,10,6);
$modelpf = substr($byte,17,2);
$modelbattpcs = substr($byte,34,2);
$modelbattv = substr($byte,37,2);
if($debug2)
{
        echo "Modell: ".$model."\n";
        echo "VA: ".$modelva."\n";
        echo "PowerFactor: ".$modelpf."\n";
        echo "BattPCs: ".$modelbattpcs."\n";
        echo "BattV: ".$modelbattv."\n";
}
// Infos collected, write it to info File
$CMD_INFO = "echo \"$model\nSerial:$serial\nSW:$version\nProtokoll:$protid\n$version\n$version2\"";
if($debug2) echo $CMD_INFO."\n";
write2file_string($tmp_dir."INFO.txt",$CMD_INFO);

//get date+time and set current time from server
//  P002T<cr>: Query current time
fwrite($fp, "^P002T".chr(0x0d));
$byte=fgets($fp,24);
$zeit = substr($byte,7,14);
$syszeit= date("YmdHis");
if($debug) logging("DEBUG: aktuelle Systemzeit: ".$syszeit);
if($debug) logging("DEBUG: aktuelle Zeit im WR: ".$zeit);
if(substr($zeit,0,11)!=substr($syszeit,0,11))
        {
        if($debug) logging("**** ALARM: UHRZEIT im WR PASST NICHT *****");
        // NEU 10.02.2020
        //^S016DATyymmddhhffss<cr>: Set date time
        //Response: ^1<CRC><cr> or ^0<CRC><cr>
        $datum=date('ymdHis');
        if($debug) logging("**** UHRZEIT WIRD ANGEPASST*****");
        echo "DATUM im Server:".$datum."\n";
        fwrite($fp, "^S016DAT".$datum.chr(0x0d));
        $byte=fgets($fp,7);
        echo "RESP:".$byte."\n";
        //exit;
        }
// Get todays power from hours counters
$hourspower = hourspowertoday();
if($debug) logging("DEBUG: Today's power calced from hourscounter: ".$hourspower." Wh");
//Get a starting value for PV_GES:
if (file_exists($tmp_dir_backup."PV_GES_yday.txt"))
{
        $daybase = file_get_contents($tmp_dir_backup."PV_GES_yday.txt");
        if($debug) logging("START:Daybase from PV_GES_yday.txt backup file: ".$daybase);
}
if($daybase==0)
{
        if($debug) logging("START:Daycounter not valid - get it from inverter");
        //Get total-counter from inverter
        fwrite($fp, "^P003ET".chr(0x0d)); // ^P003ET<cr>: Query total generated energy
        $byte=fgets($fp,16);
        $totalcounter = substr($byte,6,8);
        if($debug) logging("START:Got KwH_total from inverter: ".$totalcounter." kWh");
        // Get today's generated power
	$daypower=getdaycounter();
        if($debug) logging("START: Got WH_today from INV: ".$daypower." Wh");
        $daytemp = $daypower/1000 - ((int)($daypower/1000));
        $pv_ges = ($totalcounter*1000)+($daytemp*1000); // in KWh
        if($debug) logging("START: Calcualted PV_GES at start: ".$pv_ges." Wh");
        $daybase = $totalcounter - ((int)($daypower/1000)); // Total counter - int digits of daypower
        if($debug) logging("START: Daybase at start: ".$daybase." Wh");
}
// Gettin initial daybase_old value
	$daypower=getdaycounter();
	$daypower_old = $daypower; //set ininital value for daybase_old
        if($debug) logging("START: daypower_old was set to ".$daypower." Wh");


fclose($fp);
// MAIN LOOP
while(true)
{
        $err = false;
        $schleifenzaehler++;
        if($schleifenzaehler==200) //Query alarms about every 10 minutes
        {
                getalarms();
		echo "Nach getalarms\n";
                $schleifenzaehler=0;
                continue;
        }
        if($debug && $fp_log) @fclose($fp_log);
        if($debug) $fp_log = @fopen($logfilename.date("Y-M-d").".log", "a");    //Write a single file! -> rotation!
        $err = false;

        //Setup connection to Serial2ETH converter
        $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
        if (!$fp)
        {
                logging("**ERROR: Fehler beim Verbindungsaufbau: $errstr ($errno)");
                sleep($warte_bis_naechster_durchlauf);
                continue;
        }
        stream_set_timeout($fp, 10); // shorten TCP timer for answers from 60 sec to 10
        // midnight + 5min, set daypower_old to zero
        if(date("Hi")=="0005")
        {
                if($debug) logging("DEBUG: New day, daycounter reset to zero");
		$daypower = 0;
                $daypower_old = 0;
        }
        // at 11pm, save totalcounter as daybase_yesterday
        if(date("Hi")=="2359")
        {
                if($debug) logging("DEBUG: 23:59 Uhr Abends, Tageszähler wird gespeichert");
                $daybase_yday = file_get_contents($tmp_dir."PV_GES.txt");
                if($debug) logging("DEBUG: DAYBASE_YESTERDAY: ".$daybase_yday." KWh");
                write2file($tmp_dir_backup."PV_GES_yday.txt",$daybase_yday);
        }
        // at 1am, read totalcounter from file PV_GES_yday as daybase:
        if(date("Hi")=="0100")
        {
                if($debug) logging("DEBUG: Ein Uhr Morgens, Tageszähler wird neu gesetzt");
                $daybase = file_get_contents($tmp_dir_backup."PV_GES_yday.txt");
                if($debug) logging("DEBUG: DAYBASE-Counter: ".$daybase." KWh");
        }
        //Query inverter mode
        // ^P004MOD<cr>: Query working mode
        fwrite($fp, "^P004MOD".chr(0x0d));
        $byte=fgets($fp,11);
        $modus = substr($byte,5,2);
        switch ($modus)
        {
                case "00":
                $modusT="PowerOn";
                break;
                case "01":
                $modusT="StandBy";
                break;
                case "02":
                $modusT="Bypass";
                break;
                case "03":
                $modusT="Battery";
                break;
                case "04":
                $modusT="Fault";
                break;
                case "05":
                $modusT="Hybrid (Line mode, Grid mode)";
                break;
                case "06":
                $modusT="Charge";
                break;
                default:
                $modusT="unknown";
                break;
        }
        if($modus=="00" || $modus=="01" || $modus=="02")
        {
                if($debug) logging("=====>>>>WR ist im ".$modusT."-Modus, daher sind Abfragen verboten!");
                getalarms();
		sleep(10);
		$alles = gettotalcounter();
		if($debug) logging("=====>>>> TOTAL:".$alles." KWH gelesen <<<====");
		sleep(300); //Warte 5 Minuten, weil Nachts eh nicht viel passiert
                continue;
        }
        if($modus=="04") // WR im im Fehlermodus
        {
                fclose($fp);
                getalarms();
                if($debug) logging("**ERROR: WR im FAULT-STATUS!!! Fehler siehe ALARM.txt!");
                sleep(60); //Warte 1 Minuten weil Nachts eh nix passiert
                continue;
        }
        if($debug) logging("================================================");
        if($debug) logging("DEBUG: Modus: ".$modusT);

        //HAUPTABFRAGE send Request for Records, see "Infini-Solar 10KW protocol 20150702.xlsx"
        // ^P003GS<cr>: Query general status
        fwrite($fp, "^P003GS".chr(0x0d));
        $byte=fgets($fp,115);
        $pv1volt = (substr($byte,5,4)/10);
        $pv2volt = (substr($byte,10,4)/10);
        $pv1amps = (substr($byte,15,4)/100);
        $pv2amps = (substr($byte,20,4)/100);
        $battvolt = (substr($byte,25,4)/10);
        $battcap = substr($byte,30,3);
        $battamps = (substr($byte,34,6)/10);
        $gridvolt1 = (substr($byte,41,4)/10);
        $gridvolt2 = (substr($byte,46,4)/10);
        $gridvolt3 = (substr($byte,51,4)/10);
        $gridfreq = (substr($byte,56,4)/100);
        $gridamps1 = (substr($byte,61,4)/10);
        $gridamps2 = (substr($byte,66,4)/10);
        $gridamps3 = (substr($byte,71,4)/10);
        $outvolt1 = (substr($byte,76,4)/10);
        $outvolt2 = (substr($byte,81,4)/10);
        $outvolt3 = (substr($byte,86,4)/10);
        $outfreq = (substr($byte,91,4)/100);
        //$outamps1 = (substr($byte,96,4)/10);
        //$outamps2 = (substr($byte,101,4)/10);
        //$outamps3 = (substr($byte,106,4)/10);
        $intemp = substr($byte,99,3);
        $maxtemp = substr($byte,103,3);
        $batttemp = substr($byte,107,3);
        if($debug2)
        {
                echo "SolarInput1: ".$pv1volt."V\n";
                echo "SolarInput2: ".$pv2volt."V\n";
                echo "SolarInput1: ".$pv1amps."A\n";
                echo "SolarInput2: ".$pv2amps."A\n";
                echo "BattVoltage: ".$battvolt."V\n";
                echo "BattCap: ".$battcap."%\n";
                echo "BattAmp: ".$battamps."A\n";
                echo "GridVolt1: ".$gridvolt1."V\n";
                echo "GridVolt2: ".$gridvolt2."V\n";
                echo "GridVolt3: ".$gridvolt3."V\n";
                echo "GridFreq: ".$gridfreq."Hz\n";
                echo "GridAmps1: ".$gridamps1."A\n";
                echo "GridAmps2: ".$gridamps2."A\n";
                echo "GridAmps3: ".$gridamps3."A\n";
                echo "OutVolt1: ".$outvolt1."V\n";
                echo "OutVolt2: ".$outvolt2."V\n";
                echo "OutVolt3: ".$outvolt3."V\n";
                echo "OutFreq: ".$outfreq."Hz\n";
                //echo "OutAmps1: ".$outamps1."A\n";
                //echo "OutAmps2: ".$outamps2."A\n";
                //echo "OutAmps3: ".$outamps3."A\n";
                echo "InnerTemp: ".$intemp."°\n";
                echo "CompMaxTemp: ".$maxtemp."°\n";
                echo "BattTemp: ".$batttemp."°\n";
        }
	// ^P003PS<cr>: Query power status
	fwrite($fp, "^P003PS".chr(0x0d));
        $byte=fgets($fp,83);
        $pv1power = substr($byte,7,4);
        $pv2power = substr($byte,13,4);
        $gridpower1 = substr($byte,25,4);
        $gridpower2 = substr($byte,30,4);
        $gridpower3 = substr($byte,35,4);
        $gridpower = substr($byte,40,5);
        $apppower1 = substr($byte,46,4);
        $apppower2 = substr($byte,51,4);
        $apppower3 = substr($byte,56,4);
        $apppower = substr($byte,61,5);
        $powerperc = substr($byte,65,3);
        $acoutact = substr($byte,69,1);
        if($acoutact=="0") $acoutactT="disconnected";
        if($acoutact=="1") $acoutactT="connected";
        $pvinput1status = substr($byte,71,1);
        $pvinput2status = substr($byte,73,1);
        $battcode_code = substr($byte,75,1);
        if($battcode_code=="0") $battstat="Leerlauf";
        if($battcode_code=="1") $battstat="Laden";
        if($battcode_code=="2") $battstat="Entladen";
        $dcaccode_code = substr($byte,77,1);
        if($dcaccode_code=="0") $dcaccode="donothing";
        if($dcaccode_code=="1") $dcaccode="AC-DC";
        if($dcaccode_code=="2") $dcaccode="DC-AC";
        $powerdir_code = substr($byte,79,1);
        if($powerdir_code=="0") $powerdir="donothing";
        if($powerdir_code=="1") $powerdir="input";
        if($powerdir_code=="2") $powerdir="output";

	//Query daycounter
	$daypower=getdaycounter();

        if($daypower < $daypower_old || $daypower > $daypower_old + 100 ) // wrong daycounter received
	{
		if($debug) logging("**ERROR: Wrong Daypower received: ".$daypower." while old daypower was: ".$daypower_old);
		$err = true;
		//get daypower from hour values
		$daypower = hourspowertoday();
		$daypower_old = $daypower;
		if($debug) logging("**ERROR: Daypower_old was set to summed hour powers ");
	}
        if($debug2)
        {
                echo "PV1_Power: ".$pv1power."W\n";
                echo "PV2_Power: ".$pv2power."W\n";
                echo "GridPower1: ".$gridpower1."W\n";
                echo "GridPower2: ".$gridpower2."W\n";
                echo "GridPower3: ".$gridpower3."W\n";
                echo "GridPower: ".$gridpower."W\n";
                echo "ApperentPower1: ".$apppower1."W\n";
                echo "ApperentPower2: ".$apppower2."W\n";
                echo "ApperentPower3: ".$apppower3."W\n";
                echo "ApperentPower: ".$apppower."W\n";
                echo "AC-Out: ".$acoutactT."\n";
                echo "PowerOutputPercentage: ".$powerperc."%\n";
                echo "BatteryStatus: ".$battstat."\n";
                echo "DC-AC Power direction: ".$dcaccode."\n";
                echo "LinePowerDirection: ".$powerdir."\n";
                echo "Power Today: ".$daypower."\n";
        }
        if($err) //If any error appeared, flush data and collect again
        {
                fclose($fp);
                sleep($warte_bis_naechster_durchlauf);
                if($debug) logging("ERROR: ERROR HAPPENED!!! Flush data and redo!");
                continue;
        }

        //Add collected values to correct variables:
        $pv_ges = ($daybase+($daypower/1000)); // in KWh
        if($debug) logging("DEBUG: PV_GES: ".$pv_ges);
		if((int)$pv2power < "0" || (int)$pv2power > 10000) $pv2power == 0;
        $GRID_POW = ($pv1power+ $pv2power); // Aktuelle Leistung wird von DC genommen
        $ACV1   = $gridvolt1;
        $ACV2   = $gridvolt2;
        $ACV3   = $gridvolt3;
        $ACC1  = round($gridamps1,6);
        $ACC2  = round($gridamps2,6);
        $ACC3  = round($gridamps3,6);
        $ACF   = $gridfreq;
        $INTEMP= $intemp;
        $BOOT = $maxtemp;
        $DCINV1 = $pv1volt;
        $DCINV2 = $pv2volt;
        $DCINC1 = $pv1amps;
        $DCINC2 = $pv2amps;
        $DCPOW1  = $pv1power;
        $DCPOW2  = $pv2power;
        $BATTPOWER = ($battvolt*$battamps);

        if($debug) logging("DEBUG Wert ACV1: $ACV1");
        if($debug) logging("DEBUG Wert ACV2: $ACV2");
        if($debug) logging("DEBUG Wert ACV3: $ACV3");
        if($debug) logging("DEBUG Wert ACC1: $ACC1");
        if($debug) logging("DEBUG Wert ACC2: $ACC2");
        if($debug) logging("DEBUG Wert ACC3: $ACC3");
        if($debug) logging("DEBUG Wert ACF: $ACF");
        if($debug) logging("DEBUG Wert INTEMP: $INTEMP");
        if($debug) logging("DEBUG Wert BOOT: $BOOT");
        if($debug) logging("DEBUG Wert DCINV1: $DCINV1");
        if($debug) logging("DEBUG Wert DCINV2: $DCINV2");
        if($debug) logging("DEBUG Wert DCINC1: $DCINC1");
        if($debug) logging("DEBUG Wert DCINC2: $DCINC2");
        if($debug) logging("DEBUG Wert DCPOW1: $DCPOW1");
        if($debug) logging("DEBUG Wert DCPOW2: $DCPOW2");
        if($debug) logging("DEBUG Wert BATTV: $battvolt");
        if($debug) logging("DEBUG Wert BATTCHAMP: $battamps");
        if($debug) logging("DEBUG Wert BATTCAP: $battcap");
        if($debug) logging("DEBUG Wert BATTPOWER: $BATTPOWER");
        if($debug) logging("DEBUG Wert POWERDIR: $powerdir");
		if($debug) logging("DEBUG: ges. PV in KWh: $pv_ges");
        if($debug) logging("DEBUG: akt. Leistung in Watt: $GRID_POW");
        if($debug) logging("DEBUG: Batterie Status: $battstat");
		if($debug) logging("DEBUG: daypower_old: $daypower_old");
        if($debug) logging("DEBUG: daypower:     $daypower");
		if($debug) logging("DEBUG: daybase: $daybase");

        //schreibe akt. Daten in Files, die wiederum von 123solar drei Mal pro Sek. abgefragt werden:
        $ts = time();   //akt. Timestamp abfragen!
        write2file($tmp_dir."PV_GES.txt",$pv_ges);
        write2file($tmp_dir."ACV1.txt",$ACV1);
        write2file($tmp_dir."ACV2.txt",$ACV2);
        write2file($tmp_dir."ACV3.txt",$ACV3);
        write2file($tmp_dir."ACC1.txt",$ACC1);
        write2file($tmp_dir."ACC2.txt",$ACC2);
        write2file($tmp_dir."ACC3.txt",$ACC3);
        write2file($tmp_dir."ACF.txt",$ACF);
        write2file($tmp_dir."INTEMP.txt",$INTEMP);
        write2file($tmp_dir."BOOT.txt",$BOOT);
        write2file($tmp_dir."DCINV1.txt",$DCINV1);
        write2file($tmp_dir."DCINV2.txt",$DCINV2);
        write2file($tmp_dir."DCINC1.txt",$DCINC1);
        write2file($tmp_dir."DCINC2.txt",$DCINC2);
        write2file($tmp_dir."DCPOW1.txt",$DCPOW1);
        write2file($tmp_dir."DCPOW2.txt",$DCPOW2);
        write2file($tmp_dir."GRIDPOW.txt",$GRID_POW);
        write2file($tmp_dir."BATTV.txt",$battvolt);
        write2file($tmp_dir."BATTCAP.txt",$battcap);
        write2file($tmp_dir."BATTCHAMP.txt",$battamps);
        write2file($tmp_dir."BATTPOWER.txt",$BATTPOWER);
		write2file($tmp_dir."POWERDIR.txt",$powerdir);
        write2file_string($tmp_dir."STATE.txt",$modusT);
        write2file_string($tmp_dir."BATTSTAT.txt",$battstat);
        write2file_string($tmp_dir."BATTCODE.txt",$dcaccode);
		write2file_string($tmp_dir."ts.txt",date("Ymd-H:i:s",$ts));
        //for getting stats from storage we write battery power into extra file
	if($storage_stat)
		{
		$storage_dat = date("Ymd-H:i.s")." ".$BATTPOWER." ".$battcap;
		if($debug) logging ("DEBUG: storage_dat: $storage_dat");
		write2file_log($tmp_dir."STORAGESTAT.txt",$storage_dat);
	}
	$daypower_old = $daypower;
        //Verbindung zu WR abbauen
        fclose($fp);
        // Warte bis naechster Durchlauf
        sleep($warte_bis_naechster_durchlauf);
}
// END OF MAIN LOOP

// Div. Funtionen zur Datenaufbereitung
function hex2str($hex)
{
    $str = '';
    for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
    return $str;
}
function cal_crc_half($pin)
{
        $sum = 0;
        for($i = 0; $i < strlen($pin); $i++)
        {
                $sum += ord($pin[$i]);
        }
        $sum = $sum % 256;
        if(strlen($sum)==2) $sum="0".$sum;
        if(strlen($sum)==1) $sum="00".$sum;
        return $sum;
}
function write2file($filename, $value)
{
        global $is_error_write, $log2console;
        $fp2 = fopen($filename,"w");
        if(!$fp2 || !fwrite($fp2, (float) $value))
        {
                if(!$is_error_write)
                {
                                logging("Fehler beim Schreiben in die Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
                }
                $is_error_write = true;
        }
        else if($is_error_write)
        {
                logging("Fehler beim Schreiben bereinigt!", true);
                $is_error_write = false;
        }
        fclose($fp2);
}
function write2file_string($filename, $value)
{
        global $is_error_write, $log2console;
        $fp2 = fopen($filename,"w");
        if(!$fp2 || !fwrite($fp2, $value))
        {
                if(!$is_error_write)
                {
                        logging("Fehler beim Schreiben in die _string Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
                }
                $is_error_write = true;
        }
        else if($is_error_write)
        {
                logging("Fehler beim Schreiben bereinigt!", true);
                $is_error_write = false;
        }
        fclose($fp2);
}
function write2file_log($filename, $value)
{
        global $is_error_write, $log2console;
        $fp2 = fopen($filename,"a");
        if(!$fp2 || !fwrite($fp2, $value."\n"))
        {
                if(!$is_error_write)
                {
                        logging("Fehler beim Schreiben in die _log Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
                }
                $is_error_write = true;
        }
        else if($is_error_write)
        {
                logging("Fehler beim Schreiben bereinigt!", true);
                $is_error_write = false;
        }
        fclose($fp2);
}
function logging($txt, $write2syslog=false)
{
        global $fp_log, $log2console, $debug, $ts;
        if($log2console) echo date("Y-m-d H:i:s").": $txt<br />\n";
        if($debug)
        {
                list($ts) = explode(".",microtime(true));
                $dt = new DateTime(date("Y-m-d H:i:s.",$ts));
                $logdate = $dt->format("Y-m-d H:i:s.u");
                echo date("Y-m-d H:i:s").": $txt\n";
                fwrite($fp_log, date("Y-m-d H:i:s").": $txt<br />\n");
        }
}
function batterie_nacht()
{
        // Nachts NUR die Werte der Batterie abfragen
        global $debug, $err, $fp, $tmp_dir, $warte_bis_naechster_durchlauf;
        fwrite($fp, "^P003GS".chr(0x0d));
        $byte=fgets($fp,115);
        // Batteriedaten auswerten + pruefen
        $battvolt = (substr($byte,27,4)/10);
        $battcap = substr($byte,32,3);
        $battamps = substr($byte,36,6);
        $batttemp = substr($byte,109,3);
        // Power State abfragen
        fwrite($fp, "^P003PS".chr(0x0d));
        $byte=fgets($fp,83);
        $battcode_code = substr($byte,77,1);
        if($battcode_code=="0") $battcode="tue nichts";
        if($battcode_code=="1") $battcode="laden";
        if($battcode_code=="2") $battcode="entladen";

        // Werte in die Files schreiben
        write2file($tmp_dir."BATTV.txt",$battvolt);
        write2file($tmp_dir."BATTCAP.txt",$battcap);
        write2file_string($tmp_dir."BATTSTAT.txt",$battstat);
        write2file_string($tmp_dir."BATTCODE.txt",$battcode);
        write2file($tmp_dir."BATTCHAMP.txt",$battamps);
        if($debug) logging("DEBUG Wert BATTV: $battvolt");
        if($debug) logging("DEBUG Wert BATTCHAMP: $battchamp");
        if($debug) logging("DEBUG Wert BATTCAP: $battcap");
        if($debug) logging("DEBUG Wert BATTTEMP: $batttemp");
        fclose($fp);
}
function getalarms()
{
        global $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
        $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
        stream_set_timeout($fp, 10);
        // Read fault register
        // Command: ^P003WS<cr>: Query warning status
        //                                1 1 1 1 1 1 1 1 1 1 2 2
        //            0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
        //Answer:^D040A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V<CRC><cr>
        fwrite($fp, "^P003WS".chr(0x0d)); //Device Warning Status inquiry
        $byte=fgets($fp,51);
        $warnings = substr($byte,5,44);
        //echo "Warnings:".$warnings."\n";
        for($i = 0; $i < 43; $i=$i+2)
        {
        $fehlerbit = substr($warnings,$i,1);
                if($debug) echo "fehlerbit ".$i." ist ".$fehlerbit."\n";
        if($fehlerbit != "0" && $fehlerbit != "1" && $fehlerbit != "-") echo "Fehler beim Emfang: Bit ".$i." = ".$fehlerbit."\n";
        //Bituebersetzungstabelle:
        if($i==0 && $fehlerbit=="1") $error[$i] = "Solar input 1 loss\n";
        if($i==2 && $fehlerbit=="1") $error[$i] = "Solar input 2 loss\n";
        if($i==4 && $fehlerbit=="1") $error[$i] = "Solar input 1 voltage too higher\n";
        if($i==6 && $fehlerbit=="1") $error[$i] = "Solar input 2 voltage too higher\n";
        if($i==8 && $fehlerbit=="1") $error[$i] = "Battery under\n";
        if($i==10 && $fehlerbit=="1") $error[$i] = "Battery low\n";
        if($i==12 && $fehlerbit=="1") $error[$i] = "Battery open\n";
        if($i==14 && $fehlerbit=="1") $error[$i] = "Battery voltage too higher\n";
        if($i==16 && $fehlerbit=="1") $error[$i] = "Battery low in hybrid mode\n";
        if($i==18 && $fehlerbit=="1") $error[$i] = "Grid voltage high loss\n";
        if($i==20 && $fehlerbit=="1") $error[$i] = "Grid voltage low loss\n";
        if($i==22 && $fehlerbit=="1") $error[$i] = "Grid frequency high loss\n";
        if($i==24 && $fehlerbit=="1") $error[$i] = "Grid frequency low loss\n";
        if($i==26 && $fehlerbit=="1") $error[$i] = "AC input long-time average voltage over\n";
        if($i==28 && $fehlerbit=="1") $error[$i] = "AC input voltage loss\n";
        if($i==30 && $fehlerbit=="1") $error[$i] = "AC input frequency loss\n";
        if($i==32 && $fehlerbit=="1") $error[$i] = "AC input island\n";
        if($i==34 && $fehlerbit=="1") $error[$i] = "AC input phase dislocation\n";
        if($i==36 && $fehlerbit=="1") $error[$i] = "Over temperature\n";
        if($i==38 && $fehlerbit=="1") $error[$i] = "Over load\n";
        if($i==40 && $fehlerbit=="1") $error[$i] = "EPO active\n";
        if($i==42 && $fehlerbit=="1") $error[$i] = "AC input wave loss\n";
        }
        if ($debug) for($i = 0; $i < 22; $i++){
                        if(isset($error[$i])){
				echo "Fehler:".$error[$i];
				$fehler = $error[$i];
				logging("WR im ALARMZUSTAND: ".$fehler."\n");
				}
                        }
        $fp = fopen($tmp_dir.'ALARM.txt',"w");
        if($fp)
        {
                for($i = 1; $i < 22; $i++)
                {
                        if(isset($error[$i]))
                        {
				fwrite($fp, date("Y-m-d H:i:s"));
                                fwrite($fp, ": ".$error[$i]);
                        }
                }
        }
        fclose($fp);
}
function getdaycounter()
{
        global $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
        $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
        stream_set_timeout($fp, 10);
        // Get today's generated power
        $month=date("m");
        $year=date("Y");
        $day=date("d");
        $check = cal_crc_half("^P014ED".$year.$month.$day);
        fwrite($fp, "^P014ED".$year.$month.$day.$check.chr(0x0d)); // ^P014EDyyyymmddnnn<cr>: Query generated energy of day
        $byte=fgets($fp,15);
	$posanf=strpos($byte, '^D009');
	$posend=strpos($byte, chr(13));
	if(substr($byte,0,2)=="^0") return false;
	$daypower = substr($byte, $posanf+5, (($posend-2)-($posanf+5)));
	fclose($fp);
	return $daypower;
}
function gethourpower($stunde)
{
	//^P016EHyyyymmddhhnnn<cr>: Query generated energy of hour
        global $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
        $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
        stream_set_timeout($fp, 10);
        // Get today's generated power
        $check = cal_crc_half("^P016EH".$stunde);
        fwrite($fp, "^P016EH".$stunde.$check.chr(0x0d)); // ^P014EDyyyymmddnnn<cr>: Query generated energy of day
        $byte=fgets($fp,14);
        $posanf=strpos($byte, '^D008');
        $posend=strpos($byte, chr(13));
        if(substr($byte,0,2)=="^0") return false;
        $hourpower = substr($byte, $posanf+5, (($posend-2)-($posanf+5)));
        fclose($fp);
        return $hourpower;
}
function gettotalcounter()
{
	global $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
	//^P003ET<cr>: Query total generated energy
	//Get total-counter from inverter
        $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
        stream_set_timeout($fp, 10);
 	fwrite($fp, "^P003ET".chr(0x0d)); // ^P003ET<cr>: Query total generated energy
	$byte=fgets($fp,16);
	$totalcounter = substr($byte,6,8);
	if($debug) logging("TOTAL:Got KwH_total from inverter: ".$totalcounter." kWh");
	// Get today's generated power
	fclose($fp);
        return $totalcounter;
}
function querypowerstatus()
{
	global $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
	$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
	stream_set_timeout($fp, 10);
	// ^P003PS<cr>: Query power status
	fwrite($fp, "^P003PS".chr(0x0d));
	$byte=fgets($fp,83);
	$pv1power = substr($byte,7,4);
	$pv2power = substr($byte,13,4);
	$gridpower1 = substr($byte,25,4);
	$gridpower2 = substr($byte,30,4);
	$gridpower3 = substr($byte,35,4);
	$gridpower = substr($byte,40,5);
	$apppower1 = substr($byte,46,4);
	$apppower2 = substr($byte,51,4);
	$apppower3 = substr($byte,56,4);
	$apppower = substr($byte,61,5);
	$powerperc = substr($byte,65,3);
	$acoutact = substr($byte,69,1);
	if($acoutact=="0") $acoutactT="disconnected";
	if($acoutact=="1") $acoutactT="connected";
	$pvinput1status = substr($byte,71,1);
	$pvinput2status = substr($byte,73,1);
	$battcode_code = substr($byte,75,1);
	if($battcode_code=="0") $battstat="donothing";
	if($battcode_code=="1") $battstat="charge";
	if($battcode_code=="2") $battstat="discharge";
	$dcaccode_code = substr($byte,77,1);
	if($dcaccode_code=="0") $dcaccode="donothing";
	if($dcaccode_code=="1") $dcaccode="AC-DC";
	if($dcaccode_code=="2") $dcaccode="DC-AC";
	$powerdir_code = substr($byte,79,1);
	if($powerdir_code=="0") $powerdir="donothing";
	if($powerdir_code=="1") $powerdir="input";
	if($powerdir_code=="2") $powerdir="output";
}
function hourspowertoday()
	{
	global $debug;
	//get daypower from hour values
	for($i = 0; $i<=date("H"); $i++)
	{
		$year=date("Y");
		$month=date("m");
		$day=date("d");
		$hour=str_pad($i, 2, 0, STR_PAD_LEFT);
		$stunde= $year.$month.$day.$hour;
		$stundenpower[$i] = gethourpower($stunde);
		$hourspower = array_sum($stundenpower);
		if($debug) logging("***NEW HOURSPOWER".$hourspower." Wh\n");
	}
	return $hourspower;
}
function sendsignal($message) // send alarm messages to users signal app
	{
	logging("Sende Alarm an Signal App:".$message."\n");
	shell_exec("/etc/signal/bin/signal-cli -u +43123456789 send -a /etc/infinipoll10k/alarm.png -m $message +43654321");
	}
?>
