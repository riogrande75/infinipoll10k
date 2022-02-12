#!/usr/bin/php
<?php
//General settings
$moxa_ip = "192.168.x.y"; //ETH-RS232 converter in TCP_Server mode
$moxa_port = 12345; // ETH-RS232 converter port
$moxa_timeout = 10;
$warte_bis_naechster_durchlauf = 2; //Zeit zw. zwei Abfragen in Sekunden
$antw="";
$tmp_dir = "/tmp/inv1/";             //Speicherort/-ordner fuer akt. Werte -> am Ende ein / !!!
if (!file_exists($tmp_dir)) {
        mkdir("/tmp/inv1/", 0777);
        }
$tmp_dir_backup = "/home/user/inv1/"; // Folder for daily backup
if (!file_exists($tmp_dir_backup)) {
        mkdir("/home/user/inv1/", 0777);
        }
$error = [];
$schleifenzaehler = 0;

//Logging/Debugging Einstellungen:
$debug = 1;         //Debugausgaben und Debuglogfile
$debug2 = 0;        //advanced debugging CLI only
$storage_stat=1;
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
$battamps_old = 0;

//Syslog oeffnen
openlog($script_name, LOG_PID | LOG_PERROR, LOG_LOCAL0);
syslog(LOG_ALERT,"**INFINIPOLL 10K Neustart**");

if($debug) $fp_log = @fopen($logfilename.date("Y-m-d").".log", "a");
if($debug) logging("**INFINIPOLL 10K Neustart**");

