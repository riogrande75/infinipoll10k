#!/usr/bin/php
<?php
//Allg. Einstellungen
$moxa_ip = "192.168.x.y"; //ETH-RS232 converter in TCP_Server mode
$moxa_port = xxxxx;
$moxa_timeout = 10;
$warte_bis_naechster_durchlauf = 2; //Zeit zw. zwei Abfragen in Sekunden
$antw="";
$tmp_dir = "/tmp/inv1/";             //Speicherort/-ordner fuer akt. Werte -> am Ende ein / !!!
if (!file_exists($tmp_dir)) {
        mkdir("/tmp/inv1/", 0777);
        }
$tmp_dir_backup = "/home/userX/inv1/"; // Folder for daily backup
if (!file_exists($tmp_dir_backup)) {
        mkdir("/home/userX/inv1/", 0777);
        }
$error = [];
$schleifenzaehler = 0;
$no_modbus=0; //set to one if there is no modbusII card installed

//Logging/Debugging Einstellungen:
$debug = 1;         //Debugausgaben und Debuglogfile
$debug2 = 0;        //advanced debugging CLI only
$storage_stat=1;
$log2console = 0;
$fp_log = 0;
$script_name = "infinipoll10k_A.php";
//$logfilename = "/etc/infinipoll10k_A/log/infinipoll_10k_";     //Debugging Logfile
$logfilename = "/daten/log/infinipoll_10k_A_";     //Debugging Logfile

//Initialisieren der Variablen:
$is_error_write = false;
$totalcounter = 0;
$daybase = 0;
$daybase_yday = 0;
$daypower_old = 0;
$battamps_old = 0;

//Syslog oeffnen
openlog($script_name, LOG_PID | LOG_PERROR, LOG_LOCAL0);
syslog(LOG_ALERT,"**INFINIPOLL 10K A Neustart**");

if($debug) $fp_log = @fopen($logfilename.date("Y-m-d").".log", "a");
if($debug) logging("**INFINIPOLL 10K A Neustart**");

