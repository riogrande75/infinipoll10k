#!/usr/bin/php
<?php
//Allg. Einstellungen
$moxa_ip = "192.168.x.y"; //MoxaBox_TCP_Server
$moxa_port = 12345; //Infini 10k Keller
$moxa_timeout = 10;
$debug = 1;

// Get model,version and protocolID for infini_startup.php
$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
//echo "Fehler $errno beim Verbindungsaufbau, $errstr \n";
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

fwrite($fp, "^P003GS".chr(0x0d));
$antw=parse_antw();
If(sizeof($antw) == 25)
{
        $battvolt = $antw[4]/10;
        $battcap = $antw[5];
}
        else logging("WARNING: 003GS falsche Antwort.");
        if($debug)
        {
        echo "BattVoltage: ".$battvolt."V\n";
        echo "BattCap: ".$battcap."%\n";
}
while(true)
        {
        //Main
        echo "Batteriespannung erhöhen (+) oder verkleinern (-)";
        $handle = fopen ("php://stdin","r");
        $line = fgets($handle);

        if(trim($line) == '+'){
                $voltchange = "PLUS";
        } elseif (trim($line) == '-')
                {
                $voltchange = "MINUS";
        }
        else {
                echo "Falsche Eingabe!\n\n";
                continue;
        }
        sleep(1);
        if($voltchange=="PLUS"){
                fwrite($fp, "BTVA+01".chr(0x0d)); //BTVA+01 Spannung im WR plus 0,1 Volt
                $antw=parse_antw();
                $antwort = substr($antw[0],0,4);
                if($debug) echo " BTVA+01 Antwort: $antwort \n";
                if( $antwort == "(ACK" )
                        {
                        echo "Spannung um 25mV erhöht!\n";
                } else {
                        echo "Spannung erhöhen fehlgeschlagen!\n";
                }
        }
        if($voltchange=="MINUS"){
                fwrite($fp, "BTVA-01".chr(0x0d)); //BTVA-01 Spannung im WR minus0,1 Volt
                $antw=parse_antw();
                $antwort = substr($antw[0],0,4);
                if($debug) echo " BTVA-01 Antwort: $antwort \n";
                if( $antwort =="(ACK")
                {
                        echo "Spannung um 25mV verkleinert!\n";
                } else {
                        echo "Spannung verkleinern fehlgeschlagen!\n";
                }
        }
        sleep(1);

        fwrite($fp, "^P003GS".chr(0x0d));
        $antw=parse_antw();
        If(sizeof($antw) == 25)
        {
        $battvolt = $antw[4]/10;
        $battcap = $antw[5];
        }
        echo "BATTERIESPANNUNG:".$battvolt." VOLT\n\n";
}

// Div. Funtionen zur Datenaufbereitung
function hex2str($hex) {
    $str = '';
    for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
    return $str;
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
        if(substr($byte,0,4) == "(ACK")
        {
                $byte_ok = $byte;
        }
        else{
                $byte_ok = substr($byte,0,-3);
        }
        $antw_werte = explode(",",$byte_ok);
        return($antw_werte);
}
?>