// Get model,version and protocolID for infini_startup.php
// Modell  abfragen
$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout); // Open connection to the inverter
if(!$fp) echo "FSOCK OPEN failed!!!\n";
// ProtokollID abfragen
fwrite($fp, "^P003PI".chr(0x0d)); //QPI Protocol ID abfragen 6byte
$antw = parse_antw();
$protid = substr($antw[0],5,2);
if($debug) echo "ProtocolID: ".$protid."\n";
// Serial abfragen
fwrite($fp, "^P003ID".chr(0x0d)); //QPI Protocol ID abfragen 6byte
$antw =  parse_antw();
$serial = substr($antw[0],7,14);
if($debug) echo "Serial: ".$serial."\n";
// CPU Version abfragen
fwrite($fp, "^P004VFW".chr(0x0d));
$antw = parse_antw();
$version = substr($antw[0],5,14);
if($debug) echo "CPU Version: ".$version."\n";
// CPU secondary Version abfragen
fwrite($fp, "^P005VFW2".chr(0x0d));
$antw = parse_antw();
$version2 = substr($antw[0],5,15);
if($debug) echo "CPU secondary version: ".$version2."\n";
// CPU FW Version time abfragen
fwrite($fp, "^P005VFWT".chr(0x0d));
$antw = parse_antw();
$version3a = substr($antw[0],5,12);
$version3b = $antw[1];
$version3c = $antw[2];
$version3d = $antw[3];
$version3e = $antw[4];
$version3f = $antw[5];
if($debug) echo "CPU FW-Date: $version3a/$version3b/$version3c/$version3d/$version3e/$version3f\n";
// Modell  abfragen
fwrite($fp, "^P003MD".chr(0x0d));
$antw = parse_antw();
$modelcode = substr($antw[0],5,3);
if($modelcode="000") $model="MPI Hybrid 10KW/3P";
$modelva = $antw[1];
$modelpf = $antw[2];
$modelbattpcs = $antw[7];
$modelbattv = $antw[8]/10;
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
$antw = parse_antw();
if(strlen($antw[0])==19){ // Datestring received from inverter
	$zeit = substr($antw[0],5,14);
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
		$antw = parse_antw();
		echo "RESP:".$antw[0]."\n";
        	}
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
	$antw = parse_antw();
        $totalcounter = substr($antw[0],5,8);
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
        if($schleifenzaehler==100) //100 = Query alarms about every 6 minutes
        {
                getalarms();
                $schleifenzaehler=0;
                continue;
        }
        if($debug && $fp_log) @fclose($fp_log);
        if($debug) $fp_log = @fopen($logfilename.date("Y-m-d").".log", "a");    //Write a single file! -> rotation!
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
        if(date("Hi")=="2359" or date("H")=="23" ) 
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
	$antw=parse_antw();
        $modus = substr($antw[0],5,2);
        switch ($modus)
        {
                case "00":
                $modusT="PowerOn";
                break;
                case "01":
                $modusT="Standby without charging";
                break;
                case "02":
                $modusT="Bypass without charging";
                break;
                case "03":
                $modusT="Inverter";
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
//		batterie_nacht(); // erst aktivieren, wenn Batterie dran hängt
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
	$antw=parse_antw();
        $pv1volt = (substr($antw[0],5,4)/10);
        $pv2volt = $antw[1]/10;
        $pv1amps = $antw[2]/100;
        $pv2amps = $antw[3]/100;
        $battvolt = $antw[4]/10;
        $battcap = $antw[5];
        $battamps = $antw[6]/10;
        $gridvolt1 = $antw[7]/10;
        $gridvolt2 = $antw[8]/10;
        $gridvolt3 = $antw[9]/10;
        $gridfreq = $antw[10]/100;
        $gridamps1 = $antw[11]/10;
        $gridamps2 = $antw[12]/10;
        $gridamps3 = $antw[13]/10;
        $outvolt1 = $antw[14]/10;
        $outvolt2 = $antw[15]/10;
        $outvolt3 = $antw[16]/10;
        $outfreq = $antw[17]/100;
        //$outamps1 = (substr($antw,96,4)/10);
        //$outamps2 = (substr($antw,101,4)/10);
        //$outamps3 = (substr($antw,106,4)/10);
        $intemp = $antw[21];
        $maxtemp = $antw[22];
        $batttemp = $antw[23];
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
	$antw = parse_antw();
        $pv1power = substr($antw[0],5,5);
        $pv2power = $antw[1];
        $gridpower1 = $antw[7];
        $gridpower2 = $antw[8];
        $gridpower3 = $antw[9];
        $gridpower = $antw[10];
        $apppower1 = $antw[11];
        $apppower2 = $antw[12];
        $apppower3 = $antw[13];
        $apppower = $antw[14];
        $powerperc = $antw[15];
        $acoutact = $antw[16];
        if($acoutact=="0") $acoutactT="disconnected";
        if($acoutact=="1") $acoutactT="connected";
        $pvinput1status = $antw[17];
        $pvinput2status = $antw[18];
        $battcode_code = $antw[19];
        if($battcode_code=="0") $battstat="Leerlauf";
        if($battcode_code=="1") $battstat="Laden";
        if($battcode_code=="2") $battstat="Entladen";
        $dcaccode_code = $antw[20];
        if($dcaccode_code=="0") $dcaccode="donothing";
        if($dcaccode_code=="1") $dcaccode="AC-DC";
        if($dcaccode_code=="2") $dcaccode="DC-AC";
        $powerdir_code = $antw[21];
        if($powerdir_code=="0") $powerdir="donothing";
        if($powerdir_code=="1") $powerdir="input";
        if($powerdir_code=="2") $powerdir="output";


	//INGS command 02.01.2022
        fwrite($fp, "^P005INGS".chr(0x0d));
        $antw = parse_antw();
	$ings_InvCurrR = substr($antw[0],5,5);
	$ings_InvCurrS = $antw[1];
	$ings_InvCurrT = $antw[2];
	$ings_OutCurrR = $antw[3];
	$ings_OutCurrS = $antw[4];
	$ings_OutCurrT = $antw[5];
	$ings_PBusVolt = $antw[6];
	$ings_NBusVolt = $antw[7];
	$ings_PBusAvgV = $antw[8];
	$ings_NBusAvgV = $antw[9];
	$ings_NLintCur = $antw[10];

	if($debug2){ //Print INGS Command output
	echo "INGS Commando:\n";
	echo " R_Inv_Curr $ings_InvCurrR\n";
	echo " S_Inv_Curr $ings_InvCurrS\n";
	echo " T_Inv_Curr $ings_InvCurrT\n";
	echo " R_Out_Curr $ings_OutCurrR\n";
	echo " S_Out_Curr $ings_OutCurrS\n";
	echo " T_Out_Curr $ings_OutCurrT\n";
	echo " PBus_Volt $ings_PBusVolt\n";
	echo " NBus_Volt $ings_NBusVolt\n";
	echo " PBusAvg_V $ings_PBusAvgV\n";
	echo " NBusAvg_V $ings_PBusAvgV\n";
	echo " NLine_Cur $ings_NLintCur\n";
	}
	//Print RTCP Command output 12.02.2022
	if($debug2){ 
    	fwrite($fp, "^P005RTCP".chr(0x0d)); // RealTimeControllingParallel
    	$antw = parse_antw();
    	echo " RTCP: $antw \n";
	}
	// EMINFO command 24.01.2022
	fwrite($fp, "^P007EMINFO".chr(0x0d));
        $antw = parse_antw();
	if(count($antw)>4){
		$emfirst = substr($antw[0],5,1);
		$emgpmp = $antw[1];
		$emactpvpow = $antw[2];
		$emactfeedpow = $antw[3];
		$emactreserv = $antw[4];
		$emlast = $antw[5];
		}
	else{
		if($debug) logging("**ERROR: EMINFO received var_dump($antw)");
		if($debug2) echo "EMINFO fail: var_dump($antw)\n";
		}

	if($debug2){ //Print INGS Command output
        echo "EMINFO Commando:\n";
	echo " EMFirst: $emfirst\n";
	echo " DefFeed-InPow: $emgpmp\n";
	echo " ActPvPow: $emactpvpow\n";
	echo " ActFeedPow: $emactfeedpow\n";
	echo " ReservPow: $emactreserv\n";
	echo " EMLast: $emlast\n";
	}

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
                echo "ACTual values:\n";
		echo " PV1_Power: ".$pv1power."W\n";
                echo " PV2_Power: ".$pv2power."W\n";
                echo " GridPower1: ".$gridpower1."W\n";
                echo " GridPower2: ".$gridpower2."W\n";
                echo " GridPower3: ".$gridpower3."W\n";
                echo " GridPower: ".$gridpower."W\n";
                echo " ApperentPower1: ".$apppower1."W\n";
                echo " ApperentPower2: ".$apppower2."W\n";
                echo " ApperentPower3: ".$apppower3."W\n";
                echo " ApperentPower: ".$apppower."W\n";
                echo " AC-Out: ".$acoutactT."\n";
                echo " PowerOutputPercentage: ".$powerperc."%\n";
                echo " BatteryStatus: ".$battstat."\n";
                echo " DC-AC Power direction: ".$dcaccode."\n";
                echo " LinePowerDirection: ".$powerdir."\n";
                echo " Power Today: ".$daypower."\n";
        }
        if($err) //If any error appeared, flush data and collect again
        {
        	echo "CLOSE HANDLE in ERROR \n";
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

	//Check for failed battery braker 16.10.21
	if(( ($battamps < 10) and ($battamps > -10) ) and ($battamps_old > 70 || ($battamps_old < -70))) //Charge AMPs collapsed massivly
	{
	if($battvolt != "0" and $battamps != "0")
		{
		logging("***ALARM: FAILED BREAKER DETECTED");
		echo "**** BATTAMPS: $battamps \n";
		echo "**** BATTAMPS_OLD: $battamps_old \n";
		$message = "BATTERIESICHERUNG_AUS";
		$filename = "/home/user/BatterieBreaker.jpg";
		shell_exec("/usr/local/bin/signal-cli -u +431234512345 send -a $filename -m $message +43123123123");
		}
	else logging("FAILED BREAKER DETECTED but FALSEALARM");
	}
	$battvolt_old = $battvolt;
	$battamps_old = $battamps;

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
	if($debug) logging("DEBUG: EMINFO 1:$emfirst ACT:$emactpvpow FEED:$emactfeedpow RES:$emactreserv 5:$emlast");

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

        // Warte bis naechster Durchlauf
        sleep($warte_bis_naechster_durchlauf);
	fclose($fp);
}
// END OF MAIN LOOP