// BMS checken und shared memory öffnen
$sh_bms = shmop_open(0x4801, "a", 0777, 57); //shmop ID anpassen!
if (!$sh_bms) {
    if($debug) logging("Couldn't open shared memory segent for BMS => BMS not used!\n");
    $bms=0;
    } else $bms=1;

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
                getfaults();
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
//              batterie_nacht(); // erst aktivieren, wenn Batterie dran hängt
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
        If(sizeof($antw) == 25)
        {
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
        }
        else logging("WARNING: 003GS falsche Antwort.");
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
        If(sizeof($antw) == 22)
        {
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
        }
        else logging("WARNING: 003PS falsche Antwort.");
        // Test EMINFO set values
        // EMINFO command 24.01.2022
        if($no_modbus)
        {
        fwrite($fp, "^P026EMINFO".chr(0x0d));
        $antw = parse_antw();
        var_dump($anw);
        };
        // BMS feeback command// 03.07.2022
        if($bms){
                $bmscmd = shmop_read($sh_bms, 0, 56);
                $bmscrc = paddings(genCRC($bmscmd),4);
                $bmscrc1 = substr($bmscrc,-4,2);
                $bmscrc2 = substr($bmscrc,-2);
                fwrite($fp,$bmscmd.chr(hexdec($bmscrc1)).chr(hexdec($bmscrc2)).chr(0x0d));
//              var_dump($antw);
        }
        // BMS command 24.05.2022
        fwrite($fp, "^P004BMS".chr(0x0d)); // Query CPU2 version
        $antw = parse_antw();
        //print_r($antw);
        If(sizeof($antw) == 13)
        {
        $bms_batvolt = substr($antw[0],5,4);
        $bms_batperc = $antw[1];
        $bms_batcurr = $antw[2];
        $bms_warn = $antw[3];
        $bms_forcechg = $antw[4];
        $bms_cvvolt = $antw[5];
        $bms_floatvolt = $antw[6];
        $bms_maxchamps = $antw[7];
        $bms_batstopdisflag = $antw[8];
        $bms_batstopchaflag = $antw[9];
        $bms_batcutoffvol = $antw[10];
        $bms_maxdischamps = $antw[11];
        $bms_12 = $antw[12];

        $bmsc = "";
        for($i = 0; $i <= 12; $i++) $bmsc = $bmsc.$antw[$i]." ";

        if($debug){ //Print BMS Command output
        echo "BMS Commando:\n";
        echo "BMS Battery Voltage: $bms_batvolt\n";
        echo "BMS Battery Percent: $bms_batperc\n";
        echo "BMS Battery Current: $bms_batcurr\n";
        echo "BMS Warning Code   : $bms_warn\n";
        echo "BMS Froce Charge   : $bms_forcechg\n";
        echo "BMS CV Voltage     : $bms_cvvolt\n";
        echo "BMS Float Voltage  : $bms_floatvolt\n";
        echo "BMS Max.Charge Amps: $bms_maxchamps\n";
        echo "BMS BatStopDiscFlag: $bms_batstopdisflag\n";
        echo "BMS BatStopChgFlag : $bms_batstopchaflag\n";
        echo "BMS ButCutoffVolt  : $bms_batcutoffvol\n";
        echo "BMS BatMaxDisChAmps: $bms_maxdischamps\n";
        echo "BMS 12: $bms_12\n";
        }
        }
        //INGS command 02.01.2022
        fwrite($fp, "^P005INGS".chr(0x0d));
        $antw = parse_antw();
        If(sizeof($antw) == 11)
        {
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
        $ings = "";
        for($i = 0; $i <= 10; $i++) $ings = $ings.$antw[$i]." ";

        if($debug){ //Print INGS Command output
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
        }
        else logging("WARNING: INGS falsche Antwort.");
        if($debug){ //Print RTCP Command output
        fwrite($fp, "^P005RTCP".chr(0x0d)); // RealTimeControllingParallel
        $antw = parse_antw();
        If(sizeof($antw) == 13)
        {
        $rtcp = "";
        for($i = 0; $i <= 12; $i++) $rtcp = $rtcp.$antw[$i]." ";
        echo " RTCP: $rtcp \n";
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
                $rtcp_fehl= var_dump(implode(",", $antw));
                if($debug) logging("**ERROR: EMINFO received $rtcp_fehl");
                if($debug2) echo "EMINFO fail: var_dump($antw)\n";
                }
        if($debug){ //Print EMINFO Command output
        echo "EMINFO Commando:\n";
        echo " EMFirst: $emfirst\n";
        echo " DefFeed-InPow: $emgpmp\n";
        echo " ActPvPow: $emactpvpow\n";
        echo " ActFeedPow: $emactfeedpow\n";
        echo " ReservPow: $emactreserv\n";
        echo " EMLast: $emlast\n";
        }
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

        //check if battpower is > dc power = AC used for charging
        $accharge=0;
        $DCPOWER = $DCPOW1+$DCPOW2;
        if(intval($DCPOWER) < intval($BATTPOWER)) {
                if($debug2) echo " AC USED FOR CHARGE: DC:$DCPOWER BATT:$BATTPOWER\n";
                $accharge=1;
                }

        //Check for failed battery braker 16.10.21
        if(( ($battamps < 10) and ($battamps > -10) ) and ($battamps_old > 70 || ($battamps_old < -70))) //Charge AMPs collapsed massivly
        {
        if($battvolt != "0" and $battamps != "0")
                {
                logging("***ALARM: FAILED BREAKER DETECTED");
                echo "**** BATTAMPS: $battamps \n";
                echo "**** BATTAMPS_OLD: $battamps_old \n";
                $message = "BATTERIESICHERUNG_AUS";
                $filename = "/home/userX/BatterieBreaker.jpg";
                // shell_exec("/usr/local/bin/signal-cli -u +4123123123 send -a $filename -m $message +4121231231234");
                }
        else logging("FAILED BREAKER DETECTED but FALSEALARM");
        }
        $battvolt_old = $battvolt;
        $battamps_old = $battamps;

        if($debug) logging("DEBUG: Wert ACV1: $ACV1");
        if($debug) logging("DEBUG: Wert ACV2: $ACV2");
        if($debug) logging("DEBUG: Wert ACV3: $ACV3");
        if($debug) logging("DEBUG: Wert ACC1: $ACC1");
        if($debug) logging("DEBUG: Wert ACC2: $ACC2");
        if($debug) logging("DEBUG: Wert ACC3: $ACC3");
        if($debug) logging("DEBUG: Wert ACF: $ACF");
        if($debug) logging("DEBUG: Wert INTEMP: $INTEMP");
        if($debug) logging("DEBUG: Wert BOOT: $BOOT");
        if($debug) logging("DEBUG: Wert DCINV1: $DCINV1");
        if($debug) logging("DEBUG: Wert DCINV2: $DCINV2");
        if($debug) logging("DEBUG: Wert DCINC1: $DCINC1");
        if($debug) logging("DEBUG: Wert DCINC2: $DCINC2");
        if($debug) logging("DEBUG: Wert DCPOW1: $DCPOW1");
        if($debug) logging("DEBUG: Wert DCPOW2: $DCPOW2");
        if($debug) logging("DEBUG: Wert BATTV: $battvolt");
        if($debug) logging("DEBUG: Wert BATTCHAMP: $battamps");
        if($debug) logging("DEBUG: Wert BATTCAP: $battcap");
        if($debug) logging("DEBUG: Wert BATTPOWER: $BATTPOWER");
        if($debug) logging("DEBUG: Wert POWERDIR: $powerdir");
        if($debug) logging("DEBUG: ges. PV in KWh: $pv_ges");
        if($debug) logging("DEBUG: akt. Leistung in Watt: $GRID_POW");
        if($debug) logging("DEBUG: Batterie Status: $battstat");
        if($debug) logging("DEBUG: daypower_old: $daypower_old");
        if($debug) logging("DEBUG: daypower:     $daypower");
        if($debug) logging("DEBUG: daybase: $daybase");
        if($debug) logging("DEBUG: EMINFO 1:$emfirst ACT:$emactpvpow FEED:$emactfeedpow RES:$emactreserv 5:$emlast");
        if($debug) logging("DEBUG: RTCP $rtcp");
        if($debug) logging("DEBUG: INGS $ings");
        if($debug) logging("DEBUG: BMS $bmsc");
        if($debug) logging("DEBUG: DC:$DCPOWER BATT:$BATTPOWER");
        if($debug && $accharge) logging("DEBUG: AC CHARGE, DC:$DCPOWER BATT:$BATTPOWER");

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
//      write2file($tmp_dir."BATTCAP.txt",$$bms_batperc);
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
function genCRC (&$ptr)
{
        $crc_table = array(
        0x0,  0x1021,  0x2042,  0x3063,  0x4084,  0x50a5,  0x60c6,  0x70e7,
        0x8108,  0x9129,  0xa14a,  0xb16b,  0xc18c,  0xd1ad,  0xe1ce,  0xf1ef,
        0x1231,  0x210,  0x3273,  0x2252,  0x52b5,  0x4294,  0x72f7,  0x62d6,
        0x9339,  0x8318,  0xb37b,  0xa35a,  0xd3bd,  0xc39c,  0xf3ff,  0xe3de,
        0x2462,  0x3443,  0x420,  0x1401,  0x64e6,  0x74c7,  0x44a4,  0x5485,
        0xa56a,  0xb54b,  0x8528,  0x9509,  0xe5ee,  0xf5cf,  0xc5ac,  0xd58d,
        0x3653,  0x2672,  0x1611,  0x630,  0x76d7,  0x66f6,  0x5695,  0x46b4,
        0xb75b,  0xa77a,  0x9719,  0x8738,  0xf7df,  0xe7fe,  0xd79d,  0xc7bc,
        0x48c4,  0x58e5,  0x6886,  0x78a7,  0x840,  0x1861,  0x2802,  0x3823,
        0xc9cc,  0xd9ed,  0xe98e,  0xf9af,  0x8948,  0x9969,  0xa90a,  0xb92b,
        0x5af5,  0x4ad4,  0x7ab7,  0x6a96,  0x1a71,  0xa50,  0x3a33,  0x2a12,
        0xdbfd,  0xcbdc,  0xfbbf,  0xeb9e,  0x9b79,  0x8b58,  0xbb3b,  0xab1a,
        0x6ca6,  0x7c87,  0x4ce4,  0x5cc5,  0x2c22,  0x3c03,  0xc60,  0x1c41,
        0xedae,  0xfd8f,  0xcdec,  0xddcd,  0xad2a,  0xbd0b,  0x8d68,  0x9d49,
        0x7e97,  0x6eb6,  0x5ed5,  0x4ef4,  0x3e13,  0x2e32,  0x1e51,  0xe70,
        0xff9f,  0xefbe,  0xdfdd,  0xcffc,  0xbf1b,  0xaf3a,  0x9f59,  0x8f78,
        0x9188,  0x81a9,  0xb1ca,  0xa1eb,  0xd10c,  0xc12d,  0xf14e,  0xe16f,
        0x1080,  0xa1,  0x30c2,  0x20e3,  0x5004,  0x4025,  0x7046,  0x6067,
        0x83b9,  0x9398,  0xa3fb,  0xb3da,  0xc33d,  0xd31c,  0xe37f,  0xf35e,
        0x2b1,  0x1290,  0x22f3,  0x32d2,  0x4235,  0x5214,  0x6277,  0x7256,
        0xb5ea,  0xa5cb,  0x95a8,  0x8589,  0xf56e,  0xe54f,  0xd52c,  0xc50d,
        0x34e2,  0x24c3,  0x14a0,  0x481,  0x7466,  0x6447,  0x5424,  0x4405,
        0xa7db,  0xb7fa,  0x8799,  0x97b8,  0xe75f,  0xf77e,  0xc71d,  0xd73c,
        0x26d3,  0x36f2,  0x691,  0x16b0,  0x6657,  0x7676,  0x4615,  0x5634,
        0xd94c,  0xc96d,  0xf90e,  0xe92f,  0x99c8,  0x89e9,  0xb98a,  0xa9ab,
        0x5844,  0x4865,  0x7806,  0x6827,  0x18c0,  0x8e1,  0x3882,  0x28a3,
        0xcb7d,  0xdb5c,  0xeb3f,  0xfb1e,  0x8bf9,  0x9bd8,  0xabbb,  0xbb9a,
        0x4a75,  0x5a54,  0x6a37,  0x7a16,  0xaf1,  0x1ad0,  0x2ab3,  0x3a92,
        0xfd2e,  0xed0f,  0xdd6c,  0xcd4d,  0xbdaa,  0xad8b,  0x9de8,  0x8dc9,
        0x7c26,  0x6c07,  0x5c64,  0x4c45,  0x3ca2,  0x2c83,  0x1ce0,  0xcc1,
        0xef1f,  0xff3e,  0xcf5d,  0xdf7c,  0xaf9b,  0xbfba,  0x8fd9,  0x9ff8,
        0x6e17,  0x7e36,  0x4e55,  0x5e74,  0x2e93,  0x3eb2,  0xed1,  0x1ef0);

    $crc = 0x0000;
//    $crc_table = $GLOBALS['crc_table'];
    for ($i = 0; $i < strlen($ptr); $i++)
        $crc =  $crc_table[(($crc>>8) ^ ord($ptr[$i]))] ^ (($crc<<8) & 0x00FFFF);
    return dechex($crc);
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
function getfaults()
{
        global $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;
        //^P004CFS<cr>: Query current fault status
        //Response: ^D008AA,BB<CRC><cr>
        //AA "The latest fault code
        //BB "The latest fault code ID stored in flash "
        $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
        stream_set_timeout($fp, 10);
        fwrite($fp, "^P004CFS".chr(0x0d)); //Device Warning Status inquiry
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
        $faultid = substr($antw[0],5,2);
        $faultflash = $antw[1];
        $faults = array(
                "01"=>"BUS exceed the upper limit BUS",
                "02"=>"BUS dropp to the lower limit BUS",
                "03"=>"BUS soft start circuit timeout BUS",
                "04"=>"Inverter voltage soft start timeout",
                "05"=>"Inverter current exceed the  upper limit",
                "06"=>"Temperature over",
                "07"=>"Inverter relay work abnormal",
                "08"=>"Current sample abnormal when inverter doesn't work",
                "09"=>"Solar input voltage exceed upper limit Solar",
                "10"=>"SPS power voltage abnormal",
                "11"=>"Solar input current exceed upper limit Solar",
                "12"=>"Leakage current exceed permit range",
                "13"=>"Solar insulation resistance too low Solar",
                "14"=>"Inverter DC current exceed permit range when feed power",
                "15"=>"The AC input voltage or frequency has been detected different between master CPU and slave CPU",
                "16"=>"Leakage current detect circuit abnormal when inverter doesn't work",
                "17"=>"Comminication loss between master CPU and slave CPU",
                "18"=>"Comminicate data discordant between master CPU and slave CPU",
                "19"=>"AC input ground wire loss",
                "22"=>"Battery voltage exceed upper limit",
                "23"=>"Over load",
                "24"=>"Battery disconnected",
                "26"=>"AC output short",
                "27"=>"Fan lock",
                "32"=>"Battery DC-DC current over",
                "33"=>"AC output voltage too low",
                "34"=>"AC output voltage too high",
                "35"=>"Control board wiring error",
                "36"=>"AC circuit voltage sample error",
                "37"=>"Over current on Neutral wire",
                "60"=>"Power feedback protection",
                "61"=>"Relay board driver loss",
                "62"=>"Relay board communication loss",
                "71"=>"Firmware version inconsistent",
                "72"=>"Current sharing fault",
                "80"=>"CAN fault",
                "81"=>"Host loss",
                "82"=>"Synchronization loss",
                );
        $fpF = fopen($tmp_dir.'FAULTS.txt',"a");
        fwrite($fpF, date("Y-m-d H:i:s").": Latest FaultID ".$faultid."=".$faults[$faultid]."\n");
        fwrite($fpF, date("Y-m-d H:i:s").": Latest FaultInFlash: ".$faultflash."=".$faults[$faultflash]."\n");
        fclose($fpF);
        fclose($fp);
}
function getdaycounter()
{
        global $fp, $debug, $debug2, $tmp_dir, $daypower, $moxa_ip, $moxa_port, $moxa_timeout;
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
function paddings($wert,$leng){
        $neg = "-";
        $pos = strpos($wert, $neg);
        if(!(strpos($wert, $neg)===false)){
                $ohne = substr($wert,1,(strlen($wert-1)));
                $auff =  str_pad($ohne,$leng,'0',STR_PAD_LEFT);
                $final = substr_replace($auff,$neg,0,1);
        }
else    {
                $final = (str_pad($wert,$leng,'0',STR_PAD_LEFT));
        }
        return($final);
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
//              if($debug) logging("HOURSPOWER for hour $stunde ".$hourspower." Wh");
                 if($debug) logging("HOURSPOWER for hour $stunde: $stundenpower[$i] Wh");
        }
        return $hourspower;
}
function sendsignal($message)
        {
        logging("Sende Alarm an Signal App:".$message."\n");
        shell_exec("/etc/signal/bin/signal-cli -u +4123123123 send -a /etc/infinipoll10k_A/alarm.png -m $message +41231231234");
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