// Some functions
function hex2str($hex)
{
    $str = '';
    for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
    return $str;
}
function ascii2hex($ascii) {
  $hex = '';
  for ($i = 0; $i < strlen($ascii); $i++) {
    $byte = strtoupper(dechex(ord($ascii{$i})));
    $byte = str_repeat('0', 2 - strlen($byte)).$byte;
    $hex.=$byte." ";
  }
  return $hex;
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
        global $fp, $debug, $err, $fp, $tmp_dir, $warte_bis_naechster_durchlauf;
        $handleoffen = true;
        if(!$fp) {
                $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
                stream_set_timeout($fp, 10);
                $handleoffen = false;
                }
        fwrite($fp, "^P003GS".chr(0x0d));
        $antw = parse_antw();
        // Batteriedaten auswerten + pruefen
        $battvolt = $antw[4]/10;
	$battcap = $antw[5];
	$battamps = $antw[6]/10;
	$batttemp = $antw[23];
        // Power State abfragen
        fwrite($fp, "^P003PS".chr(0x0d));
	$antw = parse_antw();
        $battcode_code = $antw[19];
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
        if(!$handleoffen) fclose($fp);
}
function getalarms() // Handle $fp darf nicht offen sein!!
{
        global $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
        // Read fault register
        // Command: ^P003WS<cr>: Query warning status
        //                                1 1 1 1 1 1 1 1 1 1 2 2
        //            0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
        //Answer:^D040A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V<CRC><cr>
	$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
	stream_set_timeout($fp, 10);
        fwrite($fp, "^P003WS".chr(0x0d)); //Device Warning Status inquiry
        $byte="";
        $s="";
        if($fp){
                while( $fp && ($s != chr(13)) )
                        {
                        $s=fgetc($fp);
                        If($s === false) return $byte;
                        $byte=$byte.$s;
                        }
                }
        $byte_ok = substr($byte,0,-3);
        $antw = explode(",",$byte_ok);
	$warnings = array(
		"Solar input 1 loss",
		"Solar input 2 loss",
		"Solar input 1 voltage too high",
		"Solar input 2 voltage too high",
		"Battery under",
		"Battery low",
		"Battery open",
		"Battery voltage too higher",
		"Battery low in hybrid mode",
		"Grid voltage high loss",
		"Grid voltage low loss",
		"Grid frequency high loss",
		"Grid frequency low loss",
		"AC input long-time average voltage over",
		"AC input voltage loss",
		"AC input frequency loss",
		"AC input island",
		"AC input phase dislocation",
		"Over temperature",
		"Over load",
		"EPO active",
		"AC input wave loss",
	);
	$fpA = fopen($tmp_dir.'ALARM.txt',"a");
	if($fpA)
	{
	for($w=0; $w < count($antw); $w++)
		{
		if(substr($antw[$w],-1)=="1"){
			if($debug) logging("WARNING: $warnings[$w]");
			fwrite($fpA, date("Y-m-d H:i:s"));
			fwrite($fpA, ": ".$warnings[$w]."\n");
			}
		}
	}
	fclose($fpA);
	fclose($fp);
}
function getdaycounter()
{
        global $fp, $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
        $handleoffen = true;
        if(!$fp) {
		echo "HANDLE NICHT OFFEN in GetDayCounter!!!\n";
                $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
                stream_set_timeout($fp, 10);
                $handleoffen = false;
                }
        // Get today's generated power
        $month=date("m");
        $year=date("Y");
        $day=date("d");
        $check = cal_crc_half("^P014ED".$year.$month.$day);
        fwrite($fp, "^P014ED".$year.$month.$day.$check.chr(0x0d)); // ^P014EDyyyymmddnnn<cr>: Query generated energy of day
	$antw = parse_antw();
	if($antw[0]=="^0") return false;
	$daypower = substr($antw[0],5,6);
        if(!$handleoffen) fclose($fp);
	return $daypower;
}
function gethourpower($stunde)
{
	//^P016EHyyyymmddhhnnn<cr>: Query generated energy of hour
        global $fp, $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
        $handleoffen = true;
	$hourpower = 0;
        if(!$fp) {
		$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
		stream_set_timeout($fp, 10);
		$handleoffen = false;
		}
        $check = cal_crc_half("^P016EH".$stunde);
        fwrite($fp, "^P016EH".$stunde.$check.chr(0x0d));
	$antw = parse_antw();
	$hourpower = substr($antw[0], 5, 5);
	if(!$handleoffen) fclose($fp);
        return $hourpower;
}
function gettotalcounter()
{
	global $fp, $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
	//^P003ET<cr>: Query total generated energy
	//Get total-counter from inverter
	$handleoffen = true;
        if(!$fp) {
                $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
                stream_set_timeout($fp, 10);
                $handleoffen = false;
                }
 	fwrite($fp, "^P003ET".chr(0x0d)); // ^P003ET<cr>: Query total generated energy
	$antw = parse_antw();
	var_dump($antw);
	$totalcounter = substr($antw[0],5,8);
	if($debug) logging("TOTAL: Got KwH_total from INV: ".$totalcounter." kWh");
	// Get today's generated power
	if(!$handleoffen) fclose($fp);
        return $totalcounter;
}
function querypowerstatus()
{
	global $fp, $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
        $handleoffen = true;
        if(!$fp) {
                $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
                stream_set_timeout($fp, 10);
                $handleoffen = false;
                }
        // ^P003PS<cr>: Query power status
        fwrite($fp, "^P003PS".chr(0x0d));
        $antw = parse_antw();
        $pv1power = substr($antw[0],5,5);
        $pv2power = $antw[1];
        $gridpower1 = $antw[7];
        $gridpower2 = $antw[8];
        $gridpower3 = $antw[9];
        $gridpower = $antw[10];
        $apppower1 = $antw[11];
        $apppower2 = $antw[12];
        $apppower3 = $antw[13];
        $apppower = $antw[14];
        $powerperc = $antw[15];
        $acoutact = $antw[16];
        if($acoutact=="0") $acoutactT="disconnected";
        if($acoutact=="1") $acoutactT="connected";
        $pvinput1status = $antw[17];
        $pvinput2status = $antw[18];
        $battcode_code = $antw[19];
        if($battcode_code=="0") $battstat="Leerlauf";
        if($battcode_code=="1") $battstat="Laden";
        if($battcode_code=="2") $battstat="Entladen";
        $dcaccode_code = $antw[20];
        if($dcaccode_code=="0") $dcaccode="donothing";
        if($dcaccode_code=="1") $dcaccode="AC-DC";
        if($dcaccode_code=="2") $dcaccode="DC-AC";
        $powerdir_code = $antw[21];
        if($powerdir_code=="0") $powerdir="donothing";
        if($powerdir_code=="1") $powerdir="input";
        if($powerdir_code=="2") $powerdir="output";
	if(!$handleoffen) fclose($fp);
}
function hourspowertoday()
	{
	global $fp, $debug;
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
    if($debug) logging("HOURSPOWER for hour $stunde: $stundenpower[$i] Wh");
	}
	return $hourspower;
}
function sendsignal($message)
	{
	logging("Sende Alarm an Signal App:".$message."\n");
	shell_exec("/etc/signal/bin/signal-cli -u +4312345678 send -a /etc/infinipoll10k/alarm.png -m $message +43123123123");
	}
function parse_antw()
        {
        global $fp, $debug;
        $byte="";
        $s="";
        if($fp){
                while( $fp && ($s != chr(13)) )
                        {
                        $s=fgetc($fp);
                        If($s === false) return $byte;
                        $byte=$byte.$s;
                        }
                }
                else exit(7);
        $byte_ok = substr($byte,0,-3);
        $antw_werte = explode(",",$byte_ok);
        return($antw_werte);
}
?>
